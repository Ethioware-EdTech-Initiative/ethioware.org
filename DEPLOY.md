# Deploying Ethioware (GitHub → cPanel)

This repo auto-deploys to cPanel via GitHub Actions. The flow:

```
open PR → CI runs (required checks) → merge to master → deploy job rsyncs over SSH → live
```

- **Tests gate everything.** The `deploy` job `needs:` every test job, so a failing
  test means **no deploy**. With master protected (below), a PR can't even merge
  until checks are green — so nothing broken reaches production.
- **rsync, no `--delete`.** Only changed files are uploaded; server-only files
  (`config.php`, manually-managed backends, uploads) are never touched. The
  exclude list is `.deployignore`.

Defined in `.github/workflows/ci.yml`.

---

## One-time setup

### 1. Enable SSH on cPanel
cPanel → **SSH Access** (or ask your host to enable it). Note the **SSH port**
(often `22`; some hosts use a custom one like `21098`).

### 2. Create a deploy SSH key (no passphrase — CI can't type one)
Generate it **outside the repo** (e.g. in `~/.ssh`) so the private key never sits
in the working tree:
```bash
ssh-keygen -t ed25519 -f ~/.ssh/ethioware_deploy -N "" -C "github-actions-deploy"
# creates: ~/.ssh/ethioware_deploy (private)  ~/.ssh/ethioware_deploy.pub (public)
```
Add the **public** key to cPanel → **SSH Access → Manage SSH Keys → Import**
(paste `~/.ssh/ethioware_deploy.pub`), then **Authorize** it.

Test from your machine (accept the host key once):
```bash
ssh -i ~/.ssh/ethioware_deploy -p <PORT> <CPANEL_USER>@<SSH_HOST>
```

> ⚠️ **Never commit the private key.** Put its contents in the `CPANEL_SSH_KEY`
> secret (step 3), then you can delete the local copy. `.gitignore` already
> blocks `ethioware_deploy*`, and the `guard` CI check fails on any committed
> private key — but keep keys out of the repo folder regardless.

### 3. Add GitHub repository secrets
GitHub repo → **Settings → Secrets and variables → Actions → New repository secret**.
Create exactly these (names must match the workflow):

| Secret | Value | Example |
|--------|-------|---------|
| `CPANEL_SSH_HOST` | server hostname or IP | `server123.web-hosting.com` |
| `CPANEL_SSH_PORT` | SSH port | `22` |
| `CPANEL_SSH_USER` | cPanel username | `ethiowzj` |
| `CPANEL_SSH_KEY`  | **private** key contents (whole `ethioware_deploy` file, all lines) | *(paste the entire private key file)* |
| `CPANEL_DEPLOY_PATH` | docroot to publish into (no trailing slash) | `/home/ethiowzj/public_html` |

> Paste the **private** key (the file without `.pub`), including the BEGIN/END lines.

### 4. Prepare the database + backend config on the server (once)
The application form (`apply-submit.php`) reuses the Research Scholars database.
- Import the table: cPanel → phpMyAdmin → select the DB → **Import** `db/applications.sql`
  (or `mysql -u USER -p DBNAME < db/applications.sql`).
- Ensure `research-scholars/config.php` exists on the server with real MySQL
  credentials. **It is gitignored and must live only on the server** — deploy
  never uploads or deletes it.

> **Commit the non-secret Research Scholars files** (`save_signup.php`,
> `signup.html`, `index.html`, `schema.sql`) so they deploy automatically.
> Only `config.php` stays server-only. Until then, those files are managed by
> hand on the server.

### 5. Protect master & require the checks
GitHub repo → **Settings → Branches → Add branch ruleset (or rule)** for `master`:
- ✅ **Require a pull request before merging**
- ✅ **Require status checks to pass before merging** → select all of:
  - `HTML (primary pages)`
  - `Links & assets (offline)`
  - `JavaScript syntax`
  - `Certificate short URLs resolve`
  - `Asset references resolve (all pages)`
  - `PHP syntax`
  - `Repo hygiene & secret guard`
- ✅ **Require branches to be up to date before merging**
- ✅ **Do not allow bypassing the above settings** (optional, stricter)

> Do **not** add `Deploy to cPanel (rsync over SSH)` as a required check — it only
> runs on push to master, not on PRs, so it would block merges forever.

### 6. Avoid double-deploys
If cPanel was previously auto-pulling this repo into `public_html` (the old
"git pull on push" model), **disable that** so rsync is the single source of
truth. Make sure `public_html` is a plain folder (no competing `.git` clone
fighting the rsync). Deploy excludes `.git`, so it won't create one.

---

## Day-to-day workflow

```bash
git checkout -b my-change
# …edit…
git commit -am "feat: my change"
git push -u origin my-change          # opens/updates a PR
```
1. Open a PR to `master`. CI runs the seven checks.
2. When they're green, **merge**. The push to `master` triggers the `deploy` job.
3. `deploy` waits for the checks to pass again on master, then rsyncs to cPanel.

Manual re-deploy (no code change): repo → **Actions → CI → Run workflow** on
`master` (uses `workflow_dispatch`).

### Verify a deploy
```bash
curl -I https://ethioware.org/                 # 200
curl -I https://ethioware.org/WI10092516        # 200 (a certificate short URL)
# Submit the apply form once and confirm a new row in the `applications` table.
```

### Rollback
Revert the offending commit on `master` (e.g. `git revert <sha>` via a PR, or
GitHub's "Revert" button). Merging the revert re-runs CI and redeploys the
previous good state. (No `--delete`, so reverting a file edit restores it;
reverting a *new* file leaves the old copy on the server until a `--delete`
sync — remove it by hand if needed.)

---

## The required checks (what every push must pass)

| Check | Guards against |
|-------|----------------|
| HTML (primary pages) | invalid markup on the main pages |
| Links & assets (offline) | broken local links / missing assets on primary pages |
| JavaScript syntax | `node --check` parse errors in checked-in JS |
| Certificate short URLs resolve | a `/WIxxxx` certificate URL 404-ing after a change |
| Asset references resolve (all pages) | any **new** broken image/link across all 244 pages |
| PHP syntax | `php -l` parse errors in `apply-submit.php` etc. |
| Repo hygiene & secret guard | committing `config.php` (DB creds), `node_modules/`, or losing `.deployignore` |

Run them all locally before pushing:
```bash
npm ci
npm run lint:html
node --check biniyam.js && node --check cognify/auth.js
python3 ci/htaccess-route-check.py
python3 ci/check-assets.py
for f in $(find . -name '*.php' -not -path './node_modules/*'); do php -l "$f"; done
```

---

## Troubleshooting

- **Deploy job: `Permission denied (publickey)`** — the public key isn't authorized
  in cPanel, or `CPANEL_SSH_USER`/`CPANEL_SSH_HOST`/`CPANEL_SSH_PORT` is wrong.
- **`Host key verification failed`** — first connect uses `accept-new`; if the
  server key changed, it will refuse. Re-check the host/port.
- **Files uploaded but page unchanged** — confirm `CPANEL_DEPLOY_PATH` is the real
  docroot (subdomains/addon domains have their own folder).
- **Apply form returns "Server is not configured yet"** — `research-scholars/config.php`
  is missing on the server (see step 4).
- **A deleted file is still live** — expected (no `--delete`); remove it on the
  server by hand, or do a one-off `rsync --delete` after verifying excludes.
