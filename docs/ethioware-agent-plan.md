# Ethioware × Instatic — LLM Agent Implementation Plan

> **Audience:** Coding agents (Cursor, Codex, Claude Code, etc.) implementing Ethioware website changes and related integration wiring.
> **Human operator docs:** See conversation summary + `context.md` for product background.
> **Instatic repo:** `CoreBunch/Instatic` (this repo) — CMS server, admin, publisher.
> **Ethioware repo:** `Ethioware-EdTech-Initiative/ethioware.org` — static site + PHP + chatbot.
> **Last updated:** 2026-08-04

---

## META

```yaml
project: ethioware-instatic-v1
deadline_weeks: 2
hosting_model: bun_on_vps + cloudflare_tunnel  # Option A
vps_user: ethiowzj
vps_host: 192.250.239.60
document_root: /home/ethiowzj/public_html
staging_static_path: /home/ethiowzj/staging  # legacy; may coexist until cutover

domains:
  production: https://ethioware.org
  www: https://www.ethioware.org
  staging: https://staging.ethioware.org
  cms_admin: https://cms.ethioware.org

instatic_ports:
  production: 3001
  staging: 3002

localhost_services:
  instatic_prod: http://127.0.0.1:3001
  instatic_staging: http://127.0.0.1:3002
  cpanel_apache: http://127.0.0.1:80

confirmed_vps:
  ssh: true
  bun: ~/.bun/bin/bun  # 1.3.14+
  docker: false
  root: false
  persistent_nohup: true
  bind_localhost: true
```

---

## HARD CONSTRAINTS — DO NOT VIOLATE

```yaml
must:
  - Certificate public URLs: https://ethioware.org/<CODE> where CODE is alphanumeric only
  - Marketing staff edit content without touching raw HTML files post-cutover
  - apply-submit.php keeps working on MySQL through cutover (PHP stays v1 backend)
  - Application data visible in Instatic admin (dual-write or sync)
  - Chatbot widget on every public page
  - Chatbot knowledge editable in Instatic; PHP chatbot reads exported markdown
  - GitHub Actions rsync deploy continues for PHP/chatbot/export paths
  - Audit log enabled; MFA not required day 1
  - No new paid hosting services (use existing VPS + Cloudflare)

must_not:
  - Assume Docker, root, or Apache reverse proxy availability
  - Run Instatic on Node (requires Bun)
  - Break existing MySQL schemas for applications or rsp_signups
  - Remove certificate URLs or add /certificates/ prefix
  - Commit secrets (.env, DB passwords, API keys, INSTATIC_SECRET_KEY)
  - Push Instatic admin credentials to ethioware.org repo
  - Guess file paths — verify in repo before editing

acceptable:
  - Split homepage into multiple pages (/about, /mission, etc.)
  - Remove Microsoft Forms embeds; link to /apply instead
  - Migrate EmailJS contact + RSP PHP forms to Instatic CMS forms (after parity testing)
```

---

## REPO MAP — ethioware.org

