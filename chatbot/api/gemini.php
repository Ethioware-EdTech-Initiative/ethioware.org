<?php
/**
 * Gemini generateContent caller. One REST call per user message; see
 * CHATBOT_SPEC.md §4.3 for the exact request shape this mirrors.
 */

const CHATBOT_GEMINI_RESPONSE_SCHEMA = [
    'type' => 'OBJECT',
    'properties' => [
        'reply' => ['type' => 'STRING'],
        'intent' => ['type' => 'STRING', 'enum' => [
            'general', 'enrollment', 'partnership', 'pricing', 'donation',
            'investment', 'support', 'other',
        ]],
        'program' => ['type' => 'STRING', 'enum' => [
            'Software Engineering Basics', 'Engineering Basics', 'Law Basics',
            'Medicine Basics', 'Research Scholars Program', 'Unsure', '',
        ]],
        'contact' => ['type' => 'OBJECT', 'properties' => [
            'name' => ['type' => 'STRING'],
            'email' => ['type' => 'STRING'],
            'phone' => ['type' => 'STRING'],
            'organization' => ['type' => 'STRING'],
        ]],
        'action' => ['type' => 'STRING', 'enum' => ['none', 'refer_apply', 'request_gate']],
    ],
    'required' => ['reply', 'intent', 'action'],
];

// Budget for the JSON envelope + up to 3 short paragraphs of reply. Was 512,
// which a thinking model could exhaust before writing any reply at all.
const CHATBOT_GEMINI_MAX_OUTPUT_TOKENS = 800;

// Worst case a visitor waits is primary + fallback, so keep both tight.
const CHATBOT_GEMINI_TIMEOUT = 15;
const CHATBOT_GEMINI_FALLBACK_TIMEOUT = 10;

/**
 * generationConfig for one call.
 *
 * Gemini 2.5 Flash reasons before answering by default, and those thinking
 * tokens are billed against maxOutputTokens. For grounded answers off a small
 * FAQ that buys nothing measurable, while costing seconds of latency on every
 * turn — and a long think can eat the whole budget and return truncated JSON,
 * which the visitor sees as "I'm briefly unavailable". So thinking is switched
 * off here. Override with CHATBOT_GEMINI_THINKING_BUDGET in chatbot/config.php
 * (2.5 Pro cannot disable thinking, so it is left alone).
 */
function chatbot_gemini_generation_config(string $model): array {
    $config = [
        'temperature' => 0.4,
        'maxOutputTokens' => CHATBOT_GEMINI_MAX_OUTPUT_TOKENS,
        'responseMimeType' => 'application/json',
        'responseSchema' => CHATBOT_GEMINI_RESPONSE_SCHEMA,
    ];
    if (defined('CHATBOT_GEMINI_THINKING_BUDGET')) {
        $config['thinkingConfig'] = ['thinkingBudget' => (int) CHATBOT_GEMINI_THINKING_BUDGET];
    } elseif (preg_match('/^gemini-2\.5-flash/', $model)) {
        $config['thinkingConfig'] = ['thinkingBudget' => 0];
    }
    return $config;
}

/**
 * Last-ditch recovery of the reply from structured output that didn't parse.
 *
 * The usual cause is the JSON being cut off after `reply` but before the
 * closing brace. The visitor's actual answer is sitting right there, so
 * returning it beats replacing a good answer with an outage message.
 * Only accepts a fully-quoted string — a reply truncated mid-sentence is not
 * worth showing.
 */
function chatbot_gemini_salvage(string $rawText): ?array {
    if (!preg_match('/"reply"\s*:\s*("(?:[^"\\\\]|\\\\.)*")/s', $rawText, $m)) {
        return null;
    }
    $reply = json_decode($m[1], true);
    if (!is_string($reply) || trim($reply) === '') {
        return null;
    }
    return ['reply' => $reply, 'intent' => 'general', 'program' => '', 'action' => 'none'];
}

