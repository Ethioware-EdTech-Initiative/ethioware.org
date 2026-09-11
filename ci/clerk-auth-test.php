<?php
/**
 * Tests for chatbot/admin/clerk.php — the hand-rolled RS256/JWKS verification
 * behind the admin dashboard's Clerk sign-in.
 *
 * There is no Composer on this host, so token verification is done with
 * ext-openssl against a DER blob we assemble ourselves. That is exactly the
 * kind of code that must not ship unchecked, hence this file: it generates a
 * throwaway RSA keypair, seeds the JWKS cache from it (so the test never
 * touches the network), and then asserts that good tokens pass and every
 * tampered / expired / wrong-key / wrong-issuer variant is refused.
 *
 * Run:  php ci/clerk-auth-test.php
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__);

// clerk.php's only dependency is the DB bootstrap, which needs the server's
// secrets files. Nothing under test uses it, so it is stripped here.
$source = file_get_contents($repoRoot . '/chatbot/admin/clerk.php');
if ($source === false) {
    fwrite(STDERR, "cannot read chatbot/admin/clerk.php\n");
    exit(1);
}
eval('?>' . str_replace("require_once __DIR__ . '/../api/lib.php';", '', $source));

const TEST_FRONTEND_API = 'clerk-test.clerk.accounts.dev';
const TEST_KID = 'test-kid-1';

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ok   $label\n";
        return;
    }
    $failed++;
    echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

function b64u(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** Sets the CLERK_* settings for the next assertions (config.php uses define()). */
function set_env(array $values): void {
    foreach ($values as $key => $value) {
        putenv($key . '=' . $value);
    }
}

set_env(['CLERK_PUBLISHABLE_KEY' => 'pk_test_' . b64u(TEST_FRONTEND_API . '$')]);
$_SERVER['HTTP_HOST'] = 'ethioware.org';
$_SERVER['HTTPS'] = 'on';

/* -- Configuration derived from the publishable key ---------------------- */

echo "configuration\n";
check('frontend API decodes from the publishable key',
    chatbot_clerk_frontend_api() === TEST_FRONTEND_API, var_export(chatbot_clerk_frontend_api(), true));
check('clerk reports enabled', chatbot_clerk_enabled() === true);
check('issuer derived from frontend API',
    chatbot_clerk_issuer() === 'https://' . TEST_FRONTEND_API);
check('jwks url derived',
    chatbot_clerk_jwks_url() === 'https://' . TEST_FRONTEND_API . '/.well-known/jwks.json');
check('clerk-js served from the instance, not a third-party CDN',
    chatbot_clerk_script_url() === 'https://' . TEST_FRONTEND_API . '/npm/@clerk/clerk-js@5/dist/clerk.browser.js');
check('current origin honours forwarded https',
    chatbot_clerk_current_origin() === 'https://ethioware.org');

// A key that does not decode must leave the whole feature off, so the site
// falls back to the shared password instead of half-enabling sign-in.
foreach ([
    'blank' => '',
    'untouched placeholder' => 'REPLACE_ME',
    'not base64' => 'pk_test_!!!!',
    'wrong prefix' => 'sk_test_' . b64u(TEST_FRONTEND_API . '$'),
    'decodes to a non-host' => 'pk_test_' . b64u('not a hostname$'),
] as $label => $badKey) {
    set_env(['CLERK_PUBLISHABLE_KEY' => $badKey]);
    $error = null;
    check("$label publishable key leaves clerk disabled",
        chatbot_clerk_enabled() === false
        && chatbot_clerk_issuer() === null
        && chatbot_clerk_verify_session_token('a.b.c', $error) === null);
}
set_env(['CLERK_PUBLISHABLE_KEY' => 'pk_test_' . b64u(TEST_FRONTEND_API . '$')]);

/* -- JWK -> PEM ---------------------------------------------------------- */

$privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($privateKey === false) {
    fwrite(STDERR, "openssl_pkey_new() failed — is ext-openssl available?\n");
    exit(1);
}
$keyDetails = openssl_pkey_get_details($privateKey);
$jwk = [
    'kty' => 'RSA',
    'kid' => TEST_KID,
    'use' => 'sig',
    'alg' => 'RS256',
    'n' => b64u($keyDetails['rsa']['n']),
    'e' => b64u($keyDetails['rsa']['e']),
];

// Seed the on-disk JWKS cache so verification never reaches the network.
file_put_contents(
    chatbot_clerk_jwks_cache_path(),
    json_encode(['fetched_at' => time(), 'keys' => [$jwk]])
);

echo "jwk to pem\n";
$pem = chatbot_clerk_jwk_to_pem($jwk);
check('produces a PEM public key',
    is_string($pem) && strpos($pem, '-----BEGIN PUBLIC KEY-----') === 0);
$reparsed = is_string($pem) ? openssl_pkey_get_public($pem) : false;
check('openssl accepts the assembled DER', $reparsed !== false);
check('round-trips to the original public key',
    $reparsed !== false && openssl_pkey_get_details($reparsed)['key'] === $keyDetails['key']);
check('non-RSA key type refused',
    chatbot_clerk_jwk_to_pem(['kty' => 'EC', 'kid' => 'x', 'n' => 'a', 'e' => 'b']) === null);
check('incomplete jwk refused',
    chatbot_clerk_jwk_to_pem(['kty' => 'RSA', 'kid' => 'x']) === null);

/* -- Token verification -------------------------------------------------- */

/**
 * Mints a token. $tamper re-encodes the payload *after* signing, i.e. the
 * classic "edit the claims, keep the signature" attack.
 */
function mint(array $claimOverrides = [], array $headerOverrides = [], $signWith = null, bool $tamper = false): string {
    global $privateKey;

    $header = array_merge(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => TEST_KID], $headerOverrides);
    $claims = array_merge([
        'iss' => 'https://' . TEST_FRONTEND_API,
        'sub' => 'user_123',
        'sid' => 'sess_123',
        'azp' => 'https://ethioware.org',
        'iat' => time(),
        'nbf' => time() - 5,
        'exp' => time() + 60,
    ], $claimOverrides);
    foreach ($claims as $name => $value) {
        if ($value === null) unset($claims[$name]);
    }

    $signingInput = b64u(json_encode($header)) . '.' . b64u(json_encode($claims));
    openssl_sign($signingInput, $signature, $signWith ?? $privateKey, OPENSSL_ALGO_SHA256);

    if ($tamper) {
        $claims['sub'] = 'user_attacker';
        $signingInput = b64u(json_encode($header)) . '.' . b64u(json_encode($claims));
    }
    return $signingInput . '.' . b64u($signature);
}

echo "token verification\n";
$error = null;
$claims = chatbot_clerk_verify_session_token(mint(), $error);
check('a valid token is accepted',
    is_array($claims) && $claims['sub'] === 'user_123', (string) $error);

$otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

$rejections = [
    ['payload tampered after signing', mint([], [], null, true), 'bad signature'],
    ['signed by a foreign key', mint([], [], $otherKey), 'bad signature'],
    ['expired', mint(['exp' => time() - 3600]), 'token expired'],
    ['not yet valid', mint(['nbf' => time() + 3600]), 'token not yet valid'],
    ['issued by another clerk instance', mint(['iss' => 'https://evil.example.com']), 'issuer mismatch'],
    ['minted for another origin', mint(['azp' => 'https://evil.example.com']), 'unauthorized party'],
    ['no subject', mint(['sub' => null]), 'missing subject'],
    ['alg: none', mint([], ['alg' => 'none']), 'unexpected alg'],
    ['alg: HS256 confusion', mint([], ['alg' => 'HS256']), 'unexpected alg'],
    ['kid no longer in the key set', mint([], ['kid' => 'rotated-away']), 'no signing key for kid'],
    ['no kid', mint([], ['kid' => null]), 'missing kid'],
    ['not a jwt', 'not.a.jwt.at.all', 'malformed token'],
    ['empty', '', 'malformed token'],
];
foreach ($rejections as [$label, $token, $expectedError]) {
    $error = null;
    $result = chatbot_clerk_verify_session_token($token, $error);
    check("rejected: $label",
        $result === null && $error === $expectedError, 'got ' . var_export($error, true));
}

