# Ethioware — CMS Integration Context

> **Purpose:** This document describes the Ethioware website codebase for an LLM or architect designing a plan to integrate an open-source CMS. It captures what exists today, hard constraints, content types, and integration surface areas — not a recommendation of which CMS to choose.

**Last reviewed:** 2026-08-04  
**Live site:** https://ethioware.org  
**Repository:** https://github.com/Ethioware-EdTech-Initiative/ethioware.org (also referenced as `ethioware.et`)

---

## 1. Organization & product context

**Ethioware EdTech Initiative** is a non-profit connecting high-school graduates in Africa with industry mentors through "pre-training readiness" online sessions. Mission statement on site: *"Direct 200,000 African Youth to Intentionally Chosen Careers by 2040."*

The website serves multiple audiences:

| Audience | Primary needs on site |
|----------|----------------------|
| Students / parents | Learn about programs, apply, pay, view certificates |
| Mentors / volunteers | Partner logos, team profiles, recruitment forms |
| Donors / partners / investors | Payment page, support page, partnership info (partially gated in chatbot) |
| Internal staff | Publish certificates, update stats/copy, manage chatbot knowledge, review applications |

**Content update frequency (estimated from repo history):**

- Certificate pages: batch additions (~235 exist; new cohorts add dozens at a time)
- Homepage stats, copy, partner logos: occasional
- Program descriptions: infrequent but must stay in sync across HTML, PHP ENUMs, and chatbot markdown
- Publications / interview embeds: occasional
- Chatbot knowledge (`chatbot/knowledge/*.md`): ongoing editorial

---

## 2. Current architecture (high level)

```
                    ┌─────────────────────────────────────────┐
                    │  cPanel shared hosting (Apache/LiteSpeed) │
                    │  Document root = git repo root            │
                    └─────────────────────────────────────────┘
                                        │
        ┌───────────────────────────────┼───────────────────────────────┐
        │                               │                               │
   Static HTML/CSS/JS              PHP endpoints                   Server-only config
   (~248 HTML pages)               (17 .php files)                 (gitignored)
        │                               │                               │
   index.html (2,172 lines)        apply-submit.php                 research-scholars/config.php
   235 certificate pages           chatbot/api/*.php                chatbot/config.php
   team bios, pay, apply           research-scholars/save_signup.php
        │                               │
        └───────────────┬───────────────┘
                        ▼
                 MySQL (single shared DB)
                 ├── applications
                 ├── rsp_signups
                 ├── chatbot_leads
                 ├── chatbot_conversations
                 └── chatbot_events
```

**There is no frontend framework, no bundler, and no build step.** What is committed is what gets served (except SCSS, which is compiled locally and committed as CSS).

Deployment: **GitHub Actions → rsync over SSH** to cPanel `public_html`, gated on CI. rsync runs **without `--delete`**.

---

## 3. Technology stack

### 3.1 Frontend

| Layer | Technology | Notes |
|-------|------------|-------|
| Markup | Hand-written HTML5 | No templating engine in production (see §3.4) |
| Styles | CSS3 + SCSS sources | Compiled output in `assets/css/styles.css`; no CI SCSS build |
| Scripts | Vanilla ES6+ JS | `assets/js/main.js`, `script.js`, `bio.js`, `sendmail.js`, `chatbot.js` |
| Design | Glassmorphism, dark/light theme | Theme via `localStorage`; CSS custom properties |
| Fonts | Fraunces, Public Sans, Inter, Poppins | Mixed across pages — homepage uses Fraunces/Public Sans; apply uses Inter |
| Icons | Font Awesome 6.5, Remix Icon | CDN |
| Animations | ScrollReveal.js | CDN |

**Homepage behaves like a single-page app for navigation:** `main.js` maps paths (`/about`, `/mission`, etc.) to in-page section IDs and uses `history.pushState` for smooth scrolling — but all content lives in one `index.html` file.

### 3.2 Backend (minimal PHP)

