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

/**
 * @param array $history [{role: 'user'|'model', text: string}, ...] oldest first
 * @return array{ok:bool, data:?array, http_code:int, error:?string}
 */
function chatbot_gemini_request(string $model, string $systemPrompt, array $history, string $userMessage): array {
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
        'generationConfig' => [
            'temperature' => 0.4,
            'maxOutputTokens' => 512,
            'responseMimeType' => 'application/json',
            'responseSchema' => CHATBOT_GEMINI_RESPONSE_SCHEMA,
        ],
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
        CURLOPT_TIMEOUT => 20,
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
    if ($rawText === null) {
        error_log("chatbot: Gemini ($model) response missing candidates text: " . substr($body, 0, 500));
        return ['ok' => false, 'data' => null, 'http_code' => $httpCode, 'error' => 'no candidate text'];
    }

    $structured = json_decode($rawText, true);
    if (!is_array($structured) || !isset($structured['reply'])) {
        error_log("chatbot: Gemini ($model) structured output did not parse: " . substr($rawText, 0, 500));
        return ['ok' => false, 'data' => null, 'http_code' => $httpCode, 'error' => 'malformed structured output'];
    }

    return ['ok' => true, 'data' => $structured, 'http_code' => $httpCode, 'error' => null];
}

/**
 * Calls the primary model, retrying once against the fallback model on a
 * 429 or 5xx. Returns null if both attempts fail (quota-exhausted / outage).
 */
function chatbot_call_gemini(string $systemPrompt, array $history, string $userMessage): ?array {
    $result = chatbot_gemini_request(GEMINI_MODEL, $systemPrompt, $history, $userMessage);
    if ($result['ok']) {
        return $result['data'];
    }
    if ($result['http_code'] === 429 || $result['http_code'] >= 500 || $result['http_code'] === 0) {
        $retry = chatbot_gemini_request(GEMINI_FALLBACK_MODEL, $systemPrompt, $history, $userMessage);
        if ($retry['ok']) {
            return $retry['data'];
        }
    }
    return null;
}