$error = null;
check('a token with no azp is accepted (claim is optional)',
    is_array(chatbot_clerk_verify_session_token(mint(['azp' => null]), $error)), (string) $error);

set_env(['CLERK_AUTHORIZED_PARTIES' => 'https://www.ethioware.org']);
$error = null;
check('an extra configured authorized party is accepted',
    is_array(chatbot_clerk_verify_session_token(mint(['azp' => 'https://www.ethioware.org']), $error)), (string) $error);
set_env(['CLERK_AUTHORIZED_PARTIES' => '']);

// An explicit issuer override must win over the one derived from the key.
set_env(['CLERK_JWT_ISSUER' => 'https://clerk.ethioware.org']);
$error = null;
check('issuer override is enforced',
    chatbot_clerk_verify_session_token(mint(), $error) === null && $error === 'issuer mismatch',
    'got ' . var_export($error, true));
$error = null;
check('token matching the overridden issuer is accepted',
    is_array(chatbot_clerk_verify_session_token(mint(['iss' => 'https://clerk.ethioware.org']), $error)), (string) $error);
set_env(['CLERK_JWT_ISSUER' => '']);

/* -- Identity ------------------------------------------------------------ */

echo "identity\n";
$identity = chatbot_clerk_identity(['sub' => 'user_123', 'email' => '  Ada@Ethioware.ORG ', 'name' => 'Ada L']);
check('subject carried through', $identity['id'] === 'user_123');
check('e-mail claim normalised to lower case', $identity['email'] === 'ada@ethioware.org');
check('name claim kept', $identity['name'] === 'Ada L');

// With no e-mail claim and no secret key there is nothing to look up, so the
// identity stays e-mail-less and the allowlist below must refuse it.
set_env(['CLERK_SECRET_KEY' => '']);
$identity = chatbot_clerk_identity(['sub' => 'user_123']);
check('no e-mail claim and no secret key yields no e-mail', $identity['email'] === '');

/* -- Allowlist ----------------------------------------------------------- */

echo "allowlist\n";
$reason = null;

set_env(['CLERK_ALLOWED_EMAILS' => '', 'CLERK_ALLOWED_DOMAINS' => '']);
check('fails closed when no allowlist is configured',
    chatbot_clerk_access_allowed(['email' => 'ada@ethioware.org'], $reason) === false
    && $reason === 'no allowlist configured');

set_env(['CLERK_ALLOWED_DOMAINS' => 'ethioware.org']);
check('allowed domain admits', chatbot_clerk_access_allowed(['email' => 'ada@ethioware.org'], $reason) === true);
check('other domain rejected', chatbot_clerk_access_allowed(['email' => 'ada@gmail.com'], $reason) === false);
check('lookalike domain rejected', chatbot_clerk_access_allowed(['email' => 'eve@notethioware.org'], $reason) === false);
check('subdomain not treated as the domain', chatbot_clerk_access_allowed(['email' => 'eve@mail.ethioware.org'], $reason) === false);
check('account with no e-mail rejected',
    chatbot_clerk_access_allowed(['email' => ''], $reason) === false && $reason === 'no e-mail on account');

set_env(['CLERK_ALLOWED_DOMAINS' => '', 'CLERK_ALLOWED_EMAILS' => 'Sam@Ethioware.org, ada@partner.example']);
check('listed e-mail admitted regardless of case',
    chatbot_clerk_access_allowed(['email' => 'sam@ethioware.org'], $reason) === true);
check('second listed e-mail admitted',
    chatbot_clerk_access_allowed(['email' => 'ada@partner.example'], $reason) === true);
check('unlisted e-mail on an allowed-looking domain rejected',
    chatbot_clerk_access_allowed(['email' => 'eve@ethioware.org'], $reason) === false
    && $reason === 'not on allowlist');

@unlink(chatbot_clerk_jwks_cache_path());

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
