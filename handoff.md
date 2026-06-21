# Ethioware — Project Handoff

> Living context document for the Ethioware EdTech Initiative website.
> Last updated: 2026-06-21.

---

## 1. What this is

**Ethioware EdTech Initiative** (https://ethioware.org) is a static marketing +
content website for an education non-profit/startup whose mission is *"Direct
200,000 African Youth to Intentionally Chosen Careers by 2040."* It connects
high‑school graduates with industry mentors and publishes:

- A marketing homepage (mission, approach, stats, partners, publications, CTAs).
- An application page, a payment/donation page, team profiles.
- **~235 "certificate / interview" pages** — one per learner/session, each
  displaying a certificate image plus the logos of experts they learned from.
  These are shared with learners as **short URLs** (e.g. `ethioware.org/WI10092516`).

There is **no build step** and **no framework** — hand-written HTML/CSS/JS.

---

## 2. Hosting & deployment

- **Host:** cPanel / Apache (LiteSpeed, CloudLinux) shared hosting.
- **Deploy model:** the live docroot is a git checkout. **Pushing to `master`
  triggers a `git pull` on the server** (cPanel Git Version Control / deploy
  hook). There is no separate build/CI deploy — CI only *validates*.
- **To deploy:** review locally → `git push origin master`. The server pulls the
  new tree (including file moves and `.htaccess`) atomically.
- **Smoke test after deploy:**
  ```bash
  curl -I https://ethioware.org/WI10092516     # expect 200
  curl -I https://ethioware.org/               # expect 200
  ```
- **`.htaccess` is load-bearing** (see §5). The host clearly honours
  `mod_rewrite`; compression/caching are added under `<IfModule>` guards so they
  no-op if a module is missing.

### ⚠️ Security note
`cognify/.htaccess` contains a **plaintext `JWT_SECRET`** (CloudLinux env-var
block) committed to the repo. The GitHub remote is `github.com/Ethioware/ethioware.et`.
If that repo is public, the secret is exposed and **should be rotated** and moved
out of version control. `GOOGLE_CLIENT_ID` in the same file is public and fine.

---

## 3. Tech stack

- HTML5 / CSS3 (custom properties, fl/grid, glassmorphism) / vanilla ES6 JS.
- SCSS sources in `assets/scss/` (compiled output committed as `assets/css/styles.css`; no automated SCSS build wired in CI).
- Libraries via CDN: ScrollReveal, Font Awesome, Remix Icon, Google Fonts,
  EmailJS (contact forms), Google Analytics, Trustpilot, Google One Tap.
- `cognify/` — a Google-OAuth auth page for a companion Chrome extension (§6).
- Dev tooling: `html-validate` (only dev dependency). `node_modules/` is gitignored.

---

## 4. Repository layout (current)

```
/                         repo root = site docroot
├── index.html            homepage (large, ~78KB)
├── apply.html            application page
├── pay.html              payment / donation page
├── privacy.html          privacy policy
├── anteneh.html / biniyam.html / samuel.html   team profiles
├── sitemap.xml           242 URLs (primary + all certs); referenced by robots.txt
├── robots.txt
├── .htaccess             routing + caching + compression (see §5)
│
├── certificates/         235 certificate/interview pages (WI*/MB*/EB*/ES*/SEB*/MEB*/…)
│                         served at root short URLs via internal rewrite
│
├── assets/
│   ├── css/  styles.css, bio.css
│   ├── js/   main.js, script.js, bio.js, sendmail.js, scrollreveal.min.js
│   ├── img/  logos, certificate scans (WebP), portraits, banners
│   ├── scss/ SCSS sources
│   ├── resumes/ , reviews/
│
├── cognify/              Google-OAuth auth subapp for a Chrome extension
│   ├── auth.html / auth.js / auth.css
│   ├── .htaccess         ⚠️ contains JWT_SECRET (see §2)
│   └── api/v1/auth/exchange/   backend token-exchange endpoint (server-side)
│
├── pages/template.html   reference template
├── ci/                   test scripts + lists (see §7)
└── .github/workflows/ci.yml
```

Real top-level pages are lowercase (`index`, `apply`, `pay`, `privacy`,
`anteneh`, `biniyam`, `samuel`). Everything matching an uppercase code pattern
is a certificate page and lives in `certificates/`.

---

## 5. Certificate short-URL system (critical — read before touching `.htaccess` or moving files)

Learner certificates are published as **`https://ethioware.org/<CODE>`** (e.g.
`/WI10092516`), with no extension and **no `/certificates/` in the path**. These
URLs are printed/shared and **must never change.**

**How it works:** the HTML lives in `certificates/<CODE>.html`, but `.htaccess`
does an **internal rewrite** (not a 301) so the address bar still shows `/<CODE>`:

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{DOCUMENT_ROOT}/certificates/$1.html -f
RewriteRule ^([A-Za-z0-9_-]+)(?:\.html)?$ certificates/$1.html [L]
```

Key properties:
- Fires **only** when the request isn't a real file/dir **and** a matching
  `certificates/<CODE>.html` exists → automatically covers **every** code prefix
  (WI, MB, EB, ES, SEB, MEB, CMI, DI, RI, VI, MI, SI, WD, TS, LB, EM, SM, V…) and
  any future ones, with no prefix list to maintain.
- Because the served URL is `/<CODE>` (root level), the cert pages' **relative**
  asset paths (`assets/img/…`) resolve against the site root and keep working.
  → **Cert pages must use root-relative asset paths**, not `../`.
- The previous setup 301-redirected `/WI…` to a non-existent `Certificates/`
  directory (capital C) — that was broken; do not reintroduce a redirect here.

**Adding a new certificate page:**
1. Create `certificates/<CODE>.html` (copy an existing one as a template).
2. Add its image to `assets/img/<CODE>.webp` (see §8 for conversion).
3. Add a canonical tag: `<link rel="canonical" href="https://ethioware.org/<CODE>" />`.
4. Regenerate the sitemap: `python3 ci/gen-sitemap.py`.
5. Verify: `python3 ci/htaccess-route-check.py` and `python3 ci/check-assets.py`.

---

## 6. cognify/ auth subapp

A standalone Google Sign-In page used by a companion **Chrome extension**
(`ethioware.org/cognify/auth?source=extension`). Flow: Google returns a JWT →
page redirects back to the extension with the token → extension exchanges it at
`POST /cognify/api/v1/auth/exchange` for a backend JWT. The exchange endpoint is
**server-side** (not part of this static tree). `robots.txt` disallows `/cognify/`.
Full notes in `cognify/README.md`.

---

## 7. CI & automated testing

Workflow: `.github/workflows/ci.yml` (runs on push/PR to `master`). **CI does not
deploy** — deployment is the server-side `git pull` (§2). Jobs:

| Job | What it checks |
|-----|----------------|
| `html-primary` | `html-validate` on the primary pages listed in `ci/primary-html.txt` (config: `.htmlvalidate.json`). |
| `links-offline` | `lychee` offline: local `href`/`src`/`url()` resolve for primary pages + `assets/`. |
| `js-syntax` | `node --check` on `biniyam.js`, `cognify/auth.js`. |
| `cert-routes` | `ci/htaccess-route-check.py` — emulates `.htaccess`; asserts all 235 cert short URLs + 7 top pages resolve (478 paths). |
| `assets` | `ci/check-assets.py` — every local ref across **all 244 pages** resolves; baseline `ci/known-missing-assets.txt` accepts the current backlog, **fails on any new break**. |

**Run everything locally:**
```bash
npm ci
npm run lint:html
node --check biniyam.js && node --check cognify/auth.js
python3 ci/htaccess-route-check.py
python3 ci/check-assets.py
python3 ci/gen-sitemap.py        # regenerate sitemap.xml when pages change
```

`ci/` helper scripts (all stdlib Python 3, no deps):
- `htaccess-route-check.py` — route resolution emulator.
- `check-assets.py` — site-wide asset/link integrity (models cert pages served at root).
- `known-missing-assets.txt` — baseline of 52 not-yet-supplied refs; remove a line when you add the file.
- `gen-sitemap.py` — deterministic sitemap generator (lastmod from git).
- `primary-html.txt` — the page list the lint/link jobs use.

---

## 8. Conventions & gotchas

- **Images:** prefer **WebP**. Certificate scans were 2000×1414 PNGs at ~4 MB;
  converted at `convert <src> -quality 86 -define webp:method=6 <dst>.webp`
  (~350 KB, native resolution preserved). All target browsers support WebP.
- **Asset paths in cert pages must be root-relative** (`assets/…`) — see §5.
- **Editor config:** `.editorconfig` — UTF-8, LF, 2-space indent, final newline,
  trim trailing whitespace. (Beware: 20 cert filenames historically had a stray
  space before `.html`; all normalised — keep new names space-free.)
- **html-validate config** (`.htmlvalidate.json`) relaxes `no-raw-characters`
  and downgrades a couple of rules to warnings.
- **Don't commit** `node_modules/` (gitignored) or report PDFs/build artifacts.
- **`assets/img/old-*`** are local logo backups — untracked, redundant (originals
  in git history), safe to delete.

---

## 9. Work completed in the 2026-06-21 session

Six commits on `master` (local at handoff time — confirm whether pushed):

```
5f66c9b  test: site-wide asset-integrity check in CI
f1ebd8c  feat: sitemap.xml + canonical/meta + caching/compression
7384c77  fix: broken homepage-anchor nav links
5994f99  perf: heavy images → WebP (332MB → 30MB)
b8a29fc  refactor: 235 cert pages → certificates/, stable short URLs
438ec7c  chore: baseline pre-existing WIP
```

Highlights:
- **Structure:** root reduced from ~244 files to **7** real pages; 235 certs
  moved to `certificates/`; broken `Certificates/` 301 replaced with the
  internal rewrite (§5); removed 2 committed report PDFs.
- **Performance:** images **332 MB → ~30 MB** (91 cert scans + 2 portraits to
  WebP; 6 orphan heavy PNGs removed); gzip + far-future caching added to `.htaccess`.
- **Correctness:** fixed **19 dead nav links** (`/about` → `/#about`; all 7 on
  `pay.html` were broken); fixed 28 pages referencing a non-existent
  `education.png`; fixed 3 CI-blocking html-validate errors.
