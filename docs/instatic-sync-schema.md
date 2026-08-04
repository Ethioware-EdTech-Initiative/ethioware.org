# Instatic Sync Schema — MySQL ↔ CMS Field Mapping

> **Purpose:** Documents the exact MySQL column definitions for tables that must
> be mirrored in the Instatic CMS data workspace. Used by P2-3 (Instatic data
> tables) and P3-2 (applications dual-write).
>
> **Source of truth:** `db/applications.sql`, `db/supporters.sql`, `db/chatbot.sql`,
> `research-scholars/save_signup.php`, `apply-submit.php`

---

## Table: `applications`

**Source:** [`db/applications.sql`](file:///home/sda1/Downloads2/Ethioware/db/applications.sql)
**PHP handler:** [`apply-submit.php`](file:///home/sda1/Downloads2/Ethioware/apply-submit.php)
**Instatic sync:** dual-write (P3-2) — after MySQL INSERT, POST to Instatic API
**Instatic access:** read-only in admin

| # | MySQL Column | MySQL Type | Nullable | Default | Instatic Field Type | Notes |
|---|-------------|------------|----------|---------|---------------------|-------|
| 1 | `id` | INT UNSIGNED AUTO_INCREMENT | NO | auto | number (auto) | Primary key |
| 2 | `full_name` | VARCHAR(150) | NO | — | text (max 150) | |
| 3 | `email` | VARCHAR(190) | NO | — | email (max 190) | Not unique (multi-apply) |
| 4 | `highschool` | VARCHAR(200) | NO | — | text (max 200) | |
| 5 | `citizenship` | VARCHAR(100) | NO | — | text (max 100) | |
| 6 | `program` | ENUM('Software Engineering Basics','Engineering Basics','Law Basics','Medicine Basics') | NO | — | select | 4 options |
| 7 | `grade` | ENUM('11','12','Graduated','University Freshman') | NO | — | select | 4 options |
| 8 | `gpa` | VARCHAR(40) | NO | — | text (max 40) | Free text: "95%" or "3.8" |
| 9 | `telegram` | VARCHAR(100) | NO | — | text (max 100) | |
| 10 | `where_heard` | ENUM('LinkedIn','Telegram','Friends (past learners)','Other') | NO | — | select | 4 options |
| 11 | `linkedin_follow` | ENUM('Yes','No') | NO | — | select | 2 options |
| 12 | `cohort` | VARCHAR(40) | NO | 'Aug' | text (max 40) | Hardcoded in PHP |
| 13 | `ip_address` | VARCHAR(45) | YES | NULL | text (max 45) | |
| 14 | `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | datetime (auto) | |
| 15 | `chat_ref` | CHAR(36) | YES | NULL | text (max 36) | Added by chatbot.sql ALTER |

**POST field names** (from `apply-submit.php`):
`full_name`, `email`, `highschool`, `citizenship`, `program`, `grade`, `gpa`,
`telegram`, `where_heard`, `linkedin_follow`, `chat_ref`
(Plus computed: `cohort`, `ip_address`, `created_at`)

---

## Table: `rsp_signups`

**Source:** `research-scholars/schema.sql` (referenced by `save_signup.php`)
**PHP handler:** [`research-scholars/save_signup.php`](file:///home/sda1/Downloads2/Ethioware/research-scholars/save_signup.php)
**Instatic sync:** CMS form replaces PHP endpoint (P2-4)
**Instatic access:** read-write via CMS form

| # | MySQL Column | MySQL Type | Nullable | Default | Instatic Field Type | Notes |
|---|-------------|------------|----------|---------|---------------------|-------|
| 1 | `id` | INT UNSIGNED AUTO_INCREMENT | NO | auto | number (auto) | Primary key |
| 2 | `full_name` | VARCHAR(150) | NO | — | text (max 150) | |
| 3 | `email` | VARCHAR(190) | NO | — | email (max 190) | Unique key |
| 4 | `phone` | VARCHAR(40) | YES | NULL | text (max 40) | |
| 5 | `age_grade` | VARCHAR(100) | NO | — | text (max 100) | Free text |
| 6 | `school_name` | VARCHAR(200) | NO | — | text (max 200) | |
| 7 | `school_location` | VARCHAR(150) | YES | NULL | text (max 150) | |
| 8 | `track_interest` | ENUM('STEM','Social Sciences','Not sure yet') | NO | — | select | 3 options |
| 9 | `research_interest` | TEXT | NO | — | textarea | Free text |
| 10 | `prior_research_experience` | ENUM('None','Some (school project)','Some (independent)','Significant') | NO | — | select | 4 options |
| 11 | `prior_research_detail` | TEXT | YES | NULL | textarea | |
| 12 | `english_comfort` | ENUM('Very comfortable','Somewhat comfortable','Need support') | NO | — | select | 3 options |
| 13 | `device_access` | ENUM('Smartphone only','Laptop/Desktop','Both') | NO | — | select | 3 options |
| 14 | `internet_reliability` | ENUM('Reliable daily','Intermittent','Limited/rare') | NO | — | select | 3 options |
| 15 | `weekly_time_commitment` | ENUM('Less than 3 hrs','3-6 hrs','6-10 hrs','10+ hrs') | NO | — | select | 4 options |
| 16 | `preferred_session_time` | ENUM('Weekday mornings','Weekday evenings','Weekends','Flexible/no preference') | NO | — | select | 4 options |
| 17 | `heard_about_us` | VARCHAR(150) | YES | NULL | text (max 150) | |
| 18 | `questions_comments` | TEXT | YES | NULL | textarea | |
| 19 | `consent_contact` | TINYINT(1) | NO | 0 | checkbox | |
| 20 | `ip_address` | VARCHAR(45) | YES | NULL | text (max 45) | |
| 21 | `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | datetime (auto) | |

**POST field names** (from `save_signup.php`):
`full_name`, `email`, `phone`, `age_grade`, `school_name`, `school_location`,
`track_interest`, `research_interest`, `prior_research_experience`,
`prior_research_detail`, `english_comfort`, `device_access`,
`internet_reliability`, `weekly_time_commitment`, `preferred_session_time`,
`heard_about_us`, `questions_comments`, `consent_contact`
(Plus computed: `ip_address`, `created_at`)

---

## Table: `supporters`

**Source:** [`db/supporters.sql`](file:///home/sda1/Downloads2/Ethioware/db/supporters.sql)
**PHP handler:** `support-submit.php`
**Instatic sync:** CMS form (future, not in v1 plan)

| # | MySQL Column | MySQL Type | Nullable | Default | Instatic Field Type | Notes |
|---|-------------|------------|----------|---------|---------------------|-------|
| 1 | `id` | INT UNSIGNED AUTO_INCREMENT | NO | auto | number (auto) | Primary key |
| 2 | `support_type` | ENUM('sponsor','volunteer') | NO | — | select | 2 options |
| 3 | `full_name` | VARCHAR(150) | NO | — | text (max 150) | |
| 4 | `email` | VARCHAR(190) | NO | — | email (max 190) | Not unique |
| 5 | `phone` | VARCHAR(40) | YES | NULL | text (max 40) | |
| 6 | `country` | VARCHAR(150) | NO | — | text (max 150) | |
| 7 | `sponsor_amount` | DECIMAL(10,2) | YES | NULL | number | Sponsor-only |
| 8 | `sponsor_frequency` | ENUM('one_time','monthly') | YES | NULL | select | Sponsor-only |
| 9 | `volunteer_track` | ENUM('stem','social','no_preference') | YES | NULL | select | Volunteer-only |
| 10 | `volunteer_link` | VARCHAR(300) | YES | NULL | url (max 300) | Volunteer-only |
| 11 | `volunteer_commitment` | TINYINT(1) | NO | 0 | checkbox | Volunteer-only |
| 12 | `message` | TEXT | YES | NULL | textarea | |
| 13 | `source` | VARCHAR(150) | YES | NULL | text (max 150) | e.g. "linkedin_campaign_aug2026" |
| 14 | `ip_address` | VARCHAR(45) | YES | NULL | text (max 45) | |
| 15 | `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | datetime (auto) | |

---

## Chatbot Tables (reference only)

**Source:** [`db/chatbot.sql`](file:///home/sda1/Downloads2/Ethioware/db/chatbot.sql)

These tables are **not** mirrored to Instatic in v1. Listed for completeness:

- `chatbot_leads` — 17 columns (session tracking, contact capture, intent classification)
- `chatbot_conversations` — 7 columns (session transcripts as JSON)
- `chatbot_events` — 7 columns (event stream: widget_open, message, gate_passed, etc.)

---

## Sync Architecture (P3-2)

```
┌──────────────┐     MySQL INSERT     ┌─────────────┐
│  apply.html  │ ──── POST ──────────→│ apply-      │
│  (browser)   │                      │ submit.php  │
└──────────────┘                      └──────┬──────┘
                                             │
                                    1. INSERT into MySQL
                                    2. On success: fire-and-forget
                                       POST to Instatic sync endpoint
                                             │
                                             ▼
                                      ┌──────────────┐
                                      │ Instatic API │
                                      │ (localhost:   │
                                      │  3001/3002)  │
                                      └──────────────┘
```

**Sync endpoint options** (in preference order):
1. **PHP cURL** to Instatic admin API with shared secret — inline in `apply-submit.php`
2. **Queue file** — write JSON to a spool directory; Bun cron picks it up
3. **Cron poll** — MySQL SELECT → Instatic data_rows upsert every 5 min

**Environment variables** (server-only, gitignored):
- `INSTATIC_SYNC_URL` — e.g. `http://127.0.0.1:3001/api/data/applications`
- `INSTATIC_SYNC_SECRET` — shared HMAC secret for authenticating sync requests
