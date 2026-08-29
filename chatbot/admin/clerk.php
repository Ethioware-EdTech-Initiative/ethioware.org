<?php
/**
 * Clerk authentication for the chatbot admin dashboard.
 *
 * Resolves CHATBOT_SPEC.md §12.4 (per-user dashboard accounts): staff sign in
 * with their own Clerk identity instead of the one shared password, so
 * chatbot_events rows record *who* logged in and access is revoked in Clerk
 * rather than by rotating a secret everyone knows.
 *
 * Deliberately dependency-free — this host is cPanel shared hosting with no
 * Composer and no vendor/ directory, so the RS256 verification below is done
 * with ext-openssl against Clerk's public JWKS. Nothing here needs the Clerk
 * secret key for the security decision itself; CLERK_SECRET_KEY is used only
 * to look up the signed-in user's e-mail when it is not a token claim.
 *
 * Flow (see login.php / clerk-callback.php):
 *   browser  Clerk.js sign-in  →  short-lived session JWT
 *   POST     clerk-callback.php  →  verify signature + claims here
 *   server   allowlist check     →  ordinary PHP session (auth.php)
 *
 * Every later request is authorised by the PHP session exactly as before, so
 * index.php / view.php / export.php are unchanged apart from cosmetics.
 */

require_once __DIR__ . '/../api/lib.php';

const CHATBOT_CLERK_JWKS_TTL         = 3600; // refresh cached signing keys hourly
const CHATBOT_CLERK_JWKS_MIN_REFRESH = 60;   // floor between forced kid-miss refetches
const CHATBOT_CLERK_LEEWAY           = 30;   // clock-skew slack, seconds
const CHATBOT_CLERK_HTTP_TIMEOUT     = 5;

/* -------------------------------------------------------------------------
 * Configuration
 *
 * Values come from chatbot/config.php (the gitignored, never-deployed secrets
 * file — see config.example.php). getenv() is accepted as a fallback so the
 * same code works if this ever moves to an env-var host.
 * ---------------------------------------------------------------------- */

function chatbot_clerk_setting(string $key): string {
    if (defined($key)) {
        $value = trim((string) constant($key));
        // Treat the untouched template placeholder as "not configured".
        if ($value !== '' && strpos($value, 'REPLACE_ME') === false) {
            return $value;
        }
    }
    $env = getenv($key);
    return is_string($env) ? trim($env) : '';
}

