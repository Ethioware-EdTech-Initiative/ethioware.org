<?php
/**
 * Ethioware pre-training application handler.
 *
 * Mirrors research-scholars/save_signup.php and reuses the SAME MySQL database
 * (via that folder's config.php + rsp_get_connection()), but stores rows in a
 * SEPARATE table, `applications` (see db/applications.sql). Validates the
 * apply.html POST and stores it.
 * Returns JSON: {"success": bool, "message": string}.
 *
 * Setup on cPanel:
 *  1. The Research Scholars backend must already be configured — its
 *     research-scholars/config.php holds the shared DB credentials.
 *  2. Import db/applications.sql into that same database (phpMyAdmin → Import).
 *  3. Upload apply.html + apply-submit.php. Done.
 */

header('Content-Type: application/json; charset=utf-8');

const APPLY_COHORT = 'Aug';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

// Shared DB config + connection helper (same database as Research Scholars).
$sharedConfig = __DIR__ . '/research-scholars/config.php';
if (!is_file($sharedConfig)) {
    error_log('apply-submit: research-scholars/config.php missing');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server is not configured yet. Please email info@ethioware.org.']);
    exit;
}
require_once $sharedConfig;

// Honeypot: bots that fill the hidden "website" field get a silent success.
if (!empty($_POST['website'])) {
    echo json_encode(['success' => true, 'message' => 'Thanks! Your application has been received.']);
    exit;
}

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

// Allowed values — MUST mirror apply.html and the ENUMs in db/applications.sql.
$PROGRAMS = ['Software Engineering Basics', 'Engineering Basics', 'Law Basics', 'Medicine Basics'];
$GRADES   = ['11', '12', 'Graduated', 'University Freshman'];
$HEARD    = ['LinkedIn', 'Telegram', 'Friends (past learners)', 'Other'];
$YESNO    = ['Yes', 'No'];

// ---- Collect & sanitize ----
$full_name   = clean($_POST['full_name'] ?? null);
$email       = clean($_POST['email'] ?? null);
$highschool  = clean($_POST['highschool'] ?? null);
$citizenship = clean($_POST['citizenship'] ?? null);
$program     = pick(clean($_POST['program'] ?? null), $PROGRAMS);
$grade       = pick(clean($_POST['grade'] ?? null), $GRADES);
$gpa         = clean($_POST['gpa'] ?? null);
$telegram    = clean($_POST['telegram'] ?? null);
$where_heard = pick(clean($_POST['where_heard'] ?? null), $HEARD);
$linkedin    = pick(clean($_POST['linkedin_follow'] ?? null), $YESNO);

// ---- Required-field validation ----
$errors = [];
if (!$full_name) $errors[] = 'Full name is required.';
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
if (!$highschool) $errors[] = 'High school name is required.';
if (!$citizenship) $errors[] = 'Citizenship is required.';
if (!$program) $errors[] = 'Please choose a pre-training program.';
if (!$grade) $errors[] = 'Please select your grade.';
if (!$gpa) $errors[] = 'Grade/GPA is required.';
if (!$telegram) $errors[] = 'Telegram username is required.';
if (!$where_heard) $errors[] = 'Please tell us where you heard about the program.';
if (!$linkedin) $errors[] = 'Please answer the LinkedIn follow question.';

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

// Length guards (defense in depth alongside the apply.html limits)
$full_name   = mb_substr($full_name, 0, 150);
$email       = mb_substr($email, 0, 190);
$highschool  = mb_substr($highschool, 0, 200);
$citizenship = mb_substr($citizenship, 0, 100);
$gpa         = mb_substr($gpa, 0, 40);
$telegram    = mb_substr($telegram, 0, 100);

$cohort     = APPLY_COHORT;
$ip_address = clean($_SERVER['REMOTE_ADDR'] ?? null);

// ---- Insert into the separate `applications` table ----
$conn = rsp_get_connection();
$stmt = $conn->prepare(
    'INSERT INTO applications (
        full_name, email, highschool, citizenship, program, grade, gpa,
        telegram, where_heard, linkedin_follow, cohort, ip_address
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
if (!$stmt) {
    error_log('apply-submit prepare failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error preparing your application. Please try again.']);
    $conn->close();
    exit;
}
// 12 string placeholders
$stmt->bind_param(
    'ssssssssssss',
    $full_name, $email, $highschool, $citizenship, $program, $grade, $gpa,
    $telegram, $where_heard, $linkedin, $cohort, $ip_address
);
$success = $stmt->execute();

if (!$success) {
    error_log('apply-submit execute failed: ' . $stmt->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong saving your application. Please try again.']);
    $stmt->close();
    $conn->close();
    exit;
}
$stmt->close();
$conn->close();

echo json_encode(['success' => true, 'message' => "Application received! We’ll review it and reach out via Telegram or email."]);
