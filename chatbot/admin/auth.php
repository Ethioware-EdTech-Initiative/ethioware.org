<?php
/**
 * Admin session helpers: PHP-session login shared by index.php, login.php,
 * view.php, export.php. Single shared password (CHATBOT_SPEC.md §8) — a
 * deliberate simplicity trade for a small non-technical team; per-user
 * accounts are an open decision (§12.4), not built here.
 */

require_once __DIR__ . '/../api/lib.php';

const CHATBOT_ADMIN_IDLE_TIMEOUT = 8 * 3600; // 8 hours
const CHATBOT_ADMIN_MAX_ATTEMPTS = 5;
const CHATBOT_ADMIN_ATTEMPT_WINDOW_MIN = 15;

function chatbot_admin_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/chatbot/admin/',
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
    ]);
    session_name('chatbot_admin_session');
    session_start();
}

/** Redirects to login.php and exits if not authenticated or idle-timed-out. */
function chatbot_admin_require_login(): void {
    chatbot_admin_session_start();
    $loggedIn = !empty($_SESSION['admin_logged_in']);
    $lastActive = (int) ($_SESSION['admin_last_active'] ?? 0);
    if (!$loggedIn || (time() - $lastActive) > CHATBOT_ADMIN_IDLE_TIMEOUT) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
    $_SESSION['admin_last_active'] = time();
}

/** Failed attempts from this IP in the trailing window. */
function chatbot_admin_recent_failures(mysqli $conn, string $ip): int {
    $window = CHATBOT_ADMIN_ATTEMPT_WINDOW_MIN;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM chatbot_events
         WHERE event = 'admin_login_fail' AND ip_address = ?
           AND created_at >= (NOW() - INTERVAL ? MINUTE)"
    );
    $stmt->bind_param('si', $ip, $window);
    $stmt->execute();
    $c = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c;
}

function chatbot_admin_html_escape(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