function chatbot_clerk_setting_list(string $key): array {
    $raw = chatbot_clerk_setting($key);
    if ($raw === '') return [];
    $items = preg_split('/[\s,;]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY);
    return $items ?: [];
}

/**
 * Clerk's Frontend API host, base64-encoded inside the publishable key
 * (`pk_test_<base64("host$")>`). It gives us the JWKS endpoint, the token
 * issuer and the clerk-js CDN origin from a single configured value.
 */
function chatbot_clerk_frontend_api(): ?string {
    $pk = chatbot_clerk_setting('CLERK_PUBLISHABLE_KEY');
    if (!preg_match('/^pk_(test|live)_([A-Za-z0-9_\-+\/=]+)$/', $pk, $m)) return null;

    $decoded = base64_decode(strtr($m[2], '-_', '+/'), true);
    if (!is_string($decoded)) return null;

    $host = rtrim(trim($decoded), '$');
    return preg_match('/^[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $host) ? $host : null;
}

/** True once a publishable key that actually decodes is present. */
function chatbot_clerk_enabled(): bool {
    return chatbot_clerk_frontend_api() !== null;
}

/** Expected `iss` claim. Equals https://<frontend-api> unless overridden. */
function chatbot_clerk_issuer(): ?string {
    $override = chatbot_clerk_setting('CLERK_JWT_ISSUER');
    if ($override !== '') return rtrim($override, '/');
    $api = chatbot_clerk_frontend_api();
    return $api === null ? null : 'https://' . $api;
}

/** clerk-js is served from the instance's own Frontend API — no third-party CDN. */
function chatbot_clerk_script_url(): ?string {
    $api = chatbot_clerk_frontend_api();
    return $api === null ? null : 'https://' . $api . '/npm/@clerk/clerk-js@5/dist/clerk.browser.js';
}

function chatbot_clerk_jwks_url(): ?string {
    $api = chatbot_clerk_frontend_api();
    return $api === null ? null : 'https://' . $api . '/.well-known/jwks.json';
}

/** Optional JWT template name, if the team adds an `email` claim that way. */
function chatbot_clerk_jwt_template(): string {
    return chatbot_clerk_setting('CLERK_JWT_TEMPLATE');
}

/** Scheme://host of the current request — the expected `azp` of a token. */
function chatbot_clerk_current_origin(): string {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return ($isHttps ? 'https://' : 'http://') . $host;
}

/* -------------------------------------------------------------------------
 * HTTP + JWKS cache
 * ---------------------------------------------------------------------- */

function chatbot_clerk_http_get(string $url, array $headers = []): ?array {
    if (!function_exists('curl_init')) {
        error_log('chatbot/clerk: ext-curl unavailable');
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CHATBOT_CLERK_HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => CHATBOT_CLERK_HTTP_TIMEOUT,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        error_log('chatbot/clerk: request to ' . $url . ' failed: ' . $err);
        return null;
    }
    return ['status' => $status, 'body' => (string) $body];
}

function chatbot_clerk_jwks_cache_path(): string {
    // Keyed on the endpoint the keys come from, not on the issuer: overriding
    // CLERK_JWT_ISSUER changes which tokens we accept, not which key set the
    // instance publishes, so it must not orphan the cache.
    $url = (string) chatbot_clerk_jwks_url();
    return rtrim(sys_get_temp_dir(), '/') . '/ethioware-clerk-jwks-' . md5($url) . '.json';
}

/**
 * Clerk's signing keys, cached on disk for an hour.
 *
 * On a network failure we knowingly keep serving the stale cache: Clerk
 * rotates keys rarely, and a transient outage locking staff out of the
 * dashboard is the worse failure. A cache miss with no file returns [].
 */
function chatbot_clerk_jwks(bool $forceRefresh = false): array {
    $path  = chatbot_clerk_jwks_cache_path();
    $now   = time();
    $cache = null;

    if (is_file($path)) {
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (is_array($decoded) && isset($decoded['keys']) && is_array($decoded['keys'])) {
            $cache = $decoded;
        }
    }

    $fetchedAt = (int) ($cache['fetched_at'] ?? 0);
    $isStale   = ($now - $fetchedAt) >= CHATBOT_CLERK_JWKS_TTL;
    // A forced refresh (unknown kid) is throttled so a bogus kid can't be used
    // to hammer Clerk's endpoint on every request.
    $mayForce  = $forceRefresh && ($now - $fetchedAt) >= CHATBOT_CLERK_JWKS_MIN_REFRESH;

    if ($cache !== null && !$isStale && !$mayForce) {
        return $cache['keys'];
    }

    $url = chatbot_clerk_jwks_url();
    if ($url === null) return $cache['keys'] ?? [];

    $res = chatbot_clerk_http_get($url);
    if ($res === null || $res['status'] !== 200) {
        if ($res !== null) {
            error_log('chatbot/clerk: JWKS fetch returned HTTP ' . $res['status']);
        }
        return $cache['keys'] ?? [];
    }

    $payload = json_decode($res['body'], true);
    if (!is_array($payload) || !isset($payload['keys']) || !is_array($payload['keys'])) {
        error_log('chatbot/clerk: JWKS response was not a key set');
        return $cache['keys'] ?? [];
    }

    $fresh = ['fetched_at' => $now, 'keys' => $payload['keys']];
    // Atomic replace so a concurrent reader never sees a half-written file.
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($fresh), LOCK_EX) !== false) {
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) @unlink($tmp);
    }
    return $payload['keys'];
}

/* -------------------------------------------------------------------------
 * JWT verification (RS256, no library)
 * ---------------------------------------------------------------------- */

function chatbot_clerk_b64url_decode(string $s): string {
    $s   = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad !== 0) $s .= str_repeat('=', 4 - $pad);
    $out = base64_decode($s, true);
    return $out === false ? '' : $out;
}

function chatbot_clerk_der_len(int $len): string {
    if ($len < 0x80) return chr($len);
    $bytes = '';
    while ($len > 0) {
        $bytes = chr($len & 0xFF) . $bytes;
        $len >>= 8;
    }
    return chr(0x80 | strlen($bytes)) . $bytes;
}

