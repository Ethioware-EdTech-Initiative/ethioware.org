# Ethioware — Technical Overview

> One-stop technical reference: how the site is built, where it runs, and how it deploys.
> For deploy setup steps see [DEPLOY.md](DEPLOY.md); for the fuller running project log see [handoff.md](handoff.md). This file is the consolidated summary of both.

---

## 1. What it is

**Ethioware EdTech Initiative** ([ethioware.org](https://ethioware.org)) is a static marketing + content website for an education non-profit connecting high-school graduates with industry mentors. It's a hand-built, **no-framework, no-build-step** HTML/CSS/JS site — there is no React/Vue/Next.js, no bundler, no transpilation. What's in the repo is what gets served.

The site includes:
- A marketing homepage (mission, stats, partners, publications, CTAs)
- An application form (`apply.html`), a payment/donation page (`pay.html`), team profiles
- **~235 certificate/interview pages**, one per learner/session, each shared as a short URL (e.g. `ethioware.org/WI10092516`)
- A small Google-OAuth companion page (`cognify/`) for a Chrome extension
- A separate early-signup form (`research-scholars/`)

---

## 2. Technology stack

**Frontend**
- HTML5 (semantic markup)
- CSS3 — custom properties, flexbox, grid, glassmorphism styling, dark/light theme via `localStorage`
- Vanilla JavaScript (ES6+) — no framework
- SCSS sources in `assets/scss/`, compiled output committed directly as `assets/css/styles.css` (no SCSS build step wired into CI — compile locally and commit the CSS)

**Backend**
- **PHP + MySQL (mysqli)**, used only for two form backends:
  - `apply-submit.php` — the main application form
  - `research-scholars/save_signup.php` — the early-signup form
  - Both share one MySQL database with separate tables (`applications`, `rsp_signups`)
- Served by cPanel's PHP via LiteSpeed; no PHP framework

**Third-party libraries & services (all via CDN, no package manager for the frontend)**
- ScrollReveal.js (scroll animations)
- Font Awesome 6.5.0, Remix Icon 2.5.0 (icons)
- Google Fonts — Poppins, Inter, Josefin Sans
- EmailJS (contact form delivery — service `service_vgahbke`, template `template_bayaxj4`)
- Google Analytics (measurement ID `G-RYB205PY5C`)
- Trustpilot widget (reviews)
- Google OAuth / One Tap sign-in

**Dev tooling**
- `html-validate` (only npm dev dependency) — `npm run lint:html`
- No frontend package is required to *run* the site — `node_modules/` and `package.json` are purely for linting/CI and are excluded from deploys

---

## 3. Repository layout

```
/                         repo root = site docroot
├── index.html            homepage (~80 KB)
├── apply.html             multi-step application form → apply-submit.php
├── apply-submit.php       application backend (mysqli)
├── db/applications.sql    schema for the `applications` table
├── pay.html               payment / donation page
├── privacy.html           privacy policy
├── anteneh.html / biniyam.html / samuel.html   team profiles
├── sitemap.xml / robots.txt
├── .htaccess              routing + caching + compression (Apache/LiteSpeed)
│
├── certificates/          235 certificate/interview pages, served at root
│                          short URLs (WI*/MB*/EB*/ES*/SEB*/MEB*/CMI*/DI*/RI*/…)
│                          via an internal .htaccess rewrite — public URL never changes
│
├── assets/
│   ├── css/   styles.css, bio.css
│   ├── js/    main.js, script.js, bio.js, sendmail.js, scrollreveal.min.js
│   ├── img/   logos, certificate scans (WebP), portraits, banners
│   ├── scss/  SCSS sources (base/components/layout/theme)
│   └── resumes/, reviews/
│
├── cognify/               Google-OAuth auth subapp for a companion Chrome extension
│   ├── auth.html / auth.js / auth.css
│   ├── .htaccess          holds CloudLinux env vars (JWT secret, Google client ID)
│   └── api/v1/auth/exchange/   server-side token-exchange endpoint (not static)
│
├── research-scholars/      early-signup subapp (PHP/mysqli)
│   ├── signup.html / index.html
│   ├── save_signup.php     → rsp_signups table
│   ├── schema.sql
│   └── config.php          shared MySQL credentials — gitignored, server-only
│
├── pages/template.html     reference template for new pages
├── ci/                     CI helper scripts (see §6)
└── .github/workflows/ci.yml
```

Real top-level pages are lowercase (`index`, `apply`, `pay`, `privacy`, `anteneh`, `biniyam`, `samuel`). Anything matching an uppercase certificate code lives in `certificates/`.

---

## 4. Certificate short-URL system

Learner certificates are published at **`https://ethioware.org/<CODE>`** (e.g. `/WI10092516`) — no extension, no `/certificates/` in the path. These URLs are shared/printed and must never change.

The HTML physically lives in `certificates/<CODE>.html`, but `.htaccess` does an **internal rewrite** (not a redirect), so the address bar keeps showing `/<CODE>`:

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{DOCUMENT_ROOT}/certificates/$1.html -f
RewriteRule ^([A-Za-z0-9_-]+)(?:\.html)?$ certificates/$1.html [L]
```

This fires only when the request isn't a real file/directory and a matching certificate file exists — so it automatically covers every existing and future code prefix with no maintenance. Because the URL stays root-level, certificate pages must use **root-relative asset paths** (`assets/img/…`), not `../`.

---

## 5. Hosting & deployment

- **Host:** cPanel / Apache (LiteSpeed, CloudLinux) shared hosting.
- **Deploy model:** GitHub Actions → `rsync` over SSH into the cPanel docroot (`public_html`), gated on CI.
  - Flow: **open PR → CI runs required checks → merge to `master`→ deploy job rsyncs over SSH → live.**
  - The `deploy` job only runs on push to `master`, and only after every test job passes (`needs:`). `master` is branch-protected, so nothing reaches it except via a green PR.
  - rsync runs **without `--delete`** — only changed files are uploaded; server-only files (`config.php`, uploads) are never touched. Excludes are defined in `.deployignore` (VCS/CI/tooling files, all `*.md` docs, `/db/`, `config.php`, stray local PDFs).
  - Manual re-deploy: GitHub → Actions → CI → **Run workflow** on `master`.
- **Repository:** `https://github.com/Ethioware-EdTech-Initiative/ethioware.org`
- Full one-time setup (SSH keys, GitHub secrets, branch protection) is documented in [DEPLOY.md](DEPLOY.md).

**Smoke test after a deploy:**
```bash
curl -I https://ethioware.org/                 # expect 200
curl -I https://ethioware.org/WI10092516        # expect 200 (a certificate short URL)
```

**Rollback:** revert the offending commit on `master` (via PR or GitHub's Revert button); merging the revert re-runs CI and redeploys the previous state. Because rsync has no `--delete`, reverting a *new file* addition leaves the file on the server until removed by hand.

**Staging:** any subdomain with its own document root (e.g. `staging.ethioware.org`) works out of the box — `.htaccess` auto-adds `X-Robots-Tag: noindex` for any host other than `ethioware.org`/`www.ethioware.org`, so staging can't be indexed or duplicate production.

---

## 6. CI & automated testing

Defined in `.github/workflows/ci.yml`, runs on push/PR to `master`. All jobs below are required status checks except `deploy`:

| Job | What it checks |
|-----|-----------------|
| `html-primary` | `html-validate` on the primary pages (`ci/primary-html.txt`, config `.htmlvalidate.json`) |
| `links-offline` | `lychee` (offline) — local `href`/`src`/`url()` resolve for primary pages + `assets/` |
| `js-syntax` | `node --check` on checked-in JS (`biniyam.js`, `cognify/auth.js`) |
| `cert-routes` | `ci/htaccess-route-check.py` — emulates `.htaccess`, asserts all 235 certificate URLs + top pages resolve |
| `assets` | `ci/check-assets.py` — every local asset/link reference across all ~244 pages resolves; a baseline file accepts pre-existing gaps but fails on any *new* break |
| `php-syntax` | `php -l` on every checked-in `*.php` |
| `guard` | repo hygiene — fails if `config.php`, `node_modules/`, or a missing `.deployignore` is committed |
| `deploy` | not required; runs only on push to `master`; rsyncs to cPanel over SSH |

Run everything locally before pushing:
```bash
npm ci
npm run lint:html
node --check biniyam.js && node --check cognify/auth.js
python3 ci/htaccess-route-check.py
python3 ci/check-assets.py
for f in $(find . -name '*.php' -not -path './node_modules/*'); do php -l "$f"; done
python3 ci/gen-sitemap.py   # regenerate sitemap.xml when pages change
```

---

## 7. Application backend (`apply.html` → PHP → MySQL)

`apply.html` is a multi-step, one-question-per-screen form (program, grade, GPA, Telegram, how-heard, etc.) that posts `application/x-www-form-urlencoded` to `apply-submit.php`.

`apply-submit.php` (root, PHP/LiteSpeed):
- Reuses the Research Scholars database via `research-scholars/config.php` and `rsp_get_connection()` (mysqli), inserting into a separate `applications` table.
- `POST`-only; returns JSON `{"success": bool, "message": string}`.
- Validates required fields; whitelists `program`/`grade`/`where_heard`/`linkedin_follow` against fixed sets that must stay in sync across `apply.html` (JS), `apply-submit.php` (PHP), and `db/applications.sql` (ENUMs).
- Hidden honeypot field (`website`) — bots get a silent success.
- Uses a `bind_param` prepared statement (no SQL injection); strips CR/LF from mail-header values (no header injection).
- Emails `info@ethioware.org` and the applicant via `mail()`, best-effort (failure doesn't block the save).
- The DB password lives only in `research-scholars/config.php` — gitignored, present only on the server.

**research-scholars/** is the same pattern for an early-signup form (`save_signup.php` → `rsp_signups` table), and is the source of the shared DB config both backends use.

---

## 8. cognify/ auth subapp

A standalone Google Sign-In page (`ethioware.org/cognify/auth?source=extension`) for a companion **Chrome extension**. Flow: Google returns a JWT → page redirects to the extension with the token → extension exchanges it at `POST /cognify/api/v1/auth/exchange` for a backend JWT (that exchange endpoint is server-side, not part of this static tree). `robots.txt` disallows `/cognify/`.

`cognify/.htaccess` holds CloudLinux env vars for this subapp, including a `JWT_SECRET`. **That secret is committed in plaintext in the repo** — a known issue (see `handoff.md` §2): it should be rotated and moved to a location outside version control (e.g. server-only config, matching the `research-scholars/config.php` pattern). `GOOGLE_CLIENT_ID` in the same file is a public value and not sensitive.

---

## 9. `.htaccess` responsibilities

A single `.htaccess` at the repo root (deployed as-is to every environment) handles:
- **SEO host-gating** — only `ethioware.org` / `www.ethioware.org` is indexable; every other host (staging, direct IP) gets `X-Robots-Tag: noindex`.
- **Canonical URL cleanup** — strips a stray trailing `#` some shared links carry.
- **Certificate short URLs** — the internal rewrite described in §4.
- **Extensionless pretty URLs** — `/privacy` → `privacy.html`, `/apply` → `apply.html`, etc.
- **Compression** — gzip/deflate for text-based responses (guarded by `<IfModule mod_deflate.c>` so it no-ops if the module is missing).
- **Caching** — static assets (CSS/JS/images) cached for a month to a year; HTML kept at 0 seconds so page edits show immediately.

---

## 10. Conventions & known issues

- **Images:** prefer WebP (certificate scans were converted from ~4 MB PNGs to ~350 KB WebP at `-quality 86 -define webp:method=6`).
- **Certificate asset paths must be root-relative**, not `../` (see §4).
- `.editorconfig`: UTF-8, LF, 2-space indent, trim trailing whitespace.
- `.htmlvalidate.json` relaxes a couple of html-validate rules for the existing markup.
- Known outstanding items (see `handoff.md` §10 for the full list): rotate the committed `cognify` JWT secret; ~49 missing certificate images tracked in `ci/known-missing-assets.txt`; certificate page markup has unbalanced tags/duplicate `<title>`s that CI doesn't yet lint.

---

## 11. Contacts & external links

- **Site:** https://ethioware.org
- **Repo:** https://github.com/Ethioware-EdTech-Initiative/ethioware.org
- **Email:** info@ethioware.org · parents@ethioware.org
- **Socials:** LinkedIn / Twitter / YouTube / Telegram / Instagram / Facebook — `@ethioware`