```yaml
# Paths relative to repo root (= public_html on server)

marketing_pages:
  - index.html           # ~2172 lines; SPA-style nav via assets/js/main.js
  - apply.html           # posts to apply-submit.php
  - pay.html
  - privacy.html
  - support.html
  - anteneh.html
  - biniyam.html
  - samuel.html

certificates:
  location: public_html root AND/OR certificates/  # verify; live URLs have NO prefix
  pattern: "<CODE>.html"                           # e.g. WI10092516.html
  count: ~235
  image_assets: assets/img/<CODE>.webp

assets:
  css: assets/css/styles.css      # compiled from assets/scss/
  js:
    - assets/js/main.js           # homepage section routing + pushState
    - assets/js/script.js
    - assets/js/bio.js
    - assets/js/sendmail.js       # EmailJS — TO BE REPLACED by CMS form
    - assets/js/chatbot.js        # KEEP; inject via Instatic everywhere template
  scss: assets/scss/              # compile locally; commit CSS (no CI build)

php_endpoints:
  - apply-submit.php              # KEEP v1; dual-write to Instatic
  - research-scholars/save_signup.php  # MIGRATE to CMS form; then deprecate
  - chatbot/api/chat.php
  - chatbot/api/track.php
  - chatbot/admin/*.php

chatbot:
  knowledge: chatbot/knowledge/*.md   # EXPORT TARGET from Instatic
  api: chatbot/api/
  admin: chatbot/admin/

mysql:
  config: research-scholars/config.php  # gitignored on server; rsp_get_connection()
  schemas: db/                          # not deployed; use for field mapping
  tables:
    - applications
    - rsp_signups
    - chatbot_leads
    - chatbot_conversations
    - chatbot_events

deploy:
  ci: ci/
  primary_html_lint: ci/primary-html.txt
  method: GitHub Actions rsync over SSH (no --delete)

server_config:
  htaccess: public_html/.htaccess     # RewriteEngine; .html extension rules
```

---

## REPO MAP — Instatic (this repo)

```yaml
install_path_staging: /home/ethiowzj/instatic-staging
install_path_prod: /home/ethiowzj/instatic

entrypoints:
  server: server/index.ts
  start_script: scripts/start.ts      # bun run start — builds + serves
  site_import: src/core/siteImport/   # Super Import pipeline
  publisher: server/publish/publicRouter.ts
  cms_forms: server/forms/handler.ts
  audit: server/repositories/audit.ts

docs:
  deployment: docs/deployment/README.md
  site_import: docs/features/site-import.md
  templates: docs/features/templates.md
  cms_forms: docs/features/cms-native-forms.md
  content_storage: docs/features/content-storage.md
```

---

## ARCHITECTURE — TRAFFIC FLOW

```text
Cloudflare DNS
  → cloudflared tunnel (~/.cloudflared/config.yml)
    → staging.ethioware.org  → 127.0.0.1:3002  (Instatic staging)
    → cms.ethioware.org      → 127.0.0.1:3001  (Instatic prod admin)
    → ethioware.org (phase 2):
         /apply-submit.php, /chatbot/*, /cognify/*  → 127.0.0.1:80 (Apache)
         /* (default)                                 → 127.0.0.1:3001 (Instatic)
```

---

## PHASE INDEX

| Phase | ID | Goal | Primary repo |
|-------|-----|------|--------------|
| 0 | P0 | VPS infra: cloudflared + Instatic staging | VPS (ops) |
| 1 | P1 | Import site into Instatic staging | Instatic |
| 2 | P2 | Content model + marketing workflows | Instatic |
| 3 | P3 | Ethioware repo changes for integration | ethioware.org |
| 4 | P4 | Production + tunnel cutover | VPS + Cloudflare |
| 5 | P5 | Staff runbook + verification | Both |

**Dependency graph:** P0 → P1 → P2 → P3 → P4 → P5

---

## P0 — VPS INFRASTRUCTURE

### TASK P0-1: Install cloudflared

```yaml
id: P0-1
repo: VPS (not git)
depends_on: []
run_on: VPS as ethiowzj
commands:
  - mkdir -p ~/bin ~/.cloudflared ~/logs
  - curl -L https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64 -o ~/bin/cloudflared
  - chmod +x ~/bin/cloudflared
  - ~/bin/cloudflared tunnel login
  - ~/bin/cloudflared tunnel create ethioware-instatic
acceptance:
  - ~/.cloudflared/<UUID>.json exists
  - cloudflared tunnel list shows ethioware-instatic
```

### TASK P0-2: Install Instatic staging

