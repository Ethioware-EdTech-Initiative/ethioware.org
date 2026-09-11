<?php
/**
 * Admin sign-in.
 *
 * With Clerk configured this page mounts Clerk's sign-in widget and hands the
 * resulting session token to clerk-callback.php. The original shared-password
 * form stays available underneath as a break-glass fallback until the team
 * turns it off (CHATBOT_ADMIN_PASSWORD_FALLBACK), and is the only form shown
 * when Clerk is not configured at all — so this page behaves exactly as before
 * on a server with no CLERK_* values set.
 */

require_once __DIR__ . '/auth.php';

chatbot_admin_session_start();

$clerkEnabled = chatbot_clerk_enabled();
$passwordEnabled = chatbot_admin_password_fallback_enabled();
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$passwordEnabled) {
        $error = 'Password sign-in is disabled. Use your Ethioware account.';
    } else {
        $conn = chatbot_db();
        $ip = chatbot_client_ip() ?? 'unknown';

        if (chatbot_admin_recent_failures($conn, $ip) >= CHATBOT_ADMIN_MAX_ATTEMPTS) {
            $error = 'Too many attempts. Please try again in a few minutes.';
        } else {
            $password = (string) ($_POST['password'] ?? '');
            if ($password !== '' && password_verify($password, ADMIN_PASSWORD_HASH)) {
                chatbot_admin_establish_session('password');
                chatbot_log_event($conn, 'admin_login_ok', [
                    'ip_address' => $ip,
                    'detail' => 'shared password',
                ]);
                header('Location: index.php');
                exit;
            }
            chatbot_log_event($conn, 'admin_login_fail', [
                'ip_address' => $ip,
                'detail' => 'shared password',
            ]);
            $error = 'Incorrect password.';
        }
    }
}

$jsFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$clerkConfigJson = json_encode([
    'publishableKey' => chatbot_clerk_setting('CLERK_PUBLISHABLE_KEY'),
    'scriptUrl' => chatbot_clerk_script_url(),
    'template' => chatbot_clerk_jwt_template(),
    'signOut' => isset($_GET['signout']),
], $jsFlags);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Chatbot Admin — Login</title>
<style>
  :root{ --first-color:hsl(215,80%,32%); --body-color:#fff; --title-color:hsl(215,4%,15%); --text-color:hsl(215,4%,35%); }
  *{box-sizing:border-box;}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Poppins,sans-serif;background:hsl(215,24%,94%);display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1.5rem;}
  .card{background:var(--body-color);border-radius:12px;padding:2.5rem;width:100%;max-width:400px;box-shadow:0 10px 30px rgba(0,0,0,.08);}
  h1{font-size:1.15rem;color:var(--title-color);margin:0 0 1.25rem;}
  input[type=password]{width:100%;padding:.65rem .75rem;border:1px solid #d6dbe3;border-radius:8px;font-size:1rem;margin-bottom:1rem;}
  button{width:100%;padding:.7rem;border:0;border-radius:8px;background:var(--first-color);color:#fff;font-weight:600;font-size:1rem;cursor:pointer;}
  .error{color:#b3261e;font-size:.875rem;margin-bottom:1rem;}
  .hint{color:var(--text-color);font-size:.8rem;margin:0 0 1rem;}
  #clerk-signin{display:flex;justify-content:center;min-height:1rem;}
  #clerk-status{color:var(--text-color);font-size:.85rem;text-align:center;margin:0;}
  details{margin-top:1.5rem;border-top:1px solid #e2e6ec;padding-top:1rem;}
  summary{font-size:.8rem;color:var(--text-color);cursor:pointer;margin-bottom:1rem;}
  form.password-form{margin:0;}
</style>
</head>
<body>
  <div class="card">
    <h1>Ethioware Chatbot — Admin</h1>
    <?php if ($error): ?><p class="error"><?= chatbot_admin_html_escape($error) ?></p><?php endif; ?>

    <?php if ($clerkEnabled): ?>
      <p class="error" id="clerk-error" hidden></p>
      <p id="clerk-status">Loading sign-in…</p>
      <div id="clerk-signin"></div>
    <?php endif; ?>

    <?php if ($passwordEnabled): ?>
      <?php if ($clerkEnabled): ?>
      <details<?= $error ? ' open' : '' ?>>
        <summary>Sign in with the shared password instead</summary>
        <p class="hint">Fallback access — prefer your own account so the audit log shows who viewed leads.</p>
      <?php endif; ?>
        <form class="password-form" method="POST">
          <input type="password" name="password" placeholder="Password" <?= $clerkEnabled ? '' : 'autofocus' ?> required>
          <button type="submit">Log in</button>
        </form>
      <?php if ($clerkEnabled): ?>
      </details>
      <?php endif; ?>
    <?php elseif (!$clerkEnabled): ?>
      <p class="error">Admin sign-in is not configured on this server. Set CLERK_PUBLISHABLE_KEY or ADMIN_PASSWORD_HASH in chatbot/config.php.</p>
    <?php endif; ?>
  </div>

<?php if ($clerkEnabled): ?>
<script>
(function () {
  var config = <?= $clerkConfigJson ?>;
  var statusEl = document.getElementById('clerk-status');
  var errorEl = document.getElementById('clerk-error');
  var mountEl = document.getElementById('clerk-signin');
  var exchanging = false;

  function setStatus(text) {
    statusEl.textContent = text || '';
    statusEl.hidden = !text;
  }

  function showError(message) {
    errorEl.textContent = message;
    errorEl.hidden = false;
  }

  function mountSignIn() {
    setStatus('');
    mountEl.innerHTML = '';
    window.Clerk.mountSignIn(mountEl);
  }

  // Trade the (60-second) Clerk session token for a normal PHP admin session.
  async function exchange() {
    if (exchanging) return;
    exchanging = true;
    setStatus('Signing you in…');
    try {
      var token = await window.Clerk.session.getToken(
        config.template ? { template: config.template } : undefined
      );
      var response = await fetch('clerk-callback.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ token: token })
      });
      var data = await response.json();
      if (data && data.success) {
        window.location.replace(data.redirect || 'index.php');
        return;
      }
      showError((data && data.message) || 'Sign-in failed.');
    } catch (err) {
      showError('Could not reach the server. Please try again.');
    }
    exchanging = false;
    // Signing out clears the Clerk session that this server rejected, so the
    // widget comes back instead of looping on the same denied account.
    try { await window.Clerk.signOut(); } catch (err) { /* already signed out */ }
    mountSignIn();
  }

  async function start() {
    try {
      await window.Clerk.load();
    } catch (err) {
      setStatus('');
      showError('Sign-in service unavailable.');
      return;
    }

    if (config.signOut) {
      try { await window.Clerk.signOut(); } catch (err) { /* nothing to clear */ }
      history.replaceState(null, '', 'login.php');
    }

    if (window.Clerk.user) {
      exchange();
    } else {
      mountSignIn();
      window.Clerk.addListener(function (payload) {
        if (payload.user && !exchanging) exchange();
      });
    }
  }

  var script = document.createElement('script');
  script.async = true;
  script.crossOrigin = 'anonymous';
  script.src = config.scriptUrl;
  script.setAttribute('data-clerk-publishable-key', config.publishableKey);
  script.addEventListener('load', start);
  script.addEventListener('error', function () {
    setStatus('');
    showError('Could not load the sign-in widget.');
  });
  document.head.appendChild(script);
})();
</script>
<?php endif; ?>
</body>
</html>
