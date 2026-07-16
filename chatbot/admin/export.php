<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/leads_query.php';
chatbot_admin_require_login();

$conn = chatbot_db();
$rows = chatbot_admin_fetch_leads_all($conn, $_GET);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="chatbot-leads-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Date', 'Name', 'Email', 'Phone', 'Organization', 'Intent', 'Program', 'Source', 'Status', 'Session ID']);
foreach ($rows as $row) {
    fputcsv($out, [
        $row['created_at'],
        $row['name'] ?: '',
        $row['email'] ?: '',
        $row['phone'] ?: '',
        $row['organization'] ?: '',
        $row['intent'],
        $row['program_interest'] ?: '',
        $row['source'] === 'chatbot_apply' ? 'chatbot->apply' : 'chatbot',
        chatbot_admin_status_label($row),
        $row['session_id'],
    ]);
}
fclose($out);
