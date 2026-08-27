# Ethioware Scripts

Utility scripts for the Ethioware site. These are **not** served to the public
(excluded by `.deployignore` if you add the entry, or deployed to a non-web
path).

---

## export-chatbot-knowledge.sh

**Purpose:** Pulls chatbot knowledge entries from the Instatic CMS and writes
them as markdown files to `chatbot/knowledge/`.

**When to run:**
- Manually after editing chatbot knowledge in the CMS admin
- Via cron every 15 minutes (see below)

### Chatbot Knowledge File Format

Each file in `chatbot/knowledge/` follows this convention:

```markdown
# Title of the Knowledge Section

## Subheading

Body text in plain markdown. Use `## Heading` for sub-sections.
The chatbot backend reads all *.md files in this directory at runtime.
```

**Naming:** Files are named `{slug}.md` where the slug comes from the CMS.
Existing files use a numeric prefix for ordering (e.g., `00-org.md`,
`10-programs.md`, `90-faq.md`).

### Environment Variables

| Variable | Required | Example | Description |
|----------|----------|---------|-------------|
| `INSTATIC_API_URL` | Yes | `http://127.0.0.1:3001/api/data/chatbot_knowledge` | Instatic data API endpoint |
| `INSTATIC_API_KEY` | Yes | *(secret)* | Read-only API key for the CMS |
| `KNOWLEDGE_DIR` | No | `/home/ethiowzj/public_html/chatbot/knowledge` | Override default output directory |

### Cron Setup (on VPS)

```bash
# Add to crontab -e:
*/15 * * * * INSTATIC_API_URL=http://127.0.0.1:3001/api/data/chatbot_knowledge \
  INSTATIC_API_KEY=your-key-here \
  /home/ethiowzj/public_html/scripts/export-chatbot-knowledge.sh \
  >> ~/logs/knowledge-export.log 2>&1
```

---

## Instatic Sync (apply-submit.php dual-write)

The `apply-submit.php` handler includes an inline sync to the Instatic CMS
(P3-2). It is **not** a separate script — it runs as part of the form
submission flow.

### Environment Variables (set on server only)

| Variable | Required | Example | Description |
|----------|----------|---------|-------------|
| `INSTATIC_SYNC_URL` | Yes | `http://127.0.0.1:3001/api/data/applications` | Instatic data API for applications |
| `INSTATIC_SYNC_SECRET` | Yes | *(secret)* | HMAC-SHA256 signing key |

These must be set as environment variables in the server's PHP configuration
(e.g., via `.htaccess`, cPanel PHP selector, or `php.ini`). They are **never**
committed to the repository.

---

## setup-instatic-prod.sh *(P4-1 — one-time)*

**Purpose:** Sets up the production Instatic CMS instance on the VPS. Clones
the repo, installs dependencies, builds, generates `.env`, and verifies.

**When to run:** Once, during initial production deployment. Not deployed to
the web server (excluded by `.deployignore`).

```bash
chmod +x scripts/setup-instatic-prod.sh
./scripts/setup-instatic-prod.sh
```

---

## start-instatic-prod.sh *(process manager)*

**Purpose:** Starts (or restarts) the production Instatic server on port 3001.
Handles PID tracking to prevent duplicate processes.

**Cron setup:**
```bash
@reboot sleep 30 && nohup /home/ethiowzj/public_html/scripts/start-instatic-prod.sh &
```

---

## migrate-certificates.sh *(P4-2)*

**Purpose:** Bulk-migrates all 235 certificate pages from static HTML into the
Instatic CMS. Parses each certificate's HTML to extract the code, image path,
work-log iframe URL, and partner logos, then creates corresponding pages via
the Instatic API.

**Usage:**
```bash
# Preview without making changes:
./scripts/migrate-certificates.sh --dry-run

# Migrate first 5 for testing:
INSTATIC_API_URL=http://127.0.0.1:3001 \
INSTATIC_API_KEY=your-key \
./scripts/migrate-certificates.sh --limit 5 --verbose

# Full migration:
INSTATIC_API_URL=http://127.0.0.1:3001 \
INSTATIC_API_KEY=your-key \
./scripts/migrate-certificates.sh
```

The script is **idempotent** — it skips certificates that already exist in the CMS.

---

## backup-instatic.sh *(P4-5)*

**Purpose:** Daily backup of Instatic SQLite databases, uploads, and MySQL
application data. Retains 7 days of backups.

**Cron setup:**
```bash
0 3 * * * /home/ethiowzj/public_html/scripts/backup-instatic.sh >> ~/logs/backup.log 2>&1
```

**What it backs up:**
- `~/instatic/storage/cms.db` + `uploads/` + `.env` (production)
- `~/instatic-staging/storage/cms.db` + `uploads/` (staging)
- MySQL tables: `applications`, `rsp_signups`, `chatbot_*`, `supporters`

---

## acceptance-test.sh *(P5-1)*

**Purpose:** Post-cutover smoke test suite. Verifies homepage, certificate URLs,
apply form, chatbot API, CMS admin, asset loading, and checks for removed
Microsoft Forms embeds.

**Usage:**
```bash
# Test against production:
./scripts/acceptance-test.sh

# Test against staging:
./scripts/acceptance-test.sh https://staging.ethioware.org
```

Outputs a pass/fail summary and a manual verification checklist for items
that require human interaction (CMS role permissions, chatbot knowledge
updates, etc.).

---

## cloudflared-config-prod.yml *(P4-3 — template)*

**Purpose:** Production Cloudflare tunnel ingress configuration template.
Copy to `~/.cloudflared/config.yml` on the VPS, replacing `<TUNNEL-UUID>`
with your actual tunnel UUID.

Not deployed to the web server (excluded by `.deployignore`).

---

## All Server Environment Variables (summary)

| Variable | Used by | Purpose |
|----------|---------|---------|
| `INSTATIC_SYNC_URL` | apply-submit.php | Dual-write applications to CMS |
| `INSTATIC_SYNC_SECRET` | apply-submit.php | HMAC signing for sync requests |
| `INSTATIC_API_URL` | export-chatbot-knowledge.sh | CMS data API endpoint |
| `INSTATIC_API_KEY` | export-chatbot-knowledge.sh, migrate-certificates.sh | API authentication |
| `ETHIOWARE_RSP_DEPRECATED` | save_signup.php | Toggle RSP PHP deprecation (410 Gone) |
| `KNOWLEDGE_DIR` | export-chatbot-knowledge.sh | Override knowledge output dir |

All variables are **server-only** — never committed to the repository.