```yaml
id: P0-2
repo: Instatic
depends_on: []
run_on: VPS
commands:
  - git clone https://github.com/CoreBunch/Instatic.git ~/instatic-staging
  - cd ~/instatic-staging && ~/.bun/bin/bun install && ~/.bun/bin/bun run build
  - mkdir -p ~/instatic-staging/storage ~/instatic-staging/uploads
  - ~/.bun/bin/bun run scripts/generate-secret-key.ts  # save output securely
files_create:
  - path: /home/ethiowzj/instatic-staging/.env
    chmod: "600"
    template: |
      PORT=3002
      DATABASE_URL=sqlite:/home/ethiowzj/instatic-staging/storage/cms.db
      UPLOADS_DIR=/home/ethiowzj/instatic-staging/uploads
      STATIC_DIR=/home/ethiowzj/instatic-staging/dist
      INSTATIC_SECRET_KEY=<FROM generate-secret-key>
      PUBLIC_ORIGIN=https://staging.ethioware.org,https://cms.ethioware.org
acceptance:
  - curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:3002/admin/setup → 200 or redirect
```

### TASK P0-3: Tunnel config phase 1

```yaml
id: P0-3
depends_on: [P0-1, P0-2]
files_create:
  - path: /home/ethiowzj/.cloudflared/config.yml
    content: |
      tunnel: <TUNNEL-UUID>
      credentials-file: /home/ethiowzj/.cloudflared/<TUNNEL-UUID>.json
      ingress:
        - hostname: staging.ethioware.org
          service: http://127.0.0.1:3002
        - hostname: cms.ethioware.org
          service: http://127.0.0.1:3001
        - service: http_status:404
cloudflare_dns:
  - staging.ethioware.org → tunnel
  - cms.ethioware.org → tunnel
acceptance:
  - https://staging.ethioware.org loads Instatic setup wizard
```

### TASK P0-4: Process persistence

```yaml
id: P0-4
depends_on: [P0-2, P0-3]
files_create:
  - path: /home/ethiowzj/bin/start-instatic-staging.sh
  - path: /home/ethiowzj/bin/start-cloudflared.sh
crontab: |
  @reboot sleep 30 && nohup /home/ethiowzj/bin/start-instatic-staging.sh &
  @reboot sleep 60 && nohup /home/ethiowzj/bin/start-cloudflared.sh &
acceptance:
  - Process survives SSH logout (verify with ps + curl)
  - Logs append to ~/logs/
```

---

## P1 — SITE IMPORT (INSTATIC STAGING)

### TASK P1-1: Complete Instatic setup wizard

```yaml
id: P1-1
depends_on: [P0-2]
run_in: browser at https://staging.ethioware.org/admin/setup
actions:
  - Create owner account (not shared with marketing)
  - Create site name: Ethioware Staging
acceptance:
  - Owner can log in at staging /admin
```

### TASK P1-2: Super Import static site bundle

```yaml
id: P1-2
depends_on: [P1-1]
run_in: Instatic admin → Site Import (Spotlight: "Import")
input_files_from_ethioware_repo:
  - index.html
  - apply.html
  - pay.html
  - privacy.html
  - support.html
  - anteneh.html, biniyam.html, samuel.html
  - assets/css/styles.css
  - assets/css/bio.css
  - assets/js/main.js, script.js, bio.js, chatbot.js
  - assets/img/** (or upload via Media after import)
import_options:
  - Review stylesheet modes per linked CSS file
  - Resolve slug conflicts (prefer overwrite for staging)
  - Import pages; keep scripts as page-scoped where needed
known_warnings:
  - main.js pushState section routing may not survive import verbatim — fix in P2-1
  - Laravel csrf meta placeholders in index.html/support.html — remove during cleanup
acceptance:
  - Homepage renders on staging with acceptable visual parity
  - Team bios render
```

### TASK P1-3: Import sample certificates

```yaml
id: P1-3
depends_on: [P1-2]
strategy: |
  Do NOT bulk-import 235 certs in first pass.
  Import 3 representative certificate HTML files as pages.
  Validate root slug routing: /WI10092516 not /certificates/WI10092516
sample_codes: # pick 3 existing codes from public_html
  - WI10092516
  - AM2007261
  - WI1009259
acceptance:
  - https://staging.ethioware.org/<CODE> returns 200 after publish
```

