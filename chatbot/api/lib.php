<?php
/**
 * Shared helpers for chatbot/api/*.php and chatbot/admin/*.php.
 * require_once's db.php itself, so callers only need to require this file.
 */

require_once __DIR__ . '/db.php';

/**
 * Wraps mysqli::prepare() and fails with a clean JSON 500 instead of a raw
 * PHP fatal ("call to bind_param() on bool") if it returns false — e.g. the
 * server's chatbot/config.php is fine but db/chatbot.sql hasn't been
 * imported yet, or a table/column is missing. Every prepare() call in the
 * chatbot code goes through this.
 */
function chatbot_prepare(mysqli $conn, string $sql): mysqli_stmt {
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        error_log('chatbot: prepare() failed (' . $conn->error . ') for: ' . $sql);
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        die(json_encode([
            'success' => false,
            'message' => 'Chatbot is not fully configured yet (database schema). Please try again later.',
        ]));
    }
    return $stmt;
}

function chatbot_client_ip(): ?string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    return $ip ? substr($ip, 0, 45) : null;
}

function chatbot_is_valid_uuid(?string $s): bool {
    return is_string($s) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s) === 1;
}

/** RFC 4122 v4 UUID using cryptographically strong randomness. */
function chatbot_uuid4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/** trim + collapse empty to null, same convention as apply-submit.php */
function chatbot_clean(?string $v): ?string {
    if ($v === null) return null;
    $v = trim($v);
    return $v === '' ? null : $v;
}

/** Fetch a chatbot_leads row by session_id, or null. */
function chatbot_get_lead(mysqli $conn, string $sessionId): ?array {
    $stmt = chatbot_prepare($conn, 'SELECT * FROM chatbot_leads WHERE session_id = ? LIMIT 1');
    $stmt->bind_param('s', $sessionId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

/**
 * Create the lead row if it doesn't exist yet. Never overwrites existing
 * contact/status fields — callers update those explicitly via
 * chatbot_update_lead().
 */
function chatbot_ensure_lead(mysqli $conn, string $sessionId, ?string $pageFirstSeen): array {
    $existing = chatbot_get_lead($conn, $sessionId);
    if ($existing) {
        return $existing;
    }
    $stmt = chatbot_prepare($conn, 
        'INSERT INTO chatbot_leads (session_id, page_first_seen) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE session_id = session_id'
    );
    $stmt->bind_param('ss', $sessionId, $pageFirstSeen);
    $stmt->execute();
    $stmt->close();
    return chatbot_get_lead($conn, $sessionId) ?? [
        'session_id' => $sessionId, 'gate_state' => 'none', 'status' => 'chat_only',
        'intent' => 'general', 'name' => null, 'email' => null, 'phone' => null,
        'organization' => null, 'program_interest' => null, 'referral_token' => null,
    ];
}

/**
 * Update arbitrary whitelisted columns on a lead row. $fields is
 * column => value; only known columns are accepted.
 */
function chatbot_update_lead(mysqli $conn, string $sessionId, array $fields): void {
    $allowed = ['name', 'email', 'phone', 'organization', 'intent', 'program_interest',
                'gate_state', 'status', 'source', 'referral_token'];
    $sets = [];
    $types = '';
    $values = [];
    foreach ($fields as $col => $val) {
        if (!in_array($col, $allowed, true)) continue;
        $sets[] = "`$col` = ?";
        $types .= 's';
        $values[] = $val;
    }
    if (!$sets) return;
    $types .= 's';
    $values[] = $sessionId;
    $sql = 'UPDATE chatbot_leads SET ' . implode(', ', $sets) . ' WHERE session_id = ?';
    $stmt = chatbot_prepare($conn, $sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
}

function chatbot_log_event(mysqli $conn, string $event, array $opts = []): void {
    $sessionId = $opts['session_id'] ?? null;
    $referralToken = $opts['referral_token'] ?? null;
    $ipAddress = $opts['ip_address'] ?? null;
    $detail = isset($opts['detail']) ? mb_substr((string) $opts['detail'], 0, 255) : null;
    $page = isset($opts['page']) ? mb_substr((string) $opts['page'], 0, 255) : null;

    $stmt = chatbot_prepare($conn, 
        'INSERT INTO chatbot_events (session_id, referral_token, ip_address, event, detail, page)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssssss', $sessionId, $referralToken, $ipAddress, $event, $detail, $page);
    $stmt->execute();
    $stmt->close();
}

/** Fetch (creating if needed) the conversation row's decoded messages array. */
function chatbot_get_conversation(mysqli $conn, string $sessionId, ?string $ipAddress): array {
    $stmt = chatbot_prepare($conn, 'SELECT messages, message_count FROM chatbot_conversations WHERE session_id = ? LIMIT 1');
    $stmt->bind_param('s', $sessionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $messages = json_decode($row['messages'], true);
        return ['messages' => is_array($messages) ? $messages : [], 'message_count' => (int) $row['message_count']];
    }

    $empty = json_encode([]);
    $stmt = chatbot_prepare($conn, 
        'INSERT INTO chatbot_conversations (session_id, messages, message_count, ip_address)
         VALUES (?, ?, 0, ?) ON DUPLICATE KEY UPDATE session_id = session_id'
    );
    $stmt->bind_param('sss', $sessionId, $empty, $ipAddress);
    $stmt->execute();
    $stmt->close();
    return ['messages' => [], 'message_count' => 0];
}

/**
 * Append one message, persist, and update $current in place (by reference)
 * so callers that append more than once per request see consistent state.
 * Returns the new message_count.
 */
function chatbot_append_message(mysqli $conn, string $sessionId, array &$current, string $role, string $text): int {
    $current['messages'][] = ['role' => $role, 'text' => $text, 't' => time()];
    $count = count($current['messages']);
    $current['message_count'] = $count;
    $json = json_encode($current['messages'], JSON_UNESCAPED_UNICODE);

    $stmt = chatbot_prepare($conn, 
        'UPDATE chatbot_conversations SET messages = ?, message_count = ?, last_message_at = CURRENT_TIMESTAMP
         WHERE session_id = ?'
    );
    $stmt->bind_param('sis', $json, $count, $sessionId);
    $stmt->execute();
    $stmt->close();
    return $count;
}

/** Count 'message' events from an IP in the last 24h, for daily abuse caps. */
function chatbot_ip_message_count_today(mysqli $conn, string $ipAddress): int {
    $stmt = chatbot_prepare($conn, 
        "SELECT COUNT(*) AS c FROM chatbot_events
         WHERE event = 'message' AND ip_address = ? AND created_at >= (NOW() - INTERVAL 1 DAY)"
    );
    $stmt->bind_param('s', $ipAddress);
    $stmt->execute();
    $c = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c;
}

/**
 * Best-effort notification email, same mail() pattern + CR/LF header-injection
 * guard as apply-submit.php. Failure never blocks the caller.
 */
function chatbot_send_mail(string $to, string $subject, string $body, ?string $replyTo = null): bool {
    $strip = static fn(string $v): string => str_replace(["\r", "\n"], '', $v);
    $subject = $strip($subject);
    $headers = "From: Ethioware Chatbot <no-reply@ethioware.org>\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
    if ($replyTo) {
        $headers .= 'Reply-To: ' . $strip($replyTo) . "\r\n";
    }
    return @mail($to, $subject, $body, $headers);
}
