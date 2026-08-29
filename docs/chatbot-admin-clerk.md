# Chatbot admin — Clerk sign-in

Staff sign in to `chatbot/admin/` with their own Clerk account instead of the
single shared password. This closes open decision §12.4 in
[CHATBOT_SPEC.md](CHATBOT_SPEC.md): the dashboard shows every lead's name,
e-mail and phone number, so "who looked at this" should be answerable, and
removing someone's access should not mean rotating a password five people know.

The shared password still works as a **break-glass fallback** until you turn it
off, and everything below is inert until `CLERK_PUBLISHABLE_KEY` is set — a
server with no Clerk config behaves exactly as it did before.

---

## How it works

```
browser                          server
───────                          ──────
Clerk sign-in widget (clerk-js)
  └─ session JWT (≈60 s life)
        │  POST chatbot/admin/clerk-callback.php
        ▼
                        verify RS256 signature against Clerk's public JWKS
                        check iss / exp / nbf / azp / sub      (clerk.php)
                        resolve the user's e-mail
                        check it against the allowlist
                        ▼
                        ordinary PHP admin session  (auth.php)
```

Only `clerk-callback.php` ever trusts a Clerk token. Every later request is
authorised by the same PHP session the shared password produced, so
`index.php`, `view.php` and `export.php` are unchanged — including the 8-hour
idle timeout and the `HttpOnly` / `Secure` / `SameSite=Lax` cookie.

Verification is hand-rolled against `ext-openssl` because this host is cPanel
shared hosting with no Composer. It is covered by `ci/clerk-auth-test.php`,
which runs on every PR (`php ci/clerk-auth-test.php` to run it locally).

---

## Setup

### 1. Create the Clerk application

1. In the [Clerk dashboard](https://dashboard.clerk.com), create an application
   for Ethioware. Enable whichever sign-in methods the team will use — Google
   is the least friction if staff already have Workspace accounts.
2. **Restrict who can sign up.** Under **Configure → Restrictions**, either turn
   off public sign-up and invite staff, or set an allowlist of e-mail domains.
   The server-side allowlist in step 3 is the real gate, but there is no reason
   to let strangers create accounts on the instance at all.

### 2. Put the keys in `chatbot/config.php`

`chatbot/config.php` lives only on the server — it is gitignored and excluded
from deploys, so a deploy never overwrites it. Copy the new block from
[`chatbot/config.example.php`](../chatbot/config.example.php) and fill in:

```php
define('CLERK_PUBLISHABLE_KEY', 'pk_live_…');   // Clerk dashboard → API Keys
define('CLERK_SECRET_KEY',      'sk_live_…');
define('CLERK_ALLOWED_DOMAINS', 'ethioware.org');
```

The publishable key is the only value the verifier needs — the token issuer,
the JWKS endpoint and the clerk-js origin are all derived from it, so there is
no separate URL to keep in sync. `clerk-js` is loaded from your own Clerk
Frontend API host, not a third-party CDN.

### 3. Decide who may open the dashboard

`CLERK_ALLOWED_EMAILS` (exact addresses) and `CLERK_ALLOWED_DOMAINS` (the part
after `@`) are both comma-separated and both case-insensitive. A user needs to
match one of them.

> **This fails closed.** With both lists empty, *every* Clerk account is
> rejected. Having an account on the Clerk instance is not by itself permission
> to read the leads table.

Domains match exactly: `ethioware.org` admits `ada@ethioware.org` but not
`eve@mail.ethioware.org` and not `eve@notethioware.org`.

### 4. Verify, then retire the shared password

1. Open `https://ethioware.org/chatbot/admin/login.php` and sign in.
2. Confirm the header now reads **Signed in as …** with the right person.
3. Confirm the audit row landed:
   ```sql
   SELECT created_at, event, detail, ip_address FROM chatbot_events
   WHERE event IN ('admin_login_ok','admin_login_fail')
   ORDER BY created_at DESC LIMIT 10;
   ```
   Clerk logins record `clerk: ada@ethioware.org`; the fallback records
   `shared password`.
4. Have every staff member sign in once, then turn the fallback off in
   `chatbot/config.php`:
   ```php
   define('CHATBOT_ADMIN_PASSWORD_FALLBACK', false);
   ```
   The password form disappears and password POSTs are refused. Blanking
   `ADMIN_PASSWORD_HASH` has the same effect.

Removing someone later is a Clerk dashboard action (delete or ban the user), or
drop their address from `CLERK_ALLOWED_EMAILS`. No shared secret to rotate.

---

## Optional settings

| Setting | When you need it |
|---|---|
| `CLERK_JWT_TEMPLATE` | Clerk's default session token carries no e-mail, so the server looks it up once per login via the Backend API. Add a JWT template with `{"email": "{{user.primary_email_address}}"}`, name it here, and that call disappears — `CLERK_SECRET_KEY` then isn't needed at all. |
| `CLERK_AUTHORIZED_PARTIES` | Extra origins accepted in a token's `azp` claim. Only needed if the dashboard is reachable on more than one hostname (e.g. also `https://www.ethioware.org`). The origin serving the page is always accepted. |
| `CLERK_JWT_ISSUER` | Overrides the issuer derived from the publishable key. Only for a non-standard Clerk setup. |
| `CLERK_API_URL` | Overrides `https://api.clerk.com`. |

---

## Troubleshooting

Failures are vague in the browser and specific in `chatbot_events.detail` —
check the table first.

| `detail` in `chatbot_events` | Cause |
|---|---|
| `clerk: issuer mismatch` | `CLERK_PUBLISHABLE_KEY` is from a different Clerk instance than the one the browser signed into (a common test-vs-live mix-up). |
| `clerk: no signing key for kid` | JWKS could not be fetched — outbound HTTPS from the server blocked, or the key set is genuinely rotating. Check the PHP error log for `chatbot/clerk: JWKS fetch`. |
| `clerk: token expired` | Server clock skew beyond 30 s. |
| `clerk: unauthorized party` | The dashboard was opened on a hostname not in `CLERK_AUTHORIZED_PARTIES`. |
| `clerk denied (no allowlist configured): …` | Step 3 was skipped. |
| `clerk denied (not on allowlist): ada@…` | Real person, wrong list — add the address or domain. |
| `clerk denied (no e-mail on account): user_…` | No e-mail claim and no working Backend API lookup: set `CLERK_SECRET_KEY`, or add the `CLERK_JWT_TEMPLATE` above. |

Other symptoms:

- **"Could not load the sign-in widget."** — the browser could not reach the
  Clerk Frontend API host. Check the network tab; on a production instance this
  usually means the Clerk DNS records for `clerk.<domain>` aren't live yet.
- **Locked out entirely** — sign-in failures share the existing budget of
  5 attempts per IP per 15 minutes. Wait it out, or use the shared-password
  fallback if it's still enabled.

---

## What this does not cover

Only *logins* are attributed. Viewing a transcript or exporting the CSV is not
recorded per user, because `chatbot_events.event` is an `ENUM` and new event
types need a schema change (`db/chatbot.sql`). If per-action attribution is
ever required — say, a data-protection commitment to a partner — that is the
follow-up, and the identity it would record is already sitting in the session.