/**
 * @param array $history [{role: 'user'|'model', text: string}, ...] oldest first
 * @return array{ok:bool, data:?array, http_code:int, error:?string}
 */
function chatbot_gemini_request(string $model, string $systemPrompt, array $history, string $userMessage, int $timeout = CHATBOT_GEMINI_TIMEOUT): array {
    $contents = [];
    foreach ($history as $m) {
        $role = ($m['role'] ?? 'user') === 'model' ? 'model' : 'user';
        $text = (string) ($m['text'] ?? '');
        if ($text === '') continue;
        $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

    $payload = [
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => $contents,
        'generationConfig' => chatbot_gemini_generation_config($model),
    ];

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . GEMINI_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlError) {
        return ['ok' => false, 'data' => null, 'http_code' => 0, 'error' => $curlError ?: 'curl failed'];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("chatbot: Gemini ($model) HTTP $httpCode: " . substr($body, 0, 500));
        return ['ok' => false, 'data' => null, 'http_code' => $httpCode, 'error' => "HTTP $httpCode"];
    }

    $decoded = json_decode($body, true);
    $rawText = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;

    // finishReason / blockReason turn "it just didn't work" into something the
    // error log can actually be searched for. MAX_TOKENS in particular means
    // the budget above needs raising, not that Gemini is down.
    $finishReason = $decoded['candidates'][0]['finishReason'] ?? null;
    $blockReason = $decoded['promptFeedback']['blockReason'] ?? null;

    if ($rawText === null) {
        $why = $blockReason ? "blocked ($blockReason)" : ($finishReason ? "finishReason $finishReason" : 'no reason given');
        error_log("chatbot: Gemini ($model) returned no text — $why: " . substr($body, 0, 500));
        return ['ok' => false, 'data' => null, 'http_code' => $httpCode, 'error' => 'no candidate text'];
    }

    $structured = json_decode($rawText, true);
    if (is_array($structured) && isset($structured['reply'])) {
        return ['ok' => true, 'data' => $structured, 'http_code' => $httpCode, 'error' => null];
    }

    // Structured output didn't parse. Before giving up on the turn, see if the
    // reply text itself survived — usually it did and only the tail was lost.
    $salvaged = chatbot_gemini_salvage($rawText);
    if ($salvaged !== null) {
        error_log("chatbot: Gemini ($model) structured output truncated (finishReason "
            . ($finishReason ?? 'none') . '); salvaged the reply text');
        return ['ok' => true, 'data' => $salvaged, 'http_code' => $httpCode, 'error' => null];
    }

    error_log("chatbot: Gemini ($model) structured output did not parse (finishReason "
        . ($finishReason ?? 'none') . '): ' . substr($rawText, 0, 500));
    return ['ok' => false, 'data' => null, 'http_code' => $httpCode, 'error' => 'malformed structured output'];
}

/**
 * Calls the primary model, retrying once against the fallback model when the
 * failure looks transient. Returns null if both attempts fail (quota
 * exhausted / outage), which the caller turns into the contact-form fallback.
 *
 * A safety block or a 4xx other than 429 is not retried — the same request
 * would fail the same way, and the visitor would just wait twice as long.
 */
function chatbot_call_gemini(string $systemPrompt, array $history, string $userMessage): ?array {
    $result = chatbot_gemini_request(GEMINI_MODEL, $systemPrompt, $history, $userMessage);
    if ($result['ok']) {
        return $result['data'];
    }

    $transport = $result['http_code'] === 429 || $result['http_code'] >= 500 || $result['http_code'] === 0;
    $badGeneration = $result['error'] === 'malformed structured output';
    if (!$transport && !$badGeneration) {
        return null;
    }

    $retry = chatbot_gemini_request(
        GEMINI_FALLBACK_MODEL, $systemPrompt, $history, $userMessage,
        CHATBOT_GEMINI_FALLBACK_TIMEOUT
    );
    return $retry['ok'] ? $retry['data'] : null;
}
