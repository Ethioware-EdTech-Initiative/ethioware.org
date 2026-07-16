<?php
/**
 * Shared leads-list query builder for index.php and export.php.
 * Derives abandonment/completion status per CHATBOT_SPEC.md §3.3/§8 with one
 * LEFT JOIN — no background workers, no stored "abandoned" status.
 */

const CHATBOT_ADMIN_STATUS_PRESETS = [
    'chat_only', 'gated_captured', 'referred', 'apply_started', 'apply_completed',
    'abandoned', 'pending_partnership',
];

/**
 * Builds WHERE/HAVING clauses + bind params from GET filters.
 * @return array{where:string, having:string, types:string, params:array}
 */
function chatbot_admin_build_filters(array $get): array {
    $where = ['1=1'];
    $having = [];
    $types = '';
    $params = [];

    $dateFrom = chatbot_clean($get['date_from'] ?? null);
    if ($dateFrom && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $where[] = 'l.created_at >= ?';
        $types .= 's';
        $params[] = $dateFrom . ' 00:00:00';
    }
    $dateTo = chatbot_clean($get['date_to'] ?? null);
    if ($dateTo && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $where[] = 'l.created_at <= ?';
        $types .= 's';
        $params[] = $dateTo . ' 23:59:59';
    }

    $intent = chatbot_clean($get['intent'] ?? null);
    if ($intent && in_array($intent, CHATBOT_INTENTS, true)) {
        $where[] = 'l.intent = ?';
        $types .= 's';
        $params[] = $intent;
    }

    $program = chatbot_clean($get['program'] ?? null);
    if ($program) {
        $where[] = 'l.program_interest = ?';
        $types .= 's';
        $params[] = mb_substr($program, 0, 80);
    }

    $status = chatbot_clean($get['status'] ?? null);
    if ($status && in_array($status, CHATBOT_ADMIN_STATUS_PRESETS, true)) {
        if ($status === 'abandoned') {
            $where[] = "l.status = 'apply_started'";
            $having[] = 'completed = 0';
            $having[] = "started_at IS NOT NULL AND started_at < (NOW() - INTERVAL 30 MINUTE)";
        } elseif ($status === 'pending_partnership') {
            $where[] = "l.intent IN ('partnership','pricing','donation','investment')";
            $where[] = "l.status = 'gated_captured'";
        } else {
            $where[] = 'l.status = ?';
            $types .= 's';
            $params[] = $status;
        }
    }

    return [
        'where' => implode(' AND ', $where),
        'having' => $having ? implode(' AND ', $having) : '1=1',
        'types' => $types,
        'params' => $params,
    ];
}

const CHATBOT_ADMIN_BASE_SELECT = "
    SELECT l.*,
      MAX(CASE WHEN e.event = 'apply_started' THEN e.created_at END) AS started_at,
      MAX(CASE WHEN e.event = 'apply_step' THEN e.detail END)        AS last_step,
      (a.id IS NOT NULL)                                             AS completed
    FROM chatbot_leads l
    LEFT JOIN chatbot_events e ON e.referral_token = l.referral_token AND l.referral_token IS NOT NULL
    LEFT JOIN applications  a ON a.chat_ref        = l.referral_token AND l.referral_token IS NOT NULL
";

/**
 * @return array{rows: array, total: int}
 */
function chatbot_admin_fetch_leads(mysqli $conn, array $get, int $page, int $perPage): array {
    $f = chatbot_admin_build_filters($get);

    $sql = CHATBOT_ADMIN_BASE_SELECT . " WHERE {$f['where']} GROUP BY l.id HAVING {$f['having']}
            ORDER BY l.created_at DESC LIMIT ? OFFSET ?";
    $types = $f['types'] . 'ii';
    $offset = max(0, ($page - 1) * $perPage);
    $params = array_merge($f['params'], [$perPage, $offset]);

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Count total matching rows (same filters, no LIMIT/OFFSET) for pagination.
    $countInner = CHATBOT_ADMIN_BASE_SELECT . " WHERE {$f['where']} GROUP BY l.id HAVING {$f['having']}";
    $countSql = "SELECT COUNT(*) AS c FROM ({$countInner}) t";
    $stmt = $conn->prepare($countSql);
    if ($f['types']) {
        $stmt->bind_param($f['types'], ...$f['params']);
    }
    $stmt->execute();
    $total = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    return ['rows' => $rows, 'total' => $total];
}

/** All matching rows, unpaginated (for CSV export). */
function chatbot_admin_fetch_leads_all(mysqli $conn, array $get): array {
    $f = chatbot_admin_build_filters($get);
    $sql = CHATBOT_ADMIN_BASE_SELECT . " WHERE {$f['where']} GROUP BY l.id HAVING {$f['having']}
            ORDER BY l.created_at DESC";
    $stmt = $conn->prepare($sql);
    if ($f['types']) {
        $stmt->bind_param($f['types'], ...$f['params']);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Human status label + emoji, matching the table in CHATBOT_SPEC.md §8. */
function chatbot_admin_status_label(array $row): string {
    $abandoned = ($row['status'] === 'apply_started') && !$row['completed']
        && $row['started_at'] && (strtotime($row['started_at']) < time() - 1800);

    if ($row['status'] === 'apply_completed' || $row['completed']) {
        return 'Applied ✓';
    }
    if ($abandoned) {
        $step = $row['last_step'] ? " (last step {$row['last_step']})" : '';
        return "Started — abandoned ⚠{$step}";
    }
    switch ($row['status']) {
        case 'gated_captured': return 'Gated lead ✉';
        case 'referred': return 'Referred';
        case 'apply_started': return 'Started';
        default: return 'Chat only';
    }
}
