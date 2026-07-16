<?php
/**
 * Chatbot DB + secrets bootstrap.
 *
 * Thin wrapper around research-scholars/config.php's rsp_get_connection() —
 * the chatbot uses the SAME MySQL database (new tables only, see
 * db/chatbot.sql), not a separate one. Also loads chatbot/config.php, the
 * chatbot's own gitignored secrets file (Gemini key, admin password hash).
 *
 * require_once'd by every chatbot/api and chatbot/admin script.
 */

$__sharedConfig = __DIR__ . '/../../research-scholars/config.php';
if (!is_file($__sharedConfig)) {
    error_log('chatbot/api/db.php: research-scholars/config.php missing');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['success' => false, 'message' => 'Server is not configured yet.']));
}
require_once $__sharedConfig;

$__chatbotConfig = __DIR__ . '/../config.php';
if (!is_file($__chatbotConfig)) {
    error_log('chatbot/api/db.php: chatbot/config.php missing');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['success' => false, 'message' => 'Chatbot is not configured yet.']));
}
require_once $__chatbotConfig;

/**
 * Get a mysqli connection to the shared database, using the same helper
 * apply-submit.php / support-submit.php use.
 */
function chatbot_db(): mysqli {
    return rsp_get_connection();
}