| Endpoint | Purpose |
|----------|---------|
| `apply-submit.php` | Multi-step application form → MySQL + email |
| `research-scholars/save_signup.php` | Research Scholars early signup → MySQL |
| `chatbot/api/chat.php` | AI chat → Gemini API + MySQL |
| `chatbot/api/track.php` | Application abandonment tracking |
| `chatbot/admin/*.php` | Password-gated leads dashboard |
| `cognify/api/v1/auth/exchange/` | Google OAuth token exchange (Chrome extension; server-side, not in static tree) |

**Database access:** mysqli via `research-scholars/config.php` → `rsp_get_connection()`. No ORM, no PHP framework.

### 3.3 Third-party services (CDN / SaaS)

| Service | Usage |
|---------|-------|
| Google Analytics | `G-RYB205PY5C` |
| EmailJS | Contact form on homepage (`assets/js/sendmail.js`) |
| Trustpilot | Review widgets |
| Google OAuth / One Tap | Sign-in (partially configured) |
| Google Gemini API | Chatbot (`gemini-2.5-flash`) |
| Microsoft Forms | Embedded iframes for program registration on homepage **and** footer links (membership, interns, trainees) |
| OneDrive | Publication PDF embeds |
| Google Drive | Certificate work-log iframes |
| YouTube | Interview embeds |
| WhatsApp | Support link |

### 3.4 Artifacts suggesting prior CMS/framework consideration

- `index.html` and `support.html` contain `<meta name="csrf-token" content="{{ csrf_token() }}" />` — a **Laravel Blade placeholder that is never processed**. The site is not running Laravel today.
- `pages/template.html` exists as a reference for new bio-style pages but is not wired into any generator.

---

## 4. Repository layout (content-relevant paths)

```
/                              ← site document root
├── index.html                 ← marketing homepage (~80 KB, 2,172 lines)
├── apply.html                 ← application wizard (471 lines; posts to apply-submit.php)
├── pay.html                   ← payment / donation (1,155 lines)
├── privacy.html
├── support.html               ← Research Scholars supporter page
├── anteneh.html, biniyam.html, samuel.html   ← team bio pages
├── ethioware_supporter_registration.html
│
├── certificates/              ← 235 learner certificate/interview pages
│   └── <CODE>.html            ← served at https://ethioware.org/<CODE>
│
├── assets/
│   ├── css/   styles.css, bio.css, chatbot.css
│   ├── js/    main.js, script.js, bio.js, sendmail.js, chatbot.js
│   ├── img/   logos, certificate scans (WebP), portraits, partner logos
│   ├── scss/  source styles (not built in CI)
│   ├── reviews/   Trustpilot review card images
│   └── resumes/   PDF resumes for team pages
│
├── chatbot/
│   ├── knowledge/*.md         ← chatbot knowledge base (deployed; editorial content)
│   ├── api/                   ← PHP chat backend
│   └── admin/                 ← PHP admin dashboard
│
├── research-scholars/         ← early signup subapp
├── cognify/                   ← Chrome extension OAuth page
├── db/                        ← SQL schemas (not deployed)
├── ci/                        ← CI scripts (not deployed)
└── pages/template.html        ← unused bio page template
```

**Primary linted pages** (see `ci/primary-html.txt`): `index.html`, `apply.html`, `pay.html`, `privacy.html`, `support.html`, team bios, `cognify/auth.html`, `pages/template.html`. Certificate pages are **excluded** from HTML lint due to ~765 known markup issues.

---

## 5. Content inventory (CMS-relevant)

### 5.1 Homepage sections (`index.html`)

| Section ID | Content type | Edit pattern today |
|------------|--------------|-------------------|
| `#home` | Hero, CTAs | Hard-coded HTML |
| (stats) | Animated counters: graduates (210+), mentors (35+), partnerships (3), nationals (8+) | Hard-coded `data-target` values |
| `#about` | Mission copy | Hard-coded |
| `#graduates` | Trustpilot review cards (image + school name + external link) | Hard-coded; images in `assets/reviews/` |
| `#approach` | Process description + embedded content | Hard-coded |
| `#mission` | Three-step cards (Motivation, Adaptation, Execution) | Hard-coded |
| (partners) | Scrolling logo carousel with LinkedIn links | Hard-coded; ~40+ partner entries duplicated in markup |
| `#articles` | Publications (OneDrive iframe accordions) + YouTube interview embeds | Hard-coded URLs |
| `#train` | "Current Programs" — **4× Microsoft Forms iframes** | External forms; not in repo |
| `#contact` | EmailJS contact form | Hard-coded + EmailJS config in JS |
| `#faq` | FAQ accordion | Hard-coded |
| (footer) | Links, socials, Trustpilot widget | Hard-coded; some links → Microsoft Forms |

