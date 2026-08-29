<?php
/**
 * Admin session helpers: PHP-session login shared by index.php, login.php,
 * view.php, export.php.
 *
 * Two ways in, both landing on the same PHP session:
 *   1. Clerk (preferred) — per-user staff identities, verified in clerk.php
 *      and exchanged for a session by clerk-callback.php. See CHATBOT_SPEC
 *      §12.4, which this closes.
 *   2. The original single shared password (ADMIN_PASSWORD_HASH, §8), kept as
 *      a break-glass fallback so a misconfigured key or a Clerk outage cannot
 *      lock staff out of production. Disable it with
 *      CHATBOT_ADMIN_PASSWORD_FALLBACK once Clerk is verified live.
 */

require_once __DIR__ . '/../api/lib.php';
require_once __DIR__ . '/clerk.php';

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

/**
 * Marks the session authenticated. $method is 'clerk' or 'password';
 * $user/$subject identify the person for the header and the audit log
 * (the shared-password path has neither).
 */
function chatbot_admin_establish_session(string $method, string $user = '', string $subject = ''): void {
    chatbot_admin_session_start();
    session_regenerate_id(true); // new id before trusting the session
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_last_active'] = time();
    $_SESSION['admin_auth_method'] = $method;
    $_SESSION['admin_user'] = $user;
    $_SESSION['admin_subject'] = $subject;
}

/** Clears the session. Callers redirect afterwards. */
function chatbot_admin_end_session(): void {
    chatbot_admin_session_start();
    session_unset();
    session_destroy();
}

/** Display label for the signed-in user, or null under the shared password. */
function chatbot_admin_current_user(): ?string {
    $user = trim((string) ($_SESSION['admin_user'] ?? ''));
    return $user === '' ? null : $user;
}

function chatbot_admin_auth_method(): string {
    return (string) ($_SESSION['admin_auth_method'] ?? 'password');
}

/**
 * Whether the shared-password form should still be offered. It needs a hash to
 * check against, and once Clerk works the team can retire it by defining
 * CHATBOT_ADMIN_PASSWORD_FALLBACK as false.
 */
function chatbot_admin_password_fallback_enabled(): bool {
    if (!defined('ADMIN_PASSWORD_HASH')) return false;
    $hash = trim((string) ADMIN_PASSWORD_HASH);
    if ($hash === '' || strpos($hash, 'REPLACE_ME') !== false) return false;
    if (!chatbot_clerk_enabled()) return true; // Clerk off → password is the only way in
    return !defined('CHATBOT_ADMIN_PASSWORD_FALLBACK') || (bool) CHATBOT_ADMIN_PASSWORD_FALLBACK;
}

/** Failed attempts from this IP in the trailing window. */
function chatbot_admin_recent_failures(mysqli $conn, string $ip): int {
    $window = CHATBOT_ADMIN_ATTEMPT_WINDOW_MIN;
    $stmt = chatbot_prepare($conn, 
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
