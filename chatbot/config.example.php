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

// ---- Admin dashboard sign-in ----
//
// Preferred: Clerk (per-user staff accounts, so chatbot_events records WHO
// logged in and access is revoked in Clerk instead of by rotating a shared
// secret). Full setup: docs/chatbot-admin-clerk.md.
//
// Publishable key from the Clerk dashboard (API Keys). This one value also
// determines the token issuer, the JWKS endpoint and the clerk-js origin, so
// nothing else needs configuring for verification. Leave blank to keep the
// original shared-password login as the only way in.
define('CLERK_PUBLISHABLE_KEY', '');

// Secret key (sk_test_… / sk_live_…). Only used to look up the signed-in
// user's e-mail address, which Clerk's default session token does not carry.
// Optional if you add an `email` claim to the session token instead — see
// CLERK_JWT_TEMPLATE below.
define('CLERK_SECRET_KEY', '');

// Who may open the dashboard. FAILS CLOSED: with both lists empty, every
// Clerk account is rejected — an account on your Clerk instance is not by
// itself permission to read every lead's contact details.
define('CLERK_ALLOWED_EMAILS', '');            // e.g. 'ada@ethioware.org, sam@ethioware.org'
define('CLERK_ALLOWED_DOMAINS', 'ethioware.org'); // e.g. 'ethioware.org'

// Optional. Name of a Clerk JWT template that includes the user's e-mail
// (claim: {"email": "{{user.primary_email_address}}"}). Set this to avoid the
// Backend API lookup — then CLERK_SECRET_KEY is not needed at all.
define('CLERK_JWT_TEMPLATE', '');

// Optional. Extra origins allowed in a token's `azp` claim, beyond the origin
// serving this page. Only needed if the dashboard is reached on more than one
// hostname, e.g. 'https://www.ethioware.org'.
define('CLERK_AUTHORIZED_PARTIES', '');

// Fallback: the original single shared password (CHATBOT_SPEC.md §8).
// Generate the hash with:
//   php -r "echo password_hash('your-password-here', PASSWORD_DEFAULT), PHP_EOL;"
// Keep it while rolling Clerk out — it is the break-glass path if a key is
// wrong or Clerk is unreachable. Once Clerk is verified live, either set
// CHATBOT_ADMIN_PASSWORD_FALLBACK to false or blank the hash.
define('ADMIN_PASSWORD_HASH', 'REPLACE_ME');
define('CHATBOT_ADMIN_PASSWORD_FALLBACK', true);

// ---------------------------------
