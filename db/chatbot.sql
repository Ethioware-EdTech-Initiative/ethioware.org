-- Ethioware chatbot schema.
--
-- Uses the SAME database as the Research Scholars signup / applications
-- (research-scholars/), but SEPARATE tables. Import once into that database
-- via phpMyAdmin (cPanel) or:  mysql -u USER -p DBNAME < db/chatbot.sql
--
-- See CHATBOT_SPEC.md §3 for the design rationale (why MySQL, not MongoDB).

-- Chatbot leads: one row per chat session that yielded ANY lead signal.
CREATE TABLE IF NOT EXISTS chatbot_leads (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id CHAR(36) NOT NULL,              -- client-generated UUID v4
  referral_token CHAR(36) NULL,              -- set when bot hands off to apply.html
  source ENUM('chatbot','chatbot_apply') NOT NULL DEFAULT 'chatbot',
  status ENUM('chat_only','gated_captured','referred','apply_started','apply_completed')
    NOT NULL DEFAULT 'chat_only',
  -- Contact (filled progressively; email is the gate minimum alongside name)
  name VARCHAR(150) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  organization VARCHAR(150) NULL,
  -- Classification
  intent ENUM('general','enrollment','partnership','pricing','donation',
              'investment','support','other') NOT NULL DEFAULT 'general',
  program_interest VARCHAR(80) NULL,         -- free text from a controlled list, see §7
  -- Gate state (server-side; the client can never forge this)
  gate_state ENUM('none','locked','passed') NOT NULL DEFAULT 'none',
  -- Meta
  page_first_seen VARCHAR(255) NULL,         -- path where the widget was opened
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_session (session_id),
  KEY idx_referral (referral_token),
  KEY idx_status (status),
  KEY idx_intent (intent),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Full transcripts: one row per session; messages appended as a JSON array.
CREATE TABLE IF NOT EXISTS chatbot_conversations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id CHAR(36) NOT NULL,
  messages LONGTEXT NOT NULL,                -- JSON: [{"role":"user|model","text":"...","t":unixtime}, ...]
  message_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ip_address VARCHAR(45) NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_message_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_session (session_id),
  KEY idx_last (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Event stream: powers abandonment detection, rate limiting, and dashboard stats.
CREATE TABLE IF NOT EXISTS chatbot_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id CHAR(36) NULL,                  -- null for apply-page events matched by token
  referral_token CHAR(36) NULL,
  ip_address VARCHAR(45) NULL,               -- set on message/login events for rate limiting
  event ENUM('widget_open','greeted','message','gate_shown','gate_passed','referred',
             'apply_started','apply_step','apply_completed','quota_fallback',
             'admin_login_ok','admin_login_fail')
    NOT NULL,
  detail VARCHAR(255) NULL,                  -- e.g. step number, program name
  page VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_token (referral_token),
  KEY idx_event_time (event, created_at),
  KEY idx_ip_event_time (ip_address, event, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Link a completed application back to the chat session that referred it.
-- NOTE: run this ALTER only once. If db/applications.sql was already imported
-- and this ALTER previously applied, re-running it will error on the
-- duplicate column/key — that's expected and safe to ignore.
ALTER TABLE applications
  ADD COLUMN chat_ref CHAR(36) NULL AFTER ip_address,
  ADD KEY idx_chat_ref (chat_ref);
