<?php
/**
 * Ethioware Chatbot — Secrets Configuration (TEMPLATE)
 *
 * Copy this file to `chatbot/config.php` on the server and fill in real
 * values. `chatbot/config.php` is gitignored (the repo's `config.php` rule
 * matches at any depth) and excluded from deploys via .deployignore — it is
 * never committed and never overwritten by a deploy.
 *
 * Get a free Gemini API key at: https://aistudio.google.com/apikey
 */

// ---- EDIT THESE VALUES ----

// Gemini API key (free tier). Required.
define('GEMINI_API_KEY', 'REPLACE_ME');

// Primary + fallback model. Config-only switch — no code change needed to
// change models. See CHATBOT_SPEC.md §4.1.
define('GEMINI_MODEL', 'gemini-2.5-flash');
define('GEMINI_FALLBACK_MODEL', 'gemini-2.5-flash-lite');

// Admin dashboard password hash. Generate with:
//   php -r "echo password_hash('your-password-here', PASSWORD_DEFAULT), PHP_EOL;"
define('ADMIN_PASSWORD_HASH', 'REPLACE_ME');

// ---------------------------------
