<?php
/**
 * Ethioware Research Scholars — Supporter registration handler.
 *
 * Mirrors apply-submit.php / research-scholars/save_signup.php and reuses the
 * SAME MySQL database (via research-scholars/config.php + rsp_get_connection()),
 * but stores rows in a SEPARATE table, `supporters` (see db/supporters.sql).
 * Validates the support.html POST (either the "sponsor" or "volunteer" flow)
 * and stores it. Returns JSON: {"success": bool, "message": string}.
 *
 * Setup on cPanel:
 *  1. The Research Scholars backend must already be configured — its
 *     research-scholars/config.php holds the shared DB credentials.
 *  2. Import db/supporters.sql into that same database (phpMyAdmin → Import).
 *  3. Upload support.html + support-submit.php. Done.
 */

header('Content-Type: application/json; charset=utf-8');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Shared DB config + connection helper (same database as Research Scholars).
$sharedConfig = __DIR__ . '/research-scholars/config.php';
if (!is_file($sharedConfig)) {
    error_log('support-submit: research-scholars/config.php missing');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server is not configured yet. Please email info@ethioware.org.']);
    exit;
}
require_once $sharedConfig;

/** trim + collapse empty to null */
function clean(?string $v): ?string {
    if ($v === null) return null;
    $v = trim($v);
    return $v === '' ? null : $v;
}
/** return $v only if it is in the allowed set, else null */
function pick(?string $v, array $allowed): ?string {
    return ($v !== null && in_array($v, $allowed, true)) ? $v : null;
}

$SUPPORT_TYPES = ['sponsor', 'volunteer'];
$FREQUENCIES   = ['one_time', 'monthly'];
$TRACKS        = ['stem', 'social', 'no_preference'];

// ---- Collect & sanitize shared fields ----
$support_type = pick(clean($_POST['support_type'] ?? null), $SUPPORT_TYPES);
$full_name    = clean($_POST['full_name'] ?? null);
$email        = clean($_POST['email'] ?? null);
$phone        = clean($_POST['phone'] ?? null);
$country      = clean($_POST['country'] ?? null);
$message      = clean($_POST['message'] ?? null);
$source       = clean($_POST['source'] ?? null);

$errors = [];
if (!$support_type) $errors[] = 'Please choose whether you are sponsoring or volunteering.';
if (!$full_name) $errors[] = 'Full name is required.';
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
if (!$country) $errors[] = "Please let us know where you're based.";

// ---- Flow-specific fields ----
$sponsor_amount = null;
$sponsor_frequency = null;
$volunteer_track = null;
$volunteer_link = null;
$volunteer_commitment = 0;

if ($support_type === 'sponsor') {
    $rawAmount = clean($_POST['sponsor_amount'] ?? null);
    if ($rawAmount === null || !is_numeric($rawAmount) || (float) $rawAmount <= 0) {
        $errors[] = 'Please enter a valid sponsorship amount.';
    } else {
        $sponsor_amount = round((float) $rawAmount, 2);
    }
    $sponsor_frequency = pick(clean($_POST['sponsor_frequency'] ?? null), $FREQUENCIES);
    if (!$sponsor_frequency) $errors[] = 'Please select how often you want to give.';
} elseif ($support_type === 'volunteer') {
    $volunteer_track = pick(clean($_POST['volunteer_track'] ?? null), $TRACKS);
    $rawLink = clean($_POST['volunteer_link'] ?? null);
    if ($rawLink !== null) {
        $volunteer_link = filter_var($rawLink, FILTER_VALIDATE_URL) ? $rawLink : null;
    }
    $volunteer_commitment = isset($_POST['volunteer_commitment']) ? 1 : 0;
    if (!$volunteer_commitment) $errors[] = 'Please confirm you can commit to the weekly time before submitting.';
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

// Length guards (defense in depth alongside the support.html limits)
$full_name = mb_substr($full_name, 0, 150);
$email     = mb_substr($email, 0, 190);
if ($phone) $phone = mb_substr($phone, 0, 40);
$country   = mb_substr($country, 0, 150);
if ($volunteer_link) $volunteer_link = mb_substr($volunteer_link, 0, 300);
if ($source) $source = mb_substr($source, 0, 150);

$ip_address = clean($_SERVER['REMOTE_ADDR'] ?? null);

// ---- Insert into the separate `supporters` table ----
$conn = rsp_get_connection();
$stmt = $conn->prepare(
    'INSERT INTO supporters (
        support_type, full_name, email, phone, country,
        sponsor_amount, sponsor_frequency,
        volunteer_track, volunteer_link, volunteer_commitment,
        message, source, ip_address
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
if (!$stmt) {
    error_log('support-submit prepare failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error preparing your submission. Please try again.']);
    $conn->close();
    exit;
}
// Types: support_type(s) full_name(s) email(s) phone(s) country(s)
//        sponsor_amount(d) sponsor_frequency(s)
//        volunteer_track(s) volunteer_link(s) volunteer_commitment(i)
//        message(s) source(s) ip_address(s)
$stmt->bind_param(
    'sssssdsssisss',
    $support_type, $full_name, $email, $phone, $country,
    $sponsor_amount, $sponsor_frequency,
    $volunteer_track, $volunteer_link, $volunteer_commitment,
    $message, $source, $ip_address
);
$success = $stmt->execute();

if (!$success) {
    error_log('support-submit execute failed: ' . $stmt->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong saving your submission. Please try again.']);
    $stmt->close();
    $conn->close();
    exit;
}
$stmt->close();
$conn->close();

$responseMessage = $support_type === 'sponsor'
    ? "Thank you for sponsoring a learner! We'll follow up by email with payment instructions within 2–3 days."
    : "Thanks for applying to mentor! Our team will review it and follow up by email within 2–3 days.";

echo json_encode(['success' => true, 'message' => $responseMessage]);
