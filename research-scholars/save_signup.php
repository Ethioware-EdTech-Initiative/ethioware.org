<?php
/**
 * EthioWare Research Scholars Program — Early Signup Handler
 *
 * Receives the signup.html form via POST, validates input, and stores it
 * in the rsp_signups MySQL table (see schema.sql).
 *
 * Setup on cPanel:
 *  1. Create a MySQL database + user in cPanel "MySQL® Databases", and add
 *     the user to the database with ALL PRIVILEGES.
 *  2. Import schema.sql via phpMyAdmin (or the cPanel "Import" tab) into
 *     that database.
 *  3. Edit config.php with your DB_HOST / DB_NAME / DB_USER / DB_PASS.
 *  4. Upload config.php, save_signup.php, and signup.html to the same
 *     folder on your server (e.g. public_html/research-scholars/).
 */

header('Content-Type: application/json; charset=utf-8');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

require_once __DIR__ . '/config.php';

/** Small helper: trim + collapse to null if empty */
function clean(?string $v): ?string {
    if ($v === null) return null;
    $v = trim($v);
    return $v === '' ? null : $v;
}

/** Validate a value is one of an allowed set; returns null if not allowed */
function pick(?string $v, array $allowed): ?string {
    return ($v !== null && in_array($v, $allowed, true)) ? $v : null;
}

// ---- Collect & sanitize input ----
$full_name        = clean($_POST['full_name'] ?? null);
$email            = clean($_POST['email'] ?? null);
$phone            = clean($_POST['phone'] ?? null);
$age_grade        = clean($_POST['age_grade'] ?? null);

$school_name      = clean($_POST['school_name'] ?? null);
$school_location  = clean($_POST['school_location'] ?? null);

$track_interest   = pick(clean($_POST['track_interest'] ?? null), ['STEM', 'Social Sciences', 'Not sure yet']);
$research_interest = clean($_POST['research_interest'] ?? null);
$prior_research_experience = pick(clean($_POST['prior_research_experience'] ?? null), [
    'None', 'Some (school project)', 'Some (independent)', 'Significant',
]);
$prior_research_detail = clean($_POST['prior_research_detail'] ?? null);

$english_comfort  = pick(clean($_POST['english_comfort'] ?? null), [
    'Very comfortable', 'Somewhat comfortable', 'Need support',
]);
$device_access    = pick(clean($_POST['device_access'] ?? null), [
    'Smartphone only', 'Laptop/Desktop', 'Both',
]);
$internet_reliability = pick(clean($_POST['internet_reliability'] ?? null), [
    'Reliable daily', 'Intermittent', 'Limited/rare',
]);
$weekly_time_commitment = pick(clean($_POST['weekly_time_commitment'] ?? null), [
    'Less than 3 hrs', '3-6 hrs', '6-10 hrs', '10+ hrs',
]);
$preferred_session_time = pick(clean($_POST['preferred_session_time'] ?? null), [
    'Weekday mornings', 'Weekday evenings', 'Weekends', 'Flexible/no preference',
]);
$heard_about_us   = clean($_POST['heard_about_us'] ?? null);
$questions_comments = clean($_POST['questions_comments'] ?? null);
$consent_contact  = isset($_POST['consent_contact']) ? 1 : 0;

// ---- Required-field validation ----
$errors = [];

if (!$full_name) $errors[] = 'Full name is required.';
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
if (!$age_grade) $errors[] = 'Grade level is required.';
if (!$school_name) $errors[] = 'School name is required.';
if (!$track_interest) $errors[] = 'Please select a research track interest.';
if (!$research_interest) $errors[] = 'Please describe your research interest.';
if (!$prior_research_experience) $errors[] = 'Please select your prior research experience.';
if (!$english_comfort) $errors[] = 'Please select your comfort level with academic English.';
if (!$device_access) $errors[] = 'Please select your device access.';
if (!$internet_reliability) $errors[] = 'Please select your internet reliability.';
if (!$weekly_time_commitment) $errors[] = 'Please select your expected weekly time commitment.';
if (!$preferred_session_time) $errors[] = 'Please select your preferred session time.';
if (!$consent_contact) $errors[] = 'Please confirm we may contact you about the program.';

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

// Basic length guards (defense in depth alongside maxlength in the HTML form)
if (mb_strlen($full_name) > 150)            { $full_name = mb_substr($full_name, 0, 150); }
if (mb_strlen($email) > 190)                { $email = mb_substr($email, 0, 190); }
if ($phone && mb_strlen($phone) > 40)        { $phone = mb_substr($phone, 0, 40); }
if (mb_strlen($school_name) > 200)          { $school_name = mb_substr($school_name, 0, 200); }
if ($school_location && mb_strlen($school_location) > 150) { $school_location = mb_substr($school_location, 0, 150); }
if ($heard_about_us && mb_strlen($heard_about_us) > 150)   { $heard_about_us = mb_substr($heard_about_us, 0, 150); }

$ip_address = clean($_SERVER['REMOTE_ADDR'] ?? null);

// ---- Insert into database ----
$conn = rsp_get_connection();

$stmt = $conn->prepare(
    'INSERT INTO rsp_signups (
        full_name, email, phone, age_grade,
        school_name, school_location,
        track_interest, research_interest, prior_research_experience, prior_research_detail,
        english_comfort, device_access, internet_reliability, weekly_time_commitment, preferred_session_time,
        heard_about_us, questions_comments, consent_contact, ip_address
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error preparing your submission. Please try again.']);
    exit;
}

// 19 placeholders: 17 strings, then consent_contact as integer (i), then ip_address as string (s)
$stmt->bind_param(
    'sssssssssssssssssis',
    $full_name, $email, $phone, $age_grade,
    $school_name, $school_location,
    $track_interest, $research_interest, $prior_research_experience, $prior_research_detail,
    $english_comfort, $device_access, $internet_reliability, $weekly_time_commitment, $preferred_session_time,
    $heard_about_us, $questions_comments, $consent_contact, $ip_address
);

$success = $stmt->execute();

if ($success) {
    echo json_encode(['success' => true, 'message' => "Thanks! You're on the list — we'll be in touch soon."]);
} else {
    // Duplicate email (unique key) → friendly message
    if ($conn->errno === 1062) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'That email is already registered for early interest.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Something went wrong saving your submission. Please try again.']);
    }
}

$stmt->close();
$conn->close();
