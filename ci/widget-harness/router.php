<?php
/**
 * Dev router for the widget test harness. Intercepts the chat endpoint with
 * canned responses (the real chat.php needs MySQL + a Gemini key) and serves
 * everything else straight from the repo.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri !== '/chatbot/api/chat.php') {
    return false; // static file
}

header('Content-Type: application/json; charset=utf-8');
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$type = $input['type'] ?? 'message';
$message = strtolower((string) ($input['message'] ?? ''));

if ($type === 'gate_submit') {
    if (!filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL) || empty($input['name'])) {
        echo json_encode(['success' => false, 'message' => 'Please provide your name and a valid email address.', 'action' => 'request_gate', 'gate' => ['passed' => false]]);
        exit;
    }
    echo json_encode(['success' => true, 'gate' => ['passed' => true], 'action' => 'none',
        'reply' => "Thanks! Sponsoring a learner starts at \$25 a month at /support, and we match it. Questions go to info@ethioware.org."]);
    exit;
}

if (strpos($message, 'boom') !== false) {   // network/500 path
    http_response_code(500);
    echo '<html>gateway blew up</html>';
    exit;
}
if (strpos($message, 'partner') !== false) {
    echo json_encode(['success' => true, 'action' => 'request_gate', 'gate' => ['passed' => false],
        'reply' => "Happy to help with partnerships. Our partnerships team handles the details — share your name and email and they'll follow up."]);
    exit;
}
if (strpos($message, 'apply') !== false) {
    echo json_encode(['success' => true, 'action' => 'refer_apply', 'gate' => ['passed' => false],
        'referral_url' => '/apply.html?ref=8f14e45f-ceea-467a-9f2d-6d6a0b5c9a11',
        'reply' => "Software Engineering Basics sounds right. Applying takes 3-5 minutes at /apply — there's no fee. Questions: info@ethioware.org"]);
    exit;
}
if (strpos($message, 'unsure') !== false) {
    echo json_encode(['success' => true, 'action' => 'none', 'gate' => ['passed' => false],
        'chips' => ['Software Engineering Basics', 'Engineering Basics', 'Law Basics', 'Medicine Basics', 'Research Scholars Program'],
        'reply' => 'No problem — which of these is closest to what you enjoy?']);
    exit;
}
if (strpos($message, 'messy') !== false) {   // markdown the prompt says not to emit
    echo json_encode(['success' => true, 'action' => 'none', 'gate' => ['passed' => false],
        'reply' => "**Great question!** You can [apply here](/apply) or read the *policy* at /privacy.\n- Verify a certificate at ethioware.org/WI10092516\n- Or see https://www.linkedin.com/company/ethioware/"]);
    exit;
}

echo json_encode(['success' => true, 'action' => 'none', 'gate' => ['passed' => false],
    'reply' => "Pre-trainings are 7-week online programs. Apply at /apply, sponsor at /support, or email info@ethioware.org."]);
