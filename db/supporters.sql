-- Ethioware Research Scholars — Supporter registrations schema.
--
-- Uses the SAME database as the Research Scholars signup (research-scholars/)
-- and the pre-training applications (applications table), but a SEPARATE
-- table. Import once into that database via phpMyAdmin (cPanel) or:
--   mysql -u USER -p DBNAME < db/supporters.sql
-- support-submit.php inserts one row per submission.

CREATE TABLE IF NOT EXISTS supporters (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Which flow they submitted
  support_type ENUM('sponsor','volunteer') NOT NULL,

  -- Shared contact fields
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NULL,
  country VARCHAR(150) NOT NULL,

  -- Sponsor-only fields (NULL when support_type = 'volunteer')
  sponsor_amount DECIMAL(10,2) NULL,
  sponsor_frequency ENUM('one_time','monthly') NULL,

  -- Volunteer-only fields (NULL/0 when support_type = 'sponsor')
  volunteer_track ENUM('stem','social','no_preference') NULL,
  volunteer_link VARCHAR(300) NULL,
  volunteer_commitment TINYINT(1) NOT NULL DEFAULT 0,

  -- Shared optional fields
  message TEXT NULL,
  source VARCHAR(150) NULL,          -- e.g. "linkedin_campaign_aug2026"

  -- Meta
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  -- Email is intentionally NOT unique: the same person may sponsor again or
  -- apply to volunteer after already sponsoring.
  INDEX idx_email (email),
  INDEX idx_support_type (support_type),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
