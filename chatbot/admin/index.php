<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../api/prompt.php';
require_once __DIR__ . '/leads_query.php';

if (isset($_GET['logout'])) {
    chatbot_admin_session_start();
    // login.php?signout=1 also clears the Clerk session in the browser, so
    // "Log out" doesn't leave the next visitor silently signed back in.
    $wasClerk = chatbot_admin_auth_method() === 'clerk';
    chatbot_admin_end_session();
    header('Location: login.php' . ($wasClerk ? '?signout=1' : ''));
    exit;
}

chatbot_admin_require_login();
$adminUser = chatbot_admin_current_user();

$conn = chatbot_db();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$result = chatbot_admin_fetch_leads($conn, $_GET, $page, $perPage);
$rows = $result['rows'];
$total = $result['total'];
$totalPages = max(1, (int) ceil($total / $perPage));

// ---- Stats strip ----
$statsQ = $conn->query("
    SELECT
      SUM(created_at >= (NOW() - INTERVAL 7 DAY)) AS leads_this_week,
      SUM(created_at >= (NOW() - INTERVAL 14 DAY) AND created_at < (NOW() - INTERVAL 7 DAY)) AS leads_last_week,
      SUM(status = 'referred' OR status = 'apply_started' OR status = 'apply_completed') AS referred_total,
      SUM(status = 'apply_completed') AS completed_total,
      SUM(intent IN ('partnership','pricing','donation','investment') AND status = 'gated_captured') AS pending_partnership
    FROM chatbot_leads
");
$stats = $statsQ ? $statsQ->fetch_assoc() : [];
$leadsThisWeek = (int) ($stats['leads_this_week'] ?? 0);
$leadsLastWeek = (int) ($stats['leads_last_week'] ?? 0);
$referredTotal = (int) ($stats['referred_total'] ?? 0);
$completedTotal = (int) ($stats['completed_total'] ?? 0);
$pendingPartnership = (int) ($stats['pending_partnership'] ?? 0);
$conversionRate = $referredTotal > 0 ? round(100 * $completedTotal / $referredTotal) : 0;

$abandonedQ = $conn->query("
    SELECT COUNT(*) AS c FROM (
      SELECT l.id,
        MAX(CASE WHEN e.event = 'apply_started' THEN e.created_at END) AS started_at,
        (a.id IS NOT NULL) AS completed
      FROM chatbot_leads l
      LEFT JOIN chatbot_events e ON e.referral_token = l.referral_token AND l.referral_token IS NOT NULL
      LEFT JOIN applications a ON a.chat_ref = l.referral_token AND l.referral_token IS NOT NULL
      WHERE l.status = 'apply_started'
      GROUP BY l.id
      HAVING completed = 0 AND started_at IS NOT NULL AND started_at < (NOW() - INTERVAL 30 MINUTE)
    ) t
");
$abandonedCount = (int) ($abandonedQ ? ($abandonedQ->fetch_assoc()['c'] ?? 0) : 0);
$abandonmentRate = $referredTotal > 0 ? round(100 * $abandonedCount / $referredTotal) : 0;

$topProgramsQ = $conn->query("
    SELECT program_interest, COUNT(*) AS c FROM chatbot_leads
    WHERE program_interest IS NOT NULL AND program_interest <> ''
    GROUP BY program_interest ORDER BY c DESC LIMIT 5
");
$topPrograms = $topProgramsQ ? $topProgramsQ->fetch_all(MYSQLI_ASSOC) : [];
$maxProgramCount = $topPrograms ? max(array_column($topPrograms, 'c')) : 1;

function qs(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') unset($params[$k]);
    }
    return htmlspecialchars('?' . http_build_query($params), ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Chatbot Admin — Leads</title>
<style>
  :root{ --first-color:hsl(215,80%,32%); --body-color:#fff; --bg:hsl(215,24%,96%); --title-color:hsl(215,4%,15%); --text-color:hsl(215,4%,40%); --border:#e2e6ec; }
  *{box-sizing:border-box;}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Poppins,sans-serif;background:var(--bg);color:var(--text-color);margin:0;padding:1.5rem;}
  h1{font-size:1.25rem;color:var(--title-color);margin:0 0 1rem;display:flex;align-items:center;justify-content:space-between;}
  h1 a{font-size:.8rem;font-weight:500;color:var(--first-color);text-decoration:none;}
  h1 .session{display:flex;align-items:center;gap:.6rem;}
  h1 .who{font-size:.75rem;font-weight:500;color:var(--text-color);}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem;margin-bottom:1.25rem;}
  .stat{background:var(--body-color);border:1px solid var(--border);border-radius:10px;padding:.85rem 1rem;}
  .stat .num{font-size:1.4rem;font-weight:700;color:var(--title-color);}
  .stat .label{font-size:.75rem;text-transform:uppercase;letter-spacing:.03em;}
  .bars{background:var(--body-color);border:1px solid var(--border);border-radius:10px;padding:.85rem 1rem;margin-bottom:1.25rem;}
  .bar-row{display:flex;align-items:center;gap:.5rem;font-size:.85rem;margin:.3rem 0;}
  .bar-row .name{width:170px;flex-shrink:0;color:var(--title-color);}
  .bar-track{flex:1;background:#eef1f5;border-radius:4px;height:10px;overflow:hidden;}
  .bar-fill{background:var(--first-color);height:100%;}
  .bar-row .count{width:2rem;text-align:right;}
  form.filters{display:flex;flex-wrap:wrap;gap:.5rem;align-items:end;background:var(--body-color);border:1px solid var(--border);border-radius:10px;padding:.85rem 1rem;margin-bottom:1rem;}
  form.filters label{display:flex;flex-direction:column;font-size:.7rem;text-transform:uppercase;letter-spacing:.03em;gap:.2rem;}
  form.filters input,form.filters select{padding:.4rem .5rem;border:1px solid var(--border);border-radius:6px;font-size:.85rem;}
  form.filters button, .btn{padding:.45rem .9rem;border:1px solid var(--first-color);border-radius:6px;background:var(--first-color);color:#fff;font-size:.85rem;cursor:pointer;text-decoration:none;}
  .btn.secondary{background:transparent;color:var(--first-color);}
  .presets{margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;}
  table{width:100%;border-collapse:collapse;background:var(--body-color);border:1px solid var(--border);border-radius:10px;overflow:hidden;font-size:.85rem;}
  th,td{padding:.55rem .7rem;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap;}
  th{background:#f5f7fa;font-size:.72rem;text-transform:uppercase;letter-spacing:.03em;color:var(--text-color);}
  tr:last-child td{border-bottom:none;}
  .badge{display:inline-block;padding:.1rem .5rem;border-radius:20px;font-size:.72rem;background:hsl(215,24%,92%);color:var(--title-color);}
  .pager{display:flex;gap:.5rem;justify-content:center;margin-top:1rem;}
  .table-wrap{overflow-x:auto;}
</style>
</head>
<body>
  <h1>Chatbot Leads
    <span class="session">
      <?php if ($adminUser !== null): ?><span class="who">Signed in as <?= chatbot_admin_html_escape($adminUser) ?></span><?php endif; ?>
      <a class="btn secondary" style="font-size:.75rem;" href="index.php?logout=1">Log out</a>
    </span>
  </h1>

  <div class="stats">
    <div class="stat"><div class="num"><?= $leadsThisWeek ?></div><div class="label">Leads this week (<?= $leadsLastWeek ?> last week)</div></div>
    <div class="stat"><div class="num"><?= $conversionRate ?>%</div><div class="label">Referral → completion</div></div>
    <div class="stat"><div class="num"><?= $abandonmentRate ?>%</div><div class="label">Abandonment rate</div></div>
    <div class="stat"><div class="num"><?= $pendingPartnership ?></div><div class="label">Partnership follow-ups pending</div></div>
  </div>

  <?php if ($topPrograms): ?>
  <div class="bars">
    <strong style="font-size:.8rem;color:var(--title-color);">Top programs by interest</strong>
    <?php foreach ($topPrograms as $p): $pct = round(100 * $p['c'] / $maxProgramCount); ?>
      <div class="bar-row">
        <span class="name"><?= chatbot_admin_html_escape($p['program_interest']) ?></span>
        <span class="bar-track"><span class="bar-fill" style="width:<?= $pct ?>%"></span></span>
        <span class="count"><?= (int) $p['c'] ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="presets">
    <a class="btn secondary" href="<?= qs(['status' => 'abandoned', 'page' => null]) ?>">Abandoned applications</a>
    <a class="btn secondary" href="<?= qs(['status' => 'pending_partnership', 'page' => null]) ?>">Partnership inquiries pending follow-up</a>
    <a class="btn secondary" href="index.php">Clear filters</a>
    <a class="btn" href="export.php?<?= substr(qs(), 1) ?>">Export CSV</a>
  </div>

  <form class="filters" method="GET">
    <label>From <input type="date" name="date_from" value="<?= chatbot_admin_html_escape($_GET['date_from'] ?? '') ?>"></label>
    <label>To <input type="date" name="date_to" value="<?= chatbot_admin_html_escape($_GET['date_to'] ?? '') ?>"></label>
    <label>Intent
      <select name="intent">
        <option value="">All</option>
        <?php foreach (CHATBOT_INTENTS as $i): ?>
          <option value="<?= $i ?>" <?= ($_GET['intent'] ?? '') === $i ? 'selected' : '' ?>><?= ucfirst($i) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Program
      <select name="program">
        <option value="">All</option>
        <?php foreach (array_slice(CHATBOT_PROGRAMS, 0, -1) as $p): ?>
          <option value="<?= chatbot_admin_html_escape($p) ?>" <?= ($_GET['program'] ?? '') === $p ? 'selected' : '' ?>><?= chatbot_admin_html_escape($p) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Status
      <select name="status">
        <option value="">All</option>
        <?php foreach (CHATBOT_ADMIN_STATUS_PRESETS as $s): ?>
          <option value="<?= $s ?>" <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit">Filter</button>
  </form>

  <div class="table-wrap">
  <table>
    <thead><tr>
      <th>Date</th><th>Name</th><th>Email</th><th>Phone</th><th>Org</th>
      <th>Intent</th><th>Program</th><th>Source</th><th>Status</th><th>Transcript</th>
    </tr></thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="10" style="text-align:center;color:var(--text-color);">No leads match these filters.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= chatbot_admin_html_escape(date('M j, H:i', strtotime($row['created_at']))) ?></td>
          <td><?= chatbot_admin_html_escape($row['name'] ?: '—') ?></td>
          <td><?= chatbot_admin_html_escape($row['email'] ?: '—') ?></td>
          <td><?= chatbot_admin_html_escape($row['phone'] ?: '—') ?></td>
          <td><?= chatbot_admin_html_escape($row['organization'] ?: '—') ?></td>
          <td><span class="badge"><?= chatbot_admin_html_escape($row['intent']) ?></span></td>
          <td><?= chatbot_admin_html_escape($row['program_interest'] ?: '—') ?></td>
          <td><?= $row['source'] === 'chatbot_apply' ? 'chatbot→apply' : 'chatbot' ?></td>
          <td><?= chatbot_admin_html_escape(chatbot_admin_status_label($row)) ?></td>
          <td><a href="view.php?session=<?= urlencode($row['session_id']) ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pager">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a class="btn <?= $p === $page ? '' : 'secondary' ?>" href="<?= qs(['page' => $p]) ?>"><?= $p ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>

  <p style="font-size:.72rem;color:var(--text-color);margin-top:1.5rem;">
    Retention: transcripts (chatbot_conversations) are kept 12 months, then deleted.
    There's no cron on this host, so this is a manual quarterly cleanup via phpMyAdmin —
    run <code>DELETE FROM chatbot_conversations WHERE last_message_at &lt; (NOW() - INTERVAL 12 MONTH)</code>.
    Lead contact details (name/email) are kept indefinitely, same as other inquiries.
  </p>
</body>
</html>