---

## P2 — CONTENT MODEL & INSTATIC CONFIG

### TASK P2-1: Homepage split + navigation

```yaml
id: P2-1
depends_on: [P1-2]
repo: Instatic (Site workspace)
actions:
  - Optional: split index sections into pages /about, /mission, /contact, etc.
  - Replace main.js pushState routing with normal links OR reattach script on homepage only
  - Remove invalid meta: <meta name="csrf-token" content="{{ csrf_token() }}" />
acceptance:
  - All nav targets resolve on staging
  - No Laravel placeholder tokens in published HTML
```

### TASK P2-2: Certificate template page

```yaml
id: P2-2
depends_on: [P1-3]
repo: Instatic
actions:
  - Create master page slug: _certificate-template (or hidden template)
  - Fields via page tree: certificate image, work-log iframe, logo carousel, title/meta
  - Document duplicate workflow for marketing (see P5-1)
slug_rule: |
  Published certificate page slug MUST equal certificate CODE (alphanumeric).
  URL: /{slug} — single segment page route (NOT data_rows collection route).
acceptance:
  - Marketing can duplicate template → set slug → swap image → publish in <10 min
```

### TASK P2-3: Data tables (Content workspace)

```yaml
id: P2-3
depends_on: [P1-1]
repo: Instatic (Data workspace)
tables:
  - name: announcements
    fields: [slug, title, body, published_at, pinned]
    route_base: /announcements
  - name: site_flags
    fields: [registrations_open (boolean), banner_text, banner_link]
    route_base: ""  # admin-only; bind to homepage via dynamic or manual edit
  - name: chatbot_knowledge
    fields: [slug, title, body (markdown)]
    route_base: ""  # not public; export only
  - name: applications
    fields: []  # MIRROR MySQL applications columns exactly — load schema from ethioware db/
    route_base: ""
    read_only_in_admin: true
  - name: contact_submissions
    fields: []  # define when migrating contact form
  - name: rsp_signups
    fields: []  # MIRROR MySQL rsp_signups — load schema from ethioware db/
acceptance:
  - Tables visible in Content/Data workspace
  - applications columns match MySQL 1:1
```

### TASK P2-4: CMS-native forms

```yaml
id: P2-4
depends_on: [P2-3]
repo: Instatic (Site workspace)
forms:
  contact:
    replaces: assets/js/sendmail.js (EmailJS)
    target_table: contact_submissions
    page: homepage contact section OR /contact
  rsp_signup:
    replaces: research-scholars/save_signup.php
    target_table: rsp_signups
    page: support.html equivalent
acceptance:
  - POST /_instatic/form/submit succeeds on staging
  - Submissions appear in Data workspace
  - Field names/types match existing MySQL schema
```

### TASK P2-5: Remove Microsoft Forms

```yaml
id: P2-5
depends_on: [P2-1]
repo: Instatic (Site workspace)
actions:
  - Remove Microsoft Forms iframes from #train section
  - Replace with CTA buttons linking to /apply
  - Update footer links (membership, interns, trainees) → /apply or specific anchors
acceptance:
  - No microsoft.com/forms embeds in published HTML
  - Apply page linked from program section
```

### TASK P2-6: Everywhere template + chatbot embed

```yaml
id: P2-6
depends_on: [P1-2]
repo: Instatic
actions:
  - Create template target: everywhere
  - Inject in head/body-end:
      - assets/js/chatbot.js (or equivalent URL after publish)
      - chatbot.css
      - chatbot widget DOM mount point matching existing chatbot.js expectations
  - Ensure chatbot API calls use absolute path /chatbot/api/ (served by Apache in phase 2)
acceptance:
  - Chatbot widget visible on all staging pages
  - chat.php reachable (may require tunnel path rule in P4)
```

### TASK P2-7: Marketing role

