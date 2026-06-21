-- EthioWare Research Scholars Program — Early Signup Schema
-- Import this once via phpMyAdmin (cPanel) or `mysql -u USER -p DBNAME < schema.sql`

CREATE TABLE IF NOT EXISTS rsp_signups (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Basic info
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NULL,
  age_grade VARCHAR(20) NOT NULL,           -- e.g. "Grade 10", "Grade 11", "Grade 12"

  -- School
  school_name VARCHAR(200) NOT NULL,
  school_location VARCHAR(150) NULL,        -- city/region, helps gauge regional reach

  -- Research interest
  track_interest ENUM('STEM','Social Sciences','Not sure yet') NOT NULL,
  research_interest TEXT NOT NULL,          -- free text: topic/question they're curious about
  prior_research_experience ENUM('None','Some (school project)','Some (independent)','Significant') NOT NULL,
  prior_research_detail TEXT NULL,          -- optional elaboration

  -- Program-shaping questions
  english_comfort ENUM('Very comfortable','Somewhat comfortable','Need support') NOT NULL,
  device_access ENUM('Smartphone only','Laptop/Desktop','Both') NOT NULL,
  internet_reliability ENUM('Reliable daily','Intermittent','Limited/rare') NOT NULL,
  weekly_time_commitment ENUM('Less than 3 hrs','3-6 hrs','6-10 hrs','10+ hrs') NOT NULL,
  preferred_session_time ENUM('Weekday mornings','Weekday evenings','Weekends','Flexible/no preference') NOT NULL,
  heard_about_us VARCHAR(150) NULL,         -- referral source

  -- Open feedback that shapes the program
  questions_comments TEXT NULL,

  -- Meta
  consent_contact TINYINT(1) NOT NULL DEFAULT 0,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_email (email),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