### 5.2 Certificate / learner pages (`certificates/<CODE>.html`)

**235 pages**, each representing one learner session. Public URL: `https://ethioware.org/<CODE>` (no `/certificates/` prefix — critical SEO/print constraint).

**Per-page variable content:**

| Field | Example | Storage today |
|-------|---------|---------------|
| Certificate code | `WI10092516` | Filename + canonical tag |
| Certificate image | `assets/img/WI10092516.webp` | Static WebP in repo |
| Work log iframe | Google Drive preview URL | Hard-coded per page |
| Expert/employer logos | LinkedIn URL + logo image | Hard-coded carousel (varies per learner) |
| Page title / meta | Certificate & Work Log | Hard-coded (18 pages have duplicate `<title>` tags) |

**Creation workflow today** (from `handoff.md`):

1. Copy an existing certificate HTML as template
2. Add WebP image to `assets/img/`
3. Set canonical tag
4. Run `python3 ci/gen-sitemap.py`
5. Verify with `ci/htaccess-route-check.py` and `ci/check-assets.py`

This is the **highest-volume, most repetitive content type** and a prime CMS candidate.

### 5.3 Team bio pages

Three live pages (`anteneh.html`, `biniyam.html`, `samuel.html`) plus `pages/template.html`. Structure: hero banner, bio, experience, skills, portfolio, reviews, contact. Content is entirely hand-edited HTML using `assets/css/bio.css` + `assets/js/bio.js`.

### 5.4 Application flow (`apply.html` + `apply-submit.php`)

Multi-step wizard (one question per screen). **Not CMS content** — but program names and options are duplicated in:

- `apply.html` (JS slides + program description objects)
- `apply-submit.php` (PHP validation whitelist)
- `db/applications.sql` (MySQL ENUMs)
- `chatbot/knowledge/10-programs.md`
- `chatbot/api/prompt.php` (structured output schema)

Programs: `Software Engineering Basics`, `Engineering Basics`, `Law Basics`, `Medicine Basics`.

**Any CMS managing program metadata must account for this 4-way sync problem.**

### 5.5 Chatbot knowledge base (`chatbot/knowledge/`)

| File | Content | Special rules |
|------|---------|---------------|
| `00-org.md` | Organization overview | Always loaded |
| `10-programs.md` | Program details (mostly TODO placeholders) | Must sync with apply ENUMs |
| `20-enrollment.md` | Enrollment process | Always loaded |
| `30-mentorship.md` | Mentorship info | Always loaded |
| `40-partnerships.md` | Partnership/pricing/donation info | **Gated** — only loaded server-side after user passes email gate |
| `90-faq.md` | FAQ | Always loaded |

Loaded by `chatbot/api/prompt.php` at request time from disk. Deployed via rsync (explicitly **not** excluded in `.deployignore`).

### 5.6 Other pages

| Page | Content nature |
|------|----------------|
| `pay.html` | Payment instructions, donation tiers, Chapa/other payment info — large static page |
| `privacy.html` | Legal copy |
| `support.html` | Research Scholars supporter/mentor recruitment |
| `research-scholars/signup.html` | Separate signup form → PHP/MySQL |

### 5.7 Media assets

- **Certificate scans:** WebP preferred (~350 KB each at quality 86); stored in `assets/img/<CODE>.webp`
- **Partner/employer logos:** PNG/WebP/JPEG in `assets/img/`
- **Review cards:** PNG in `assets/reviews/`
- **~49 missing images** baselined in `ci/known-missing-assets.txt` (known gap)

---

## 6. Data layer

### 6.1 MySQL tables (single shared database)