```yaml
id: P2-7
depends_on: [P1-1]
repo: Instatic admin → Users → Roles
role_name: Marketing
capabilities:
  grant:
    - dashboard.read
    - site.read
    - site.content.edit
    - pages.publish
    - content.create
    - content.edit.any
    - content.publish.any
    - media.read
    - media.upload
    - audit.read
  deny:
    - site.structure.edit
    - users.manage
    - plugins.install
    - data.custom.tables.manage
acceptance:
  - Test user with Marketing role can publish cert but cannot delete pages
```

---

## P3 — ETHIOWARE.ORG REPO CHANGES

> Agent working in `Ethioware-EdTech-Initiative/ethioware.org` makes these changes.
> Deploy to staging branch first; rsync to server staging path if still used pre-cutover.

### TASK P3-1: MySQL schema export for sync

```yaml
id: P3-1
depends_on: []
repo: ethioware.org
files_read:
  - db/*.sql
  - apply-submit.php
  - research-scholars/save_signup.php
deliverable:
  - docs/instatic-sync-schema.md (in ethioware repo OR instatic plan)
    listing every applications + rsp_signups column with types
acceptance:
  - P2-3 applications/rsp_signups tables can be created to match
```

### TASK P3-2: Applications dual-write

```yaml
id: P3-2
depends_on: [P2-3, P3-1]
repo: ethioware.org
file_edit:
  - apply-submit.php
logic: |
  AFTER successful MySQL INSERT:
  1. POST normalized payload to internal sync endpoint
  2. On sync failure: log error; do NOT fail user submission
sync_endpoint_options:
  preferred: PHP curl to Instatic admin API (if exposed) — likely NOT public
  recommended: |
    Create scripts/sync-application-to-instatic.php (CLI/cron) OR
    apply-submit.php writes to a queue file OR
    shared secret POST to a small Bun script on localhost:3001
  fallback: cron every 5 min: MySQL SELECT → Instatic data_rows upsert
env_vars_add: # server-only, gitignored
  - INSTATIC_SYNC_URL
  - INSTATIC_SYNC_SECRET
acceptance:
  - New application in MySQL appears in Instatic applications table within 5 min max
  - apply-submit.php response unchanged for frontend
```

### TASK P3-3: Chatbot knowledge export receiver

```yaml
id: P3-3
depends_on: [P2-3]
repo: ethioware.org
files_create:
  - scripts/export-chatbot-knowledge.sh  # OR php
  - chatbot/knowledge/.gitkeep
logic: |
  Instatic publish hook (or cron on VPS) writes markdown files to:
    /home/ethiowzj/public_html/chatbot/knowledge/
  Format must match existing chatbot/knowledge/*.md structure — READ existing files first.
actions:
  - Document expected frontmatter/body format in scripts/README.md
  - Ensure rsync deploy includes chatbot/knowledge/
acceptance:
  - Editing chatbot_knowledge row in Instatic → export → chatbot answers with new content
```

### TASK P3-4: Deprecate EmailJS (after CMS form live)

```yaml
id: P3-4
depends_on: [P2-4, P4]
repo: ethioware.org
files_edit:
  - assets/js/sendmail.js  # remove or gate behind flag
  - index.html             # remove EmailJS script tags if still in static repo
timing: post-cutover only
acceptance:
  - No EmailJS network calls on production homepage
```

### TASK P3-5: Deprecate RSP PHP (after CMS form live)

```yaml
id: P3-5
depends_on: [P2-4, P4]
repo: ethioware.org
files_edit:
  - research-scholars/save_signup.php
actions:
  - Return 410 Gone with message OR redirect after CMS form verified
timing: post-cutover only
acceptance:
  - RSP signups only via Instatic form; data in rsp_signups table
```

### TASK P3-6: CI / lint updates

