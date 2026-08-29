<?php
/**
 * Exchanges a Clerk session JWT for an admin PHP session.
 *
 * login.php's clerk-js widget signs the user in, mints a short-lived session
 * token and POSTs it here. This endpoint is the only place a Clerk token is
 * trusted; everything downstream reads the ordinary PHP session, so the rest
 * of the dashboard is unchanged.
 *
 * Failures are deliberately vague to the browser and specific in
 * chatbot_events, and they count toward the same 5-per-15-minutes-per-IP
 * budget as failed password attempts.
 */

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** @return never */
function chatbot_clerk_callback_fail(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    chatbot_clerk_callback_fail(405, 'Method not allowed.');
}

// Same-origin guard. Browsers always send Origin on a cross-origin POST, so a
// missing or mismatched value means this was not our own login page.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (rtrim(strtolower($origin), '/') !== strtolower(chatbot_clerk_current_origin())) {
    chatbot_clerk_callback_fail(403, 'Invalid request origin.');
}

if (!chatbot_clerk_enabled()) {
    chatbot_clerk_callback_fail(503, 'Clerk sign-in is not configured on this server.');
}

// Accept JSON (what login.php sends) or a plain form post.
$token = '';
$raw = file_get_contents('php://input');
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded) && isset($decoded['token']) && is_string($decoded['token'])) {
        $token = trim($decoded['token']);
    }
}
if ($token === '' && isset($_POST['token']) && is_string($_POST['token'])) {
    $token = trim($_POST['token']);
}
if ($token === '') {
    chatbot_clerk_callback_fail(400, 'Missing session token.');
}

$conn = chatbot_db();
$ip = chatbot_client_ip() ?? 'unknown';

if (chatbot_admin_recent_failures($conn, $ip) >= CHATBOT_ADMIN_MAX_ATTEMPTS) {
    chatbot_clerk_callback_fail(429, 'Too many attempts. Please try again in a few minutes.');
}

$verifyError = null;
$claims = chatbot_clerk_verify_session_token($token, $verifyError);
if ($claims === null) {
    chatbot_log_event($conn, 'admin_login_fail', [
        'ip_address' => $ip,
        'detail' => 'clerk: ' . ($verifyError ?? 'verification failed'),
    ]);
    chatbot_clerk_callback_fail(401, 'Could not verify that sign-in. Please try again.');
}

$identity = chatbot_clerk_identity($claims);

$denyReason = null;
if (!chatbot_clerk_access_allowed($identity, $denyReason)) {
    chatbot_log_event($conn, 'admin_login_fail', [
        'ip_address' => $ip,
        'detail' => 'clerk denied (' . $denyReason . '): '
            . ($identity['email'] !== '' ? $identity['email'] : $identity['id']),
    ]);
    chatbot_clerk_callback_fail(403, 'This account does not have access to the chatbot dashboard.');
}

$label = $identity['email'];
if ($identity['name'] !== '') {
    $label = $identity['name'] . ' <' . $identity['email'] . '>';
}

chatbot_admin_establish_session('clerk', $label, $identity['id']);
chatbot_log_event($conn, 'admin_login_ok', [
    'ip_address' => $ip,
    'detail' => 'clerk: ' . $identity['email'],
]);

echo json_encode(['success' => true, 'redirect' => 'index.php']);