function chatbot_clerk_der_integer(string $bin): string {
    $bin = ltrim($bin, "\x00");
    if ($bin === '') $bin = "\x00";
    // DER INTEGERs are signed; a leading high bit would read as negative.
    if ((ord($bin[0]) & 0x80) !== 0) $bin = "\x00" . $bin;
    return "\x02" . chatbot_clerk_der_len(strlen($bin)) . $bin;
}

function chatbot_clerk_der_sequence(string $payload): string {
    return "\x30" . chatbot_clerk_der_len(strlen($payload)) . $payload;
}

/**
 * JWK (RSA n/e) → PEM SubjectPublicKeyInfo, so openssl_verify() can use it.
 * PHP has no API to build an RSA key from a raw modulus/exponent, so the DER
 * is assembled by hand.
 */
function chatbot_clerk_jwk_to_pem(array $jwk): ?string {
    if (($jwk['kty'] ?? '') !== 'RSA') return null;

    $n = chatbot_clerk_b64url_decode((string) ($jwk['n'] ?? ''));
    $e = chatbot_clerk_b64url_decode((string) ($jwk['e'] ?? ''));
    if ($n === '' || $e === '') return null;

    $rsaPublicKey = chatbot_clerk_der_sequence(
        chatbot_clerk_der_integer($n) . chatbot_clerk_der_integer($e)
    );
    // AlgorithmIdentifier: OID 1.2.840.113549.1.1.1 (rsaEncryption) + NULL
    $algorithm = chatbot_clerk_der_sequence(hex2bin('06092a864886f70d0101010500'));
    $bitString = "\x03" . chatbot_clerk_der_len(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;
    $spki      = chatbot_clerk_der_sequence($algorithm . $bitString);

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($spki), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

function chatbot_clerk_key_for_kid(string $kid, bool $forceRefresh = false): ?string {
    foreach (chatbot_clerk_jwks($forceRefresh) as $jwk) {
        if (is_array($jwk) && ($jwk['kid'] ?? null) === $kid) {
            return chatbot_clerk_jwk_to_pem($jwk);
        }
    }
    return null;
}

/**
 * Verifies a Clerk session token and returns its claims, or null on any
 * failure. $error receives a short reason suitable for the audit log — never
 * show it verbatim to the browser beyond the generic message login.php uses.
 */
function chatbot_clerk_verify_session_token(string $jwt, ?string &$error = null): ?array {
    $error = null;

    if (!function_exists('openssl_verify')) {
        $error = 'ext-openssl unavailable';
        return null;
    }
    $issuer = chatbot_clerk_issuer();
    if ($issuer === null) {
        $error = 'clerk not configured';
        return null;
    }

    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        $error = 'malformed token';
        return null;
    }
    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

    $header = json_decode(chatbot_clerk_b64url_decode($encodedHeader), true);
    if (!is_array($header)) {
        $error = 'malformed header';
        return null;
    }
    // Pin the algorithm: never let the token choose (alg=none / HS256 confusion).
    if (($header['alg'] ?? '') !== 'RS256') {
        $error = 'unexpected alg';
        return null;
    }
    $kid = (string) ($header['kid'] ?? '');
    if ($kid === '') {
        $error = 'missing kid';
        return null;
    }

    $pem = chatbot_clerk_key_for_kid($kid);
    if ($pem === null) {
        // Unknown kid usually means Clerk rotated keys — retry once, fresh.
        $pem = chatbot_clerk_key_for_kid($kid, true);
    }
    if ($pem === null) {
        $error = 'no signing key for kid';
        return null;
    }

    $signature = chatbot_clerk_b64url_decode($encodedSignature);
    $signed    = $encodedHeader . '.' . $encodedPayload;
    if (openssl_verify($signed, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
        $error = 'bad signature';
        return null;
    }

    $claims = json_decode(chatbot_clerk_b64url_decode($encodedPayload), true);
    if (!is_array($claims)) {
        $error = 'malformed claims';
        return null;
    }

    $now = time();
    if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $issuer) {
        $error = 'issuer mismatch';
        return null;
    }
    if (!isset($claims['exp']) || (int) $claims['exp'] + CHATBOT_CLERK_LEEWAY < $now) {
        $error = 'token expired';
        return null;
    }
    if (isset($claims['nbf']) && (int) $claims['nbf'] - CHATBOT_CLERK_LEEWAY > $now) {
        $error = 'token not yet valid';
        return null;
    }
    if (empty($claims['sub'])) {
        $error = 'missing subject';
        return null;
    }

    // azp is the origin the token was minted for — reject tokens issued to a
    // different site on the same Clerk instance.
    $azp = (string) ($claims['azp'] ?? '');
    if ($azp !== '') {
        $allowed = chatbot_clerk_setting_list('CLERK_AUTHORIZED_PARTIES');
        $allowed[] = strtolower(chatbot_clerk_current_origin());
        if (!in_array(strtolower(rtrim($azp, '/')), $allowed, true)) {
            $error = 'unauthorized party';
            return null;
        }
    }

    return $claims;
}

/* -------------------------------------------------------------------------
 * Identity + access control
 * ---------------------------------------------------------------------- */

/**
 * Resolves the signed-in user's e-mail and display name.
 *
 * Clerk's default session token carries no e-mail, so we take it from a
 * custom claim when the instance is configured to emit one, and otherwise ask
 * the Backend API once per login (the result is then held in the PHP session).
 */
function chatbot_clerk_identity(array $claims): array {
    $identity = [
        'id'    => (string) $claims['sub'],
        'email' => '',
        'name'  => '',
    ];

    foreach (['email', 'email_address', 'primary_email', 'primary_email_address'] as $claim) {
        if (!empty($claims[$claim]) && is_string($claims[$claim])) {
            $identity['email'] = strtolower(trim($claims[$claim]));
            break;
        }
    }
    foreach (['name', 'full_name', 'first_name'] as $claim) {
        if (!empty($claims[$claim]) && is_string($claims[$claim])) {
            $identity['name'] = trim($claims[$claim]);
            break;
        }
    }

    if ($identity['email'] === '') {
        $remote = chatbot_clerk_fetch_user($identity['id']);
        if ($remote !== null) {
            $identity['email'] = $remote['email'];
            if ($identity['name'] === '') $identity['name'] = $remote['name'];
        }
    }

    return $identity;
}

/** GET /v1/users/{id} on the Clerk Backend API. Needs CLERK_SECRET_KEY. */
function chatbot_clerk_fetch_user(string $userId): ?array {
    $secret = chatbot_clerk_setting('CLERK_SECRET_KEY');
    if ($secret === '') return null;

    $base = chatbot_clerk_setting('CLERK_API_URL');
    if ($base === '') $base = 'https://api.clerk.com';

    $res = chatbot_clerk_http_get(
        rtrim($base, '/') . '/v1/users/' . rawurlencode($userId),
        ['Authorization: Bearer ' . $secret]
    );
    if ($res === null || $res['status'] !== 200) {
        if ($res !== null) {
            error_log('chatbot/clerk: user lookup returned HTTP ' . $res['status']);
        }
        return null;
    }

    $user = json_decode($res['body'], true);
    if (!is_array($user)) return null;

    $email     = '';
    $primaryId = $user['primary_email_address_id'] ?? null;
    foreach ((array) ($user['email_addresses'] ?? []) as $address) {
        if (!is_array($address) || empty($address['email_address'])) continue;
        if ($primaryId !== null && ($address['id'] ?? null) === $primaryId) {
            $email = (string) $address['email_address'];
            break;
        }
        if ($email === '') $email = (string) $address['email_address'];
    }

    $name = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));

    return ['email' => strtolower(trim($email)), 'name' => $name];
}

/**
 * Allowlist gate. Fails closed on purpose: this dashboard exposes every lead's
 * contact details, so an unconfigured allowlist must not mean "any Clerk
 * account gets in".
 */
function chatbot_clerk_access_allowed(array $identity, ?string &$reason = null): bool {
    $reason = null;

    $emails  = chatbot_clerk_setting_list('CLERK_ALLOWED_EMAILS');
    $domains = chatbot_clerk_setting_list('CLERK_ALLOWED_DOMAINS');

    if (!$emails && !$domains) {
        $reason = 'no allowlist configured';
        return false;
    }

    $email = strtolower(trim($identity['email'] ?? ''));
    if ($email === '') {
        $reason = 'no e-mail on account';
        return false;
    }
    if (in_array($email, $emails, true)) return true;

    $at = strrpos($email, '@');
    if ($at !== false && in_array(substr($email, $at + 1), $domains, true)) return true;

    $reason = 'not on allowlist';
    return false;
}