```yaml
id: P3-6
depends_on: [P4]
repo: ethioware.org
files_edit:
  - ci/primary-html.txt     # remove pages migrated to CMS if no longer maintained in git
  - .github/workflows/*     # add optional step to pull chatbot knowledge export
notes: |
  Certificate HTML files may remain in repo during transition but stop being source of truth.
  Do not delete 235 cert HTML until URL parity verified on Instatic.
acceptance:
  - CI passes on main
  - rsync deploy still succeeds
```

---

## P4 — PRODUCTION & CUTOVER

### TASK P4-1: Clone staging → production Instatic

```yaml
id: P4-1
depends_on: [P2-* complete on staging]
run_on: VPS
actions:
  - Site Transfer export from staging Instatic
  - Install ~/instatic (prod) — same as P0-2 but PORT=3001
  - Import bundle into production
  - .env PUBLIC_ORIGIN includes https://ethioware.org,https://www.ethioware.org,https://cms.ethioware.org
acceptance:
  - curl http://127.0.0.1:3001/admin → 200
```

### TASK P4-2: Bulk certificate migration

```yaml
id: P4-2
depends_on: [P2-2, P4-1]
strategy: |
  For each CODE in public_html/*.html matching certificate pattern:
    1. Duplicate certificate template in Instatic
    2. Set slug = CODE
    3. Set image from assets/img/<CODE>.webp
    4. Copy work-log iframe + logos from legacy HTML
  Automate with script if >50 pages (optional Instatic MCP or API).
priority: all ~235 before cutover OR top traffic certs first + 301 rest
acceptance:
  - Spot-check 20 random CODEs: staging/prod returns 200 at /<CODE>
  - No /certificates/ prefix
```

### TASK P4-3: Tunnel config phase 2

```yaml
id: P4-3
depends_on: [P4-1]
file_edit: /home/ethiowzj/.cloudflared/config.yml
ingress_add: |
  - hostname: ethioware.org
    path: /apply-submit.php
    service: http://127.0.0.1:80
  - hostname: ethioware.org
    path: /chatbot/*
    service: http://127.0.0.1:80
  - hostname: ethioware.org
    path: /cognify/*
    service: http://127.0.0.1:80
  - hostname: ethioware.org
    service: http://127.0.0.1:3001
  - hostname: www.ethioware.org
    service: http://127.0.0.1:3001
spike_before_cutover: |
  curl -H "Host: ethioware.org" http://127.0.0.1:80/chatbot/api/chat.php
acceptance:
  - apply-submit.php works through Cloudflare
  - chatbot API works through Cloudflare
  - CMS pages served by Instatic
```

### TASK P4-4: DNS cutover

```yaml
id: P4-4
depends_on: [P4-2, P4-3]
run_in: Cloudflare dashboard
actions:
  - Point ethioware.org + www to tunnel (if not already)
  - Keep Cloudflare proxy enabled
  - Lower TTL before cutover
rollback: |
  Revert DNS/tunnel ingress to Apache-only origin.
  public_html unchanged — static fallback remains.
acceptance:
  - https://ethioware.org serves Instatic published homepage
  - https://cms.ethioware.org/admin login works
```

### TASK P4-5: Backup cron

```yaml
id: P4-5
depends_on: [P4-1]
files_create:
  - /home/ethiowzj/bin/backup-instatic.sh
crontab: |
  0 3 * * * /home/ethiowzj/bin/backup-instatic.sh
backup_includes:
  - ~/instatic/storage/cms.db
  - ~/instatic/uploads/
  - ~/instatic-staging/storage/cms.db  # until staging decommissioned
retention: 7 days
```

---

## P5 — VERIFICATION & RUNBOOK

### TASK P5-1: Acceptance test matrix

