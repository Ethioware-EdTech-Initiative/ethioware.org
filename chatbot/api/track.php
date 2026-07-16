<?php
/**
 * Beacon receiver for apply.html referral events (navigator.sendBeacon).
 *
 * Hardening per CHATBOT_SPEC.md §7.2.6: POST only, same-origin Origin/Referer
 * check, token must already exist in chatbot_leads, capped events per token.
 * It writes events and flips status='apply_started' — it can NEVER create a
 * lead row (that only happens in chat.php).
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib.php';

const CHATBOT_TRACK_EVENT_CAP_PER_TOKEN = 40;

function chatbot_track_fail(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chatbot_track_fail(405, 'Invalid request method.');
}

// Same-origin check: sendBeacon requests carry Origin or Referer for a
// same-origin POST in every modern browser; reject anything that names a
// different host. Requests with neither header (e.g. direct API testing)
// are allowed through to keep the deploy smoke test simple, and because the
// token/lead existence check below is the real access control.
$host = $_SERVER['HTTP_HOST'] ?? '';
$origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? null;
if ($origin) {
    $originHost = parse_url($origin, PHP_URL_HOST);
    if ($originHost && strcasecmp($originHost, (string) parse_url('http://' . $host, PHP_URL_HOST)) !== 0) {
        chatbot_track_fail(403, 'Origin mismatch.');
    }
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    chatbot_track_fail(400, 'Invalid request body.');
}

$token = $input['token'] ?? null;
$event = $input['event'] ?? null;
$detail = isset($input['detail']) ? mb_substr((string) $input['detail'], 0, 255) : null;
$page = isset($input['page']) ? mb_substr((string) $input['page'], 0, 255) : null;

if (!chatbot_is_valid_uuid($token)) {
    chatbot_track_fail(400, 'Invalid token.');
}
if (!in_array($event, ['apply_started', 'apply_step'], true)) {
    chatbot_track_fail(400, 'Invalid event.');
}

$conn = chatbot_db();

// The token must already exist as a referral_token on a lead row — track.php
// never creates leads.
$stmt = chatbot_prepare($conn, 'SELECT id, status FROM chatbot_leads WHERE referral_token = ? LIMIT 1');
$stmt->bind_param('s', $token);
$stmt->execute();
$lead = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$lead) {
    chatbot_track_fail(404, 'Unknown referral token.');
}

$stmt = chatbot_prepare($conn, 'SELECT COUNT(*) AS c FROM chatbot_events WHERE referral_token = ?');
$stmt->bind_param('s', $token);
$stmt->execute();
$count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();
if ($count >= CHATBOT_TRACK_EVENT_CAP_PER_TOKEN) {
    chatbot_track_fail(429, 'Event cap reached for this token.');
}

chatbot_log_event($conn, $event, ['referral_token' => $token, 'ip_address' => chatbot_client_ip(), 'detail' => $detail, 'page' => $page]);

if ($event === 'apply_started' && !in_array($lead['status'], ['apply_started', 'apply_completed'], true)) {
    $stmt = chatbot_prepare($conn, "UPDATE chatbot_leads SET status = 'apply_started' WHERE referral_token = ?");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->close();
}

echo json_encode(['success' => true]);