- **SEO:** created the missing `sitemap.xml` (242 URLs incl. all certs);
  canonical tags on 235 certs + 6 primary pages; meta descriptions on 6 pages.
- **Testing:** added `cert-routes` + `assets` CI gates covering all 244 pages.

All local CI gates pass.

---

## 10. Outstanding work / TODO

1. **Investor/partner/donor content (not started).** Sharpen `index.html` +
   `pay.html` messaging. Needs real inputs: current traction numbers, the
   specific ask (investment vs. partnership vs. donations), what each partner
   logo represents, any testimonials/metrics.
2. **49 missing certificate images.** Pages reference cert/portrait images that
   don't exist in any format (e.g. `assets/img/WI083101.png`, `MeronM.png`,
   `RedietT.png`). Baselined in `ci/known-missing-assets.txt`. As files are
   supplied: add to `assets/img/` (WebP), update the page if the extension
   changes, and delete the matching baseline line.
3. **Certificate template markup cleanup.** `html-validate certificates/*.html`
   reports ~765 errors: mostly `close-order` (unbalanced `<div>`/`<section>`),
   **18 duplicate `<title>`** tags, and some unescaped `&`. Browsers tolerate it,
   but worth normalising the shared template once (would also let certs join the
   `html-primary` lint job).
4. **Rotate the committed `JWT_SECRET`** and move it out of git (§2).
5. **Housekeeping:** delete the untracked `assets/img/old-*` backups.
6. **Optional:** the user's newer cert WebP images (~30 files) are ~800 KB each
   vs ~350 KB for the batch-converted ones — could be re-compressed for consistency.

---

## 11. Contacts & links

- Site: https://ethioware.org · Repo: https://github.com/Ethioware/ethioware.et
- Email: info@ethioware.org · parents@ethioware.org
- Socials: LinkedIn/Twitter/YouTube/Telegram/Instagram/Facebook `@ethioware`
- Stated stats (homepage): 210+ graduates, 35+ mentors, 3 partnerships, 8+ nationalities.
