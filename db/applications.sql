-- Ethioware pre-training applications schema.
--
-- Uses the SAME database as the Research Scholars signup (research-scholars/),
-- but a SEPARATE table. Import once into that database via phpMyAdmin (cPanel)
-- or:  mysql -u USER -p DBNAME < db/applications.sql
-- apply-submit.php inserts one row per submission.

CREATE TABLE IF NOT EXISTS applications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Applicant
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  highschool VARCHAR(200) NOT NULL,
  citizenship VARCHAR(100) NOT NULL,

  -- Program choice & background
  program ENUM('Software Engineering Basics','Engineering Basics','Law Basics','Medicine Basics') NOT NULL,
  grade ENUM('11','12','Graduated','University Freshman') NOT NULL,
  gpa VARCHAR(40) NOT NULL,                 -- free text: "95%" or "3.8"
  telegram VARCHAR(100) NOT NULL,

  -- Acquisition
  where_heard ENUM('LinkedIn','Telegram','Friends (past learners)','Other') NOT NULL,
  linkedin_follow ENUM('Yes','No') NOT NULL,

  -- Meta
  cohort VARCHAR(40) NOT NULL DEFAULT 'Aug',
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  -- Email is intentionally NOT unique: an applicant may apply to more than one
  -- program / cohort.
  INDEX idx_email (email),
  INDEX idx_program (program),
  INDEX idx_cohort (cohort),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
