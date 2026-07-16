<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/leads_query.php';
chatbot_admin_require_login();

$conn = chatbot_db();

$sessionId = $_GET['session'] ?? '';
if (!chatbot_is_valid_uuid($sessionId)) {
    http_response_code(400);
    exit('Invalid session.');
}

$lead = chatbot_get_lead($conn, $sessionId);
if (!$lead) {
    http_response_code(404);
    exit('Lead not found.');
}

$stmt = $conn->prepare('SELECT messages, started_at, last_message_at, ip_address FROM chatbot_conversations WHERE session_id = ? LIMIT 1');
$stmt->bind_param('s', $sessionId);
$stmt->execute();
$conv = $stmt->get_result()->fetch_assoc();
$stmt->close();
$messages = $conv ? (json_decode($conv['messages'], true) ?: []) : [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Transcript — Chatbot Admin</title>
<style>
  :root{ --first-color:hsl(215,80%,32%); --bg:hsl(215,24%,96%); --title-color:hsl(215,4%,15%); --text-color:hsl(215,4%,40%); --border:#e2e6ec; }
  *{box-sizing:border-box;}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Poppins,sans-serif;background:var(--bg);color:var(--text-color);margin:0;padding:1.5rem;max-width:760px;margin-inline:auto;}
  a.back{color:var(--first-color);text-decoration:none;font-size:.85rem;}
  h1{font-size:1.15rem;color:var(--title-color);}
  .meta{background:#fff;border:1px solid var(--border);border-radius:10px;padding:.85rem 1rem;margin:1rem 0;font-size:.85rem;}
  .meta dl{display:grid;grid-template-columns:auto 1fr;gap:.25rem .75rem;margin:0;}
  .meta dt{color:var(--text-color);}
  .meta dd{margin:0;color:var(--title-color);}
  .thread{display:flex;flex-direction:column;gap:.6rem;}
  .msg{max-width:80%;padding:.55rem .8rem;border-radius:10px;font-size:.9rem;white-space:pre-wrap;}
  .msg.user{align-self:flex-end;background:var(--first-color);color:#fff;border-bottom-right-radius:2px;}
  .msg.model{align-self:flex-start;background:#fff;border:1px solid var(--border);color:var(--title-color);border-bottom-left-radius:2px;}
  .msg .t{display:block;font-size:.65rem;opacity:.7;margin-top:.25rem;}
</style>
</head>
<body>
  <a class="back" href="index.php">&larr; Back to leads</a>
  <h1>Transcript</h1>
  <div class="meta">
    <dl>
      <dt>Session</dt><dd><?= chatbot_admin_html_escape($sessionId) ?></dd>
      <dt>Name / Email</dt><dd><?= chatbot_admin_html_escape($lead['name'] ?: '—') ?> / <?= chatbot_admin_html_escape($lead['email'] ?: '—') ?></dd>
      <dt>Status</dt><dd><?= chatbot_admin_html_escape($lead['status']) ?> (gate: <?= chatbot_admin_html_escape($lead['gate_state']) ?>)</dd>
      <dt>Intent / Program</dt><dd><?= chatbot_admin_html_escape($lead['intent']) ?> / <?= chatbot_admin_html_escape($lead['program_interest'] ?: '—') ?></dd>
      <dt>Page first seen</dt><dd><?= chatbot_admin_html_escape($lead['page_first_seen'] ?: '—') ?></dd>
      <dt>IP</dt><dd><?= chatbot_admin_html_escape($conv['ip_address'] ?? '—') ?></dd>
    </dl>
  </div>
  <div class="thread">
    <?php if (!$messages): ?>
      <p>No messages recorded.</p>
    <?php endif; ?>
    <?php foreach ($messages as $m): ?>
      <div class="msg <?= ($m['role'] ?? '') === 'user' ? 'user' : 'model' ?>">
        <?= nl2br(chatbot_admin_html_escape($m['text'] ?? '')) ?>
        <?php if (!empty($m['t'])): ?><span class="t"><?= chatbot_admin_html_escape(date('M j, H:i:s', (int) $m['t'])) ?></span><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</body>
</html>