| Table | Rows expected | CMS overlap? |
|-------|---------------|--------------|
| `applications` | One per form submission | Could replace form backend or integrate via webhook |
| `rsp_signups` | Research Scholars signups | Same |
| `chatbot_leads` | Chat sessions with lead signals | Unlikely CMS-managed |
| `chatbot_conversations` | JSON message transcripts | Unlikely CMS-managed |
| `chatbot_events` | Analytics/event stream | Unlikely CMS-managed |

Schema files: `db/applications.sql`, `db/chatbot.sql`, `research-scholars/schema.sql`.

### 6.2 Config/secrets (server-only, gitignored)

| File | Contains |
|------|----------|
| `research-scholars/config.php` | MySQL credentials, `rsp_get_connection()` |
| `chatbot/config.php` | Gemini API key, admin password hash |

Pattern: any file named `config.php` at any depth is excluded from git and deploy.

---

## 7. URL routing & SEO (non-negotiable constraints)

All routing is in root `.htaccess`:

### 7.1 Certificate short URLs (CRITICAL)

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{DOCUMENT_ROOT}/certificates/$1.html -f
RewriteRule ^([A-Za-z0-9_-]+)(?:\.html)?$ certificates/$1.html [L]
```

- Internal rewrite only (address bar stays `/<CODE>`)
- Certificate pages **must use root-relative asset paths** (`assets/img/…`, not `../`)
- URLs are printed on physical certificates and shared publicly — **must never change**
- Code prefixes include: WI, MB, EB, ES, SEB, MEB, CMI, DI, RI, VI, MI, SI, WD, TS, LB, EM, SM, V, etc.

**CMS integration must preserve this URL scheme** or provide a migration with permanent redirects (high risk for 235+ live links).

### 7.2 Extensionless pretty URLs

`/privacy` → `privacy.html`, `/apply` → `apply.html`, etc.

### 7.3 Staging / SEO gating

Non-production hosts get `X-Robots-Tag: noindex, nofollow`. Only `ethioware.org` / `www.ethioware.org` is indexable.

### 7.4 Sitemap

`sitemap.xml` — ~242 URLs, regenerated via `python3 ci/gen-sitemap.py` (uses git lastmod). Referenced by `robots.txt`.

---

## 8. Hosting & deployment constraints (CMS selection factors)

| Constraint | Detail |
|------------|--------|
| **Host** | cPanel shared hosting, CloudLinux, LiteSpeed, PHP 8.x, MySQL |
| **No root/sudo** | Cannot install arbitrary system packages; PHP extensions limited to host's PHP Selector |
| **MongoDB** | Not viable from shared PHP (see `CHATBOT_SPEC.md` §3) |
| **Deploy** | rsync from GitHub Actions; no `--delete` |
| **Secrets** | Server-managed `config.php` files; never in git |
| **Node build on server** | Unlikely supported for production builds; any SSG/build step probably runs in CI |
| **Staging** | Subdomain with separate docroot works; auto-noindexed |

A CMS that requires Docker, Redis, Elasticsearch, or long-running workers is **poorly matched** unless hosted externally (headless) with static/PHP frontend on cPanel.

---

## 9. CI & quality gates

Workflow: `.github/workflows/ci.yml` — runs on PR/push to `master`.

| Job | Relevance to CMS integration |
|-----|------------------------------|
| `html-primary` | html-validate on primary pages |
| `links-offline` | lychee link checker |
| `js-syntax` | node --check on JS files |
| `cert-routes` | Emulates `.htaccess`; all 235 cert URLs must resolve |
| `assets` | All asset refs across ~244 pages must resolve (baseline for known gaps) |
| `php-syntax` | php -l on all PHP |
| `guard` | Fails if `config.php` or `node_modules/` committed |
| `deploy` | rsync to cPanel after green CI |

**CMS-generated output must pass these checks** or CI must be updated.

`.deployignore` excludes: `.git/`, `.github/`, `node_modules/`, root `*.md` docs, `/db/`, `config.php`, `/ci/`. Notably **`chatbot/knowledge/*.md` is deployed**.

---

## 10. Design system (preserve in templates)

| Token | Value |
|-------|-------|
| Primary blue | `hsl(215, 80%, 32%)` |
| Body font | Public Sans / Poppins |
| Heading font | Fraunces / Poppins |
| Theme | Light/dark via `body.dark-theme` class + localStorage |
| UI pattern | Glassmorphism (`backdrop-filter: blur`, semi-transparent backgrounds) |
| Breakpoints | Mobile 320–767, Tablet 768–991, Desktop 992+ |

Certificate pages mix Tailwind CDN + site `styles.css` — inconsistent with homepage styling approach.

---

## 11. Current content-management pain points

1. **Certificate publishing is manual and error-prone** — copy HTML, edit URLs, add image, regenerate sitemap, run CI scripts.
2. **Program metadata duplicated in 4+ places** — HTML, PHP, SQL ENUMs, chatbot markdown.
3. **Homepage is a monolith** — 2,172-line single file; editing one section risks breaking others.
4. **Partner logo carousel duplicated** — homepage and each certificate page maintain separate logo lists.
5. **Mixed form backends** — custom PHP apply form, Microsoft Forms embeds, Research Scholars PHP form, EmailJS contact form.
6. **Chatbot knowledge is markdown-in-git** — non-technical editors need PR workflow or a CMS with markdown/API sync.
7. **Publication/interview content** — hard-coded iframe URLs (OneDrive, YouTube).
8. **Stats counters** — hard-coded numbers; noted in `handoff.md` as needing real traction data.
9. **Certificate markup debt** — unbalanced tags, duplicate titles; blocks cert pages from CI lint.
10. **No preview/staging content workflow** — staging subdomain exists but content is file-based.

---

## 12. Integration surface areas (for planning LLM)

### 12.1 Likely CMS-managed content types

| Content type | Suggested CMS model | Complexity |
|--------------|---------------------|------------|
| Certificate / learner page | Collection type with code, image, work-log URL, expert logos | High (URL routing) |
| Homepage sections | Singleton or flexible blocks | Medium |
| Team bio | Profile collection | Low |
| Publication | Document with embed URL | Low |
| Interview video | Video embed entry | Low |
| Partner / employer logo | Logo collection (reusable relation) | Medium |
| FAQ item | Structured entries | Low |
| Program | Structured entry (must sync to apply/chatbot) | High |
| Stat counter | Key-value or site settings | Low |
| Chatbot knowledge article | Markdown or rich text → export to `chatbot/knowledge/` | Medium |

### 12.2 Likely NOT CMS-managed (keep as code or separate systems)

- Application form logic and validation
- Chatbot AI pipeline (Gemini calls, gate enforcement)
- Payment processing flows
- Cognify OAuth extension backend
- CI/deploy pipeline

### 12.3 Plausible integration patterns

| Pattern | Pros for this codebase | Cons |
|---------|--------------------------|------|
| **Headless CMS + static site generator** | Keeps cPanel static hosting; CI builds HTML | Requires build pipeline; cert URL routing must be reproduced |
| **Headless CMS + PHP template layer** | Fits existing PHP on host; can serve dynamic cert pages | Introduces runtime dependency; departs from static model |
| **Git-based CMS (Decap/Sveltia/etc.)** | Minimal infra change; PR workflow matches current deploy | Still requires build or markdown→HTML for certs |
| **Traditional CMS (WordPress/etc.) on subdomain/path** | Familiar admin UI | May conflict with existing `.htaccess` cert routing; migration heavy |
| **CMS as editorial source only; build step emits static HTML** | Preserves current architecture best | Needs Node/PHP build in CI |

### 12.4 Certificate URL strategies (decision required)

| Option | Description |
|--------|-------------|
| A. **Static generation** | CMS build emits `certificates/<CODE>.html`; existing `.htaccess` unchanged |
| B. **Dynamic PHP route** | Single `certificate.php?code=` or rewrite to PHP; CMS stores data in MySQL |
| C. **Hybrid** | New certs dynamic; existing 235 remain static forever |

Option A is lowest risk for existing URLs. Option B enables admin UI but requires careful rewrite ordering in `.htaccess`.

### 12.5 Sync targets if programs become CMS-managed

Any program definition in a CMS should propagate to (or be read by):

- `apply.html` program slides
- `apply-submit.php` validation
- `db/applications.sql` ENUM (or migrate ENUM → lookup table)
- `chatbot/knowledge/10-programs.md` (or replace with API fetch)
- `chatbot/api/prompt.php` schema

---

## 13. Security & compliance notes

- `cognify/.htaccess` contains a **plaintext JWT_SECRET** committed to repo — known issue; rotate regardless of CMS work.
- `robots.txt` disallows `/cognify/` and `/chatbot/`.
- Privacy policy at `privacy.html`; forms collect PII (name, email, school, etc.).
- Chatbot partnership content is structurally gated (file not loaded until gate passed) — any CMS replacing `40-partnerships.md` must preserve this server-side gate in `chatbot/api/prompt.php`.

---

## 14. Related internal documentation

| File | Contents |
|------|----------|
| `TECHNICAL_OVERVIEW.md` | Consolidated technical reference |
| `handoff.md` | Project history, TODOs, conventions |
| `DEPLOY.md` | GitHub → cPanel deploy setup |
| `CHATBOT_SPEC.md` | Chatbot architecture and DB rationale |
| `README.md` | Public-facing project overview |

These root-level `.md` files are **not deployed** to production (`.deployignore`).

---

## 15. Open questions for stakeholders

Before finalizing a CMS integration plan, clarify the following with the Ethioware team:

### 15.1 Scope & priorities

1. **Which content types must the CMS manage on day one?** (certificates only? homepage? everything?)
2. **Who are the editors?** (developers only vs. non-technical staff/marketing/volunteers)
3. **Is bilingual/multilingual content required?** (site is English-only today)

### 15.2 Certificate workflow

4. **How often are new certificates published?** (per cohort size, frequency)
5. **Should editors create certificates entirely in a UI**, or is a "upload CSV + images zip" batch workflow acceptable?
6. **Are existing 235 certificate URLs immutable forever**, or is a one-time migration acceptable?
7. **Should work-log Google Drive URLs and expert logo sets be CMS fields**, or remain freeform?

### 15.3 Architecture preferences

8. **Must the site remain static HTML on cPanel**, or is a PHP runtime CMS acceptable on the same host?
9. **Is an external CMS host OK** (e.g., Strapi Cloud, Directus Cloud, self-hosted VPS) with API → static build?
10. **Is adding a CI build step (Node/PHP) acceptable?** (currently there is none for the frontend)
11. **Budget for hosting** — stay on shared cPanel only, or can add a small VPS for CMS?

### 15.4 Forms & data

12. **Should Microsoft Forms embeds on the homepage be replaced** by CMS-managed content + existing `apply.html`?
13. **Should the CMS admin replace or complement** the existing chatbot admin (`chatbot/admin/`)?
14. **Should application/signup data be viewable/exportable from the CMS**, or keep separate PHP dashboards?

### 15.5 CMS candidates

15. **Are there preferred CMS options?** (Strapi, Directus, Payload, WordPress, Ghost, Decap CMS, Keystone, etc.)
16. **Any technologies to avoid** (Node version limits, PHP version on host, license requirements)?

### 15.6 Design & UX

17. **Must the CMS preserve the exact current design**, or is a redesign acceptable as part of integration?
18. **Should certificate pages be visually unified** (they currently mix Tailwind CDN + legacy styles)?

---

## 16. Suggested planning deliverables

An LLM or architect using this document should produce:

1. **CMS recommendation matrix** scored against §8 hosting constraints and §12 integration patterns
2. **Phased migration plan** (e.g., Phase 1: certificates; Phase 2: homepage blocks; Phase 3: chatbot knowledge sync)
3. **Content model schema** for priority content types (§12.1)
4. **URL/routing impact analysis** for certificates (§7.1, §12.4)
5. **CI/CD changes** required (§9)
6. **Program metadata sync strategy** (§12.5)
7. **Editor workflow** (draft → preview on staging → publish → deploy)
8. **Rollback plan** compatible with rsync-without-delete deploy model

---

## 17. Quick reference — file counts

| Item | Count |
|------|-------|
| HTML pages total | ~248 |
| Certificate pages | 235 |
| Primary/marketing pages | ~10 |
| PHP files | 17 |
| Chatbot knowledge files | 6 |
| Sitemap URLs | ~242 |

---

*End of CMS integration context document.*
