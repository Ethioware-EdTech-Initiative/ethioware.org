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
