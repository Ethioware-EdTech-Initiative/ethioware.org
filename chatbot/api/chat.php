<?php
/**
 * Chatbot main endpoint.
 *
 * Stateless per request: rate limits -> load/create lead + conversation ->
 * (gate check / structural knowledge withholding) -> Gemini -> persist ->
 * respond. No persistent process; one HTTP request in, at most one Gemini
 * REST call out (two on a gate auto-pass, see below), one HTTP response out.
 * See CHATBOT_SPEC.md §4 and §7.
 *
 * Response contract (always JSON):
 *   { success, reply, action: 'none'|'refer_apply'|'request_gate'|'quota_fallback'|'capped',
 *     referral_url?, gate: {passed}, chips? }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/gemini.php';

const CHATBOT_SESSION_MESSAGE_CAP = 30;
const CHATBOT_IP_DAILY_CAP = 60;

function chatbot_fail(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chatbot_fail(405, 'Invalid request method.');
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    chatbot_fail(400, 'Invalid request body.');
}

$sessionId = $input['session_id'] ?? null;
if (!chatbot_is_valid_uuid($sessionId)) {
    chatbot_fail(400, 'Missing or invalid session_id.');
}

$type = $input['type'] ?? 'message';
if (!in_array($type, ['message', 'gate_submit', 'contact_submit'], true)) {
    chatbot_fail(400, 'Invalid request type.');
}

$ip = chatbot_client_ip();
$page = chatbot_clean($input['page'] ?? null);
if ($page !== null) $page = mb_substr($page, 0, 255);

$conn = chatbot_db();
$lead = chatbot_ensure_lead($conn, $sessionId, $page);
$conversation = chatbot_get_conversation($conn, $sessionId, $ip);

// ---------------------------------------------------------------------
// Gate form submission: contact captured -> unlock partnership knowledge
// and re-answer the question that triggered the gate.
// ---------------------------------------------------------------------
if ($type === 'gate_submit') {
    $name = chatbot_clean($input['name'] ?? null);
    $email = chatbot_clean($input['email'] ?? null);
    $phone = chatbot_clean($input['phone'] ?? null);
    $org = chatbot_clean($input['organization'] ?? null);

    if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'success' => false,
            'message' => 'Please provide your name and a valid email address.',
            'action' => 'request_gate',
            'gate' => ['passed' => false],
        ]);
        exit;
    }

    $name = mb_substr($name, 0, 150);
    $email = mb_substr($email, 0, 190);
    if ($phone) $phone = mb_substr($phone, 0, 40);
    if ($org) $org = mb_substr($org, 0, 150);

    $wasPassed = ($lead['gate_state'] ?? 'none') === 'passed';
    $newStatus = in_array($lead['status'] ?? '', ['apply_started', 'apply_completed'], true)
        ? $lead['status'] : 'gated_captured';

    chatbot_update_lead($conn, $sessionId, [
        'name' => $name, 'email' => $email, 'phone' => $phone, 'organization' => $org,
        'gate_state' => 'passed', 'status' => $newStatus,
    ]);
    chatbot_log_event($conn, 'gate_passed', ['session_id' => $sessionId, 'ip_address' => $ip, 'page' => $page]);

    if (!$wasPassed) {
        $mailBody = "New gated chatbot lead:\n\n"
            . "Name: $name\nEmail: $email\nPhone: " . ($phone ?: '-') . "\nOrganization: " . ($org ?: '-')
            . "\nIntent: " . ($lead['intent'] ?? 'general') . "\nSession: $sessionId\nPage: " . ($page ?: '-');
        chatbot_send_mail('info@ethioware.org', 'New partnership/pricing chatbot lead', $mailBody, $email);
    }

    // Re-answer the question that triggered the gate, now with the gated
    // knowledge included (lead.gate_state is 'passed' as of the update above).
    $lead = chatbot_get_lead($conn, $sessionId) ?? $lead;
    $messages = $conversation['messages'];
    $lastUserText = null;
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (($messages[$i]['role'] ?? '') === 'user') {
            $lastUserText = $messages[$i]['text'];
            $messages = array_slice($messages, 0, $i);
            break;
        }
    }

    $reply = "Thanks, $name — you're all set. What would you like to know?";
    if ($lastUserText !== null) {
        $systemPrompt = chatbot_build_system_prompt($lead);
        $history = array_slice($messages, -12);
        $result = chatbot_call_gemini($systemPrompt, $history, $lastUserText);
        if ($result && !empty($result['reply'])) {
            $reply = (string) $result['reply'];
        } else {
            chatbot_log_event($conn, 'quota_fallback', ['session_id' => $sessionId, 'ip_address' => $ip]);
        }
    }

    chatbot_append_message($conn, $sessionId, $conversation, 'model', $reply);
    echo json_encode(['success' => true, 'reply' => $reply, 'action' => 'none', 'gate' => ['passed' => true]]);
    exit;
}

// ---------------------------------------------------------------------
// Quota-fallback mini contact form: Gemini was unavailable on a prior turn,
// widget rendered its built-in contact form instead of a dead widget.
// ---------------------------------------------------------------------
if ($type === 'contact_submit') {
    $name = chatbot_clean($input['name'] ?? null);
    $email = chatbot_clean($input['email'] ?? null);
    if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        chatbot_fail(422, 'Please provide your name and a valid email address.');
    }
    $phone = chatbot_clean($input['phone'] ?? null);
    $org = chatbot_clean($input['organization'] ?? null);

    chatbot_update_lead($conn, $sessionId, [
        'name' => mb_substr($name, 0, 150),
        'email' => mb_substr($email, 0, 190),
        'phone' => $phone ? mb_substr($phone, 0, 40) : null,
        'organization' => $org ? mb_substr($org, 0, 150) : null,
    ]);

    echo json_encode([
        'success' => true,
        'reply' => "Thanks, $name — our team will follow up by email soon.",
        'action' => 'none',
        'gate' => ['passed' => ($lead['gate_state'] ?? 'none') === 'passed'],
    ]);
    exit;
}

// ---------------------------------------------------------------------
// Normal chat message.
// ---------------------------------------------------------------------
$message = chatbot_clean($input['message'] ?? null);
if (!$message) {
    chatbot_fail(400, 'Missing message.');
}
$message = mb_substr($message, 0, 2000);

$hintIntent = chatbot_clean($input['hint_intent'] ?? null);
if ($hintIntent !== null && !in_array($hintIntent, CHATBOT_INTENTS, true)) {
    $hintIntent = null;
}

// ---- Abuse caps: a shared, exhaustible free-tier quota (CHATBOT_SPEC.md §4.2) ----
if ($conversation['message_count'] >= CHATBOT_SESSION_MESSAGE_CAP) {
    $notice = "This conversation has reached its message limit. Please email info@ethioware.org and our team will help directly.";
    chatbot_append_message($conn, $sessionId, $conversation, 'user', $message);
    chatbot_append_message($conn, $sessionId, $conversation, 'model', $notice);
    echo json_encode(['success' => true, 'reply' => $notice, 'action' => 'capped', 'gate' => ['passed' => ($lead['gate_state'] ?? 'none') === 'passed']]);
    exit;
}
if ($ip && chatbot_ip_message_count_today($conn, $ip) >= CHATBOT_IP_DAILY_CAP) {
    $notice = "We've hit today's chat limit from your network. Please email info@ethioware.org and our team will help directly.";
    echo json_encode(['success' => true, 'reply' => $notice, 'action' => 'capped', 'gate' => ['passed' => ($lead['gate_state'] ?? 'none') === 'passed']]);
    exit;
}

// History sent to Gemini is everything BEFORE this turn; $message is passed
// to chatbot_call_gemini separately, so capture history before appending.
$historyBeforeThisTurn = $conversation['messages'];

// Persist the incoming user message and count it toward both caps.
chatbot_append_message($conn, $sessionId, $conversation, 'user', $message);
chatbot_log_event($conn, 'message', ['session_id' => $sessionId, 'ip_address' => $ip, 'page' => $page]);

// ---- Gate backstop: a short keyword check BEFORE the Gemini call. ----
// Rationale: the gate is a hard business rule and shouldn't rest solely on
// model compliance (CHATBOT_SPEC.md §7.3). Lives in prompt.php next to the
// gated-intent list, and is covered by ci/chatbot-content-test.php.
$backstopHit = chatbot_gate_keyword_hit($message);
if ($hintIntent !== null && in_array($hintIntent, CHATBOT_GATED_INTENTS, true)) {
    $backstopHit = true;
}

// If this is a gated topic and the visitor's contact info is already known
// (captured earlier, e.g. via the soft ask), the gate's requirement — a
// name + email on file — is already satisfied: unlock now rather than
// showing a redundant form for information we already have.
$hasContact = !empty($lead['email']) && filter_var($lead['email'], FILTER_VALIDATE_EMAIL) && !empty($lead['name']);
$wasAlreadyPassed = ($lead['gate_state'] ?? 'none') === 'passed';
if ($backstopHit && !$wasAlreadyPassed && $hasContact) {
    chatbot_update_lead($conn, $sessionId, ['gate_state' => 'passed']);
    chatbot_log_event($conn, 'gate_passed', ['session_id' => $sessionId, 'ip_address' => $ip, 'detail' => 'auto: contact already on file', 'page' => $page]);
    $lead = chatbot_get_lead($conn, $sessionId) ?? $lead;
}

$systemPrompt = chatbot_build_system_prompt($lead);
$history = array_slice($historyBeforeThisTurn, -12);
$data = chatbot_call_gemini($systemPrompt, $history, $message);

if ($data === null) {
    $fallback = "I'm briefly unavailable — leave your name and email and our team will get back to you.";
    chatbot_append_message($conn, $sessionId, $conversation, 'model', $fallback);
    chatbot_log_event($conn, 'quota_fallback', ['session_id' => $sessionId, 'ip_address' => $ip, 'page' => $page]);
    echo json_encode(['success' => true, 'reply' => $fallback, 'action' => 'quota_fallback', 'gate' => ['passed' => ($lead['gate_state'] ?? 'none') === 'passed']]);
    exit;
}

$intent = in_array($data['intent'] ?? '', CHATBOT_INTENTS, true) ? $data['intent'] : 'general';
$program = in_array($data['program'] ?? '', CHATBOT_PROGRAMS, true) ? $data['program'] : '';
$modelAction = in_array($data['action'] ?? '', ['none', 'refer_apply', 'request_gate'], true) ? $data['action'] : 'none';
$reply = is_string($data['reply'] ?? null) && $data['reply'] !== '' ? $data['reply'] : "I'm not sure — please email info@ethioware.org and our team will help.";

$leadUpdates = ['intent' => $intent];
if ($program !== '') {
    $leadUpdates['program_interest'] = $program;
}

// Extract plausible contact fields (never trust blindly — filter_var-validate email).
$contact = is_array($data['contact'] ?? null) ? $data['contact'] : [];
$extractedEmail = chatbot_clean($contact['email'] ?? null);
if ($extractedEmail && filter_var($extractedEmail, FILTER_VALIDATE_EMAIL) && empty($lead['email'])) {
    $leadUpdates['email'] = mb_substr($extractedEmail, 0, 190);
}
$extractedName = chatbot_clean($contact['name'] ?? null);
if ($extractedName && empty($lead['name'])) {
    $leadUpdates['name'] = mb_substr($extractedName, 0, 150);
}
$extractedPhone = chatbot_clean($contact['phone'] ?? null);
if ($extractedPhone && empty($lead['phone'])) {
    $leadUpdates['phone'] = mb_substr($extractedPhone, 0, 40);
}
$extractedOrg = chatbot_clean($contact['organization'] ?? null);
if ($extractedOrg && empty($lead['organization'])) {
    $leadUpdates['organization'] = mb_substr($extractedOrg, 0, 150);
}

$wasGateLocked = ($lead['gate_state'] ?? 'none') === 'locked';
$isGateSatisfiedNow = ($leadUpdates['email'] ?? $lead['email'] ?? null)
    && filter_var($leadUpdates['email'] ?? $lead['email'], FILTER_VALIDATE_EMAIL)
    && ($leadUpdates['name'] ?? $lead['name'] ?? null);

$gateNowNeeded = ($modelAction === 'request_gate' || ($backstopHit && !$hasContact))
    && ($lead['gate_state'] ?? 'none') !== 'passed'
    && !$isGateSatisfiedNow;

$responseAction = 'none';
$referralUrl = null;
$chips = null;

if ($gateNowNeeded) {
    if (!$wasGateLocked) {
        $leadUpdates['gate_state'] = 'locked';
        chatbot_log_event($conn, 'gate_shown', ['session_id' => $sessionId, 'ip_address' => $ip, 'detail' => $intent, 'page' => $page]);
    }
    $responseAction = 'request_gate';
} elseif ($modelAction === 'refer_apply') {
    $referralToken = $lead['referral_token'] ?? null;
    if (!$referralToken) {
        $referralToken = chatbot_uuid4();
    }
    $leadUpdates['referral_token'] = $referralToken;
    $leadUpdates['source'] = 'chatbot_apply';
    if (!in_array($lead['status'] ?? '', ['apply_started', 'apply_completed'], true)) {
        $leadUpdates['status'] = 'referred';
    }
    chatbot_log_event($conn, 'referred', ['session_id' => $sessionId, 'referral_token' => $referralToken, 'ip_address' => $ip, 'detail' => $program, 'page' => $page]);
    $responseAction = 'refer_apply';
    $referralUrl = '/apply.html?ref=' . $referralToken;
} elseif ($program === 'Unsure' && $intent === 'enrollment') {
    $chips = array_values(array_diff(CHATBOT_PROGRAMS, ['Unsure']));
}

if ($leadUpdates) {
    chatbot_update_lead($conn, $sessionId, $leadUpdates);
}

chatbot_append_message($conn, $sessionId, $conversation, 'model', $reply);

$response = [
    'success' => true,
    'reply' => $reply,
    'action' => $responseAction,
    'gate' => ['passed' => ($leadUpdates['gate_state'] ?? $lead['gate_state'] ?? 'none') === 'passed'],
];
if ($referralUrl) $response['referral_url'] = $referralUrl;
if ($chips) $response['chips'] = $chips;

echo json_encode($response);
