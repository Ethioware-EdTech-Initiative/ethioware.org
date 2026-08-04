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

// Chatbot referral link-back. NOT part of the program/grade/where_heard/
// linkedin_follow whitelist-sync set (CHATBOT_SPEC.md §7.2.4): it's nullable,
// format-validated free input, not an enumerated field. A missing or
// malformed chat_ref is silently discarded and must never fail the application.
$chat_ref_raw = clean($_POST['chat_ref'] ?? null);
$chat_ref = ($chat_ref_raw && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $chat_ref_raw))
    ? $chat_ref_raw : null;

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
$insertId = $stmt->insert_id;
$stmt->close();

// ---------------------------------------------------------------------------
// Instatic CMS dual-write (P3-2).
//
// Fire-and-forget: mirror the new application row into the Instatic CMS
// "applications" data table so that admins can view it in the CMS dashboard.
// On sync failure we log and move on — the MySQL INSERT already succeeded,
// and the user must never see a sync error.
//
// Requires server-only env vars (set in hosting; NOT committed):
//   INSTATIC_SYNC_URL    — e.g. http://127.0.0.1:3001/api/data/applications
//   INSTATIC_SYNC_SECRET — shared HMAC secret for authenticating sync POSTs
// ---------------------------------------------------------------------------
$syncUrl    = getenv('INSTATIC_SYNC_URL');
$syncSecret = getenv('INSTATIC_SYNC_SECRET');

if ($syncUrl && $syncSecret) {
    $syncPayload = json_encode([
        'id'              => $insertId,
        'full_name'       => $full_name,
        'email'           => $email,
        'highschool'      => $highschool,
        'citizenship'     => $citizenship,
        'program'         => $program,
        'grade'           => $grade,
        'gpa'             => $gpa,
        'telegram'        => $telegram,
        'where_heard'     => $where_heard,
        'linkedin_follow' => $linkedin,
        'cohort'          => $cohort,
        'ip_address'      => $ip_address,
        'created_at'      => date('c'),
    ]);

    $syncSig = hash_hmac('sha256', $syncPayload, $syncSecret);

    $ch = curl_init($syncUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $syncPayload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Instatic-Signature: ' . $syncSig,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,           // hard cap — never block the user
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $syncResp = curl_exec($ch);
    $syncCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $syncErr  = curl_error($ch);
    curl_close($ch);

    if ($syncResp === false || $syncCode < 200 || $syncCode >= 300) {
        error_log(sprintf(
            'apply-submit: Instatic sync failed (HTTP %d): %s — payload id=%d',
            $syncCode, $syncErr ?: $syncResp, $insertId
        ));
    }
}

// Best-effort: link this application back to the chatbot referral and flip
// the lead's status. Deliberately a separate step from the INSERT above (not
// an extra column in it) so that a not-yet-imported db/chatbot.sql — the
// chat_ref column, or the chatbot_leads/chatbot_events tables — can never
// fail the application that's already been saved. mysqli_report is
// MYSQLI_REPORT_OFF (set by rsp_get_connection()), so prepare() just returns
// false on an unknown column/table instead of throwing.
if ($chat_ref) {
    $linkStmt = $conn->prepare('UPDATE applications SET chat_ref = ? WHERE id = ?');
    if ($linkStmt) {
        $linkStmt->bind_param('si', $chat_ref, $insertId);
        $linkStmt->execute();
        $linkStmt->close();
    }
    $leadStmt = $conn->prepare("UPDATE chatbot_leads SET status = 'apply_completed' WHERE referral_token = ?");
    if ($leadStmt) {
        $leadStmt->bind_param('s', $chat_ref);
        $leadStmt->execute();
        $leadStmt->close();
    }
    $eventStmt = $conn->prepare("INSERT INTO chatbot_events (referral_token, event, ip_address) VALUES (?, 'apply_completed', ?)");
    if ($eventStmt) {
        $eventStmt->bind_param('ss', $chat_ref, $ip_address);
        $eventStmt->execute();
        $eventStmt->close();
    }
}

$conn->close();

echo json_encode(['success' => true, 'message' => "Application received! We’ll review it and reach out via Telegram or email."]);