```yaml
id: P5-1
depends_on: [P4-4]
tests:
  - id: T01
    action: GET https://ethioware.org/
    expect: 200; homepage content
  - id: T02
    action: GET https://ethioware.org/<CERT_CODE>
    expect: 200; certificate image
  - id: T03
    action: Submit apply form on /apply
    expect: MySQL row + Instatic applications row
  - id: T04
    action: Submit contact CMS form
    expect: contact_submissions row
  - id: T05
    action: Open chatbot; send message
    expect: Gemini response via /chatbot/api/chat.php
  - id: T06
    action: Edit chatbot_knowledge in CMS; publish; export
    expect: chatbot uses new answer
  - id: T07
    action: Marketing user publish announcement
    expect: audit log entry; content live
  - id: T08
    action: No microsoft.com/forms in page source
    expect: pass
  - id: T09
    action: cms.ethioware.org/admin login as Marketing
    expect: can publish; cannot manage users
```

### TASK P5-2: Staff runbook (markdown)

```yaml
id: P5-2
repo: ethioware.org
file_create: docs/cms-staff-runbook.md
sections:
  - Login URL: https://cms.ethioware.org/admin
  - New certificate (duplicate template workflow)
  - Post announcement
  - Toggle registrations open
  - Edit FAQ/partners/stats
  - Edit chatbot knowledge
  - View applications (read-only)
  - View audit log
```

---

## AGENT EXECUTION RULES

```yaml
before_any_edit:
  - Read target file fully
  - Confirm file exists in ethioware.org repo (clone if needed)
  - Check ci/primary-html.txt if editing HTML

commit_conventions:
  ethioware.org:
    branch: feat/instatic-integration-<task-id>  # e.g. feat/instatic-integration-P3-2
    pr_title: "feat(cms): <summary>"
  instatic:
    branch: feat/ethioware-<task-id>  # only if core changes needed (plugins, export hooks)

parallelism:
  safe_parallel:
    - P3-1 (schema doc) while P1-2 (import) runs
    - P2-7 (roles) while P2-4 (forms) runs
  sequential_required:
    - P4-4 after P4-3 spike passes
    - P3-4/P3-5 after P4-4

when_stuck:
  - Certificate URL not at root → use Page slug, NOT data_rows route_base
  - CSRF invalid origin → add host to PUBLIC_ORIGIN comma list
  - Chatbot CORS/API fail → check tunnel path rule for /chatbot/*
  - Process died after reboot → check crontab @reboot entries
  - Disk full on server → ticket host; user account only ~2GB

do_not:
  - Implement MFA unless asked
  - Move apply backend off PHP in v1
  - Replace Gemini chatbot backend in v1
  - Use Docker on VPS
  - Pay for Render/Railway
```

---

## OPEN INPUTS — AGENT MUST READ BEFORE P3-2 / P2-3

```yaml
required_artifacts:
  - ethioware db/*.sql — applications + rsp_signups column definitions
  - One sample chatbot/knowledge/*.md — export format
  - One sample certificate HTML — field extraction template
  - apply-submit.php — POST field names
  - GitHub Actions workflow — rsync paths and secrets names (do not print values)
```

---

## TASK CHECKLIST (copy for agent state)

```
[ ] P0-1 cloudflared installed
[ ] P0-2 instatic-staging installed
[ ] P0-3 tunnel phase 1
[ ] P0-4 persistence cron
[ ] P1-1 setup wizard
[ ] P1-2 super import
[ ] P1-3 sample certs
[ ] P2-1 homepage/nav
[ ] P2-2 cert template
[ ] P2-3 data tables
[ ] P2-4 CMS forms
[ ] P2-5 remove MS Forms
[ ] P2-6 chatbot embed
[ ] P2-7 marketing role
[ ] P3-1 schema doc
[ ] P3-2 applications dual-write
[ ] P3-3 chatbot export
[ ] P3-4 deprecate EmailJS
[ ] P3-5 deprecate RSP PHP
[ ] P3-6 CI updates
[ ] P4-1 production instatic
[ ] P4-2 bulk certs
[ ] P4-3 tunnel phase 2
[ ] P4-4 DNS cutover
[ ] P4-5 backups
[ ] P5-1 acceptance tests
[ ] P5-2 staff runbook
```
