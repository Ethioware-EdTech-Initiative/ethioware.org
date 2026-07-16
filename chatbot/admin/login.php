<?php
require_once __DIR__ . '/auth.php';

chatbot_admin_session_start();

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $conn = chatbot_db();
    $ip = chatbot_client_ip() ?? 'unknown';

    if (chatbot_admin_recent_failures($conn, $ip) >= CHATBOT_ADMIN_MAX_ATTEMPTS) {
        $error = 'Too many attempts. Please try again in a few minutes.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        if ($password !== '' && password_verify($password, ADMIN_PASSWORD_HASH)) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_last_active'] = time();
            session_regenerate_id(true);
            chatbot_log_event($conn, 'admin_login_ok', ['ip_address' => $ip]);
            header('Location: index.php');
            exit;
        }
        chatbot_log_event($conn, 'admin_login_fail', ['ip_address' => $ip]);
        $error = 'Incorrect password.';
    }
}
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
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Poppins,sans-serif;background:hsl(215,24%,94%);display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
  .card{background:var(--body-color);border-radius:12px;padding:2.5rem;width:100%;max-width:360px;box-shadow:0 10px 30px rgba(0,0,0,.08);}
  h1{font-size:1.15rem;color:var(--title-color);margin:0 0 1.25rem;}
  input[type=password]{width:100%;padding:.65rem .75rem;border:1px solid #d6dbe3;border-radius:8px;font-size:1rem;margin-bottom:1rem;}
  button{width:100%;padding:.7rem;border:0;border-radius:8px;background:var(--first-color);color:#fff;font-weight:600;font-size:1rem;cursor:pointer;}
  .error{color:#b3261e;font-size:.875rem;margin-bottom:1rem;}
</style>
</head>
<body>
  <form class="card" method="POST">
    <h1>Ethioware Chatbot — Admin</h1>
    <?php if ($error): ?><p class="error"><?= chatbot_admin_html_escape($error) ?></p><?php endif; ?>
    <input type="password" name="password" placeholder="Password" autofocus required>
    <button type="submit">Log in</button>
  </form>
</body>
</html>
