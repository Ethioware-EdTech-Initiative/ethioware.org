# Ethioware Website Chatbot — Implementation Specification

> Developer-ready implementation plan for the ethioware.org AI chatbot.
> Audience: CTO + implementing contractor. Everything here is decided unless
> explicitly listed in §12 (Open decisions). Ground truth for the existing
> site is `TECHNICAL_OVERVIEW.md`; this spec references its file paths and
> conventions throughout.

---

## 1. Executive summary

We are adding a site-wide AI chat widget to ethioware.org that answers questions about Ethioware's programs from a maintained knowledge folder, captures leads conversationally, refers enrollment-ready users to the existing `apply.html` flow with abandonment tracking, and hard-gates partnership/pricing/donation answers behind a name+email signup. The stack matches the existing site exactly: a plain vanilla-JS widget (one `<script>` tag per page), PHP 8.x endpoints on the existing shared cPanel/LiteSpeed host calling Google's Gemini API (free tier) per request, lead/conversation storage in **new tables in the existing MySQL database** (see §3 for why not MongoDB), and a simple password-protected PHP admin page for the sales/education teams.

---

## 2. Architecture overview

```
Visitor's browser (any page)
  └── /assets/js/chatbot.js  (single script tag; injects its own CSS + DOM)
        │  POST JSON, same-origin
        ▼
  /chatbot/api/chat.php ───────────► Gemini API (generateContent, REST, API key)
        │                             system prompt = base persona + knowledge files
        │                             (partnership KB file withheld until gate passed)
        ▼
  MySQL (existing DB, new tables: chatbot_leads, chatbot_conversations, chatbot_events)
        ▲
        │ beacons from apply.html          UPDATE on successful submit
  /chatbot/api/track.php ◄────────  apply.html (?ref= token)
                                     apply-submit.php (chat_ref column)
        ▲
        │ reads
  /chatbot/admin/index.php  (PHP-session login; sales/education staff)
```

Components:

1. **Widget** — `assets/js/chatbot.js` + `assets/css/chatbot.css`. One deferred script tag on every page. No framework, no build. Auto-greets after 10 s (once per session), matches the site's glassmorphism + dark/light theme.
2. **Chat endpoint** — `chatbot/api/chat.php`. Stateless PHP: loads knowledge files, loads conversation history from MySQL, calls Gemini once per user message, enforces the partnership gate server-side, persists everything, returns JSON. No persistent process — one HTTP request in, one Gemini REST call, one HTTP response out. This is the correct shape for shared hosting.
3. **Tracking endpoint** — `chatbot/api/track.php`. Receives `navigator.sendBeacon` events from `apply.html` (referral started / step reached).
4. **Database** — three new tables in the existing MySQL database (the one `research-scholars/config.php` already connects to), plus one nullable column added to `applications`.
5. **Knowledge base** — `chatbot/knowledge/*.md`, edited by the team, deployed through the normal PR → CI → rsync pipeline (one `.deployignore` change required, §10).
6. **Admin dashboard** — `chatbot/admin/index.php`, plain PHP page behind a session login.
7. **Secrets** — `chatbot/config.php` (Gemini API key, admin password hash). Named `config.php` deliberately: the existing `.gitignore` rule (`config.php`, matches at any depth) and `.deployignore` rule already exclude it, and the `guard` CI job already fails if it's committed. Zero new secret-handling machinery.

---

## 3. Database design

### 3.1 Recommendation: extend the existing MySQL database. Do not use MongoDB Atlas for this.

This was weighed on data shape and query patterns, not incumbency. The conclusion is MySQL, for four reasons in descending order of weight:

1. **There is no reliable access path from shared cPanel PHP to Atlas.** The MongoDB PHP library requires the `mongodb` PECL extension. On CloudLinux shared hosting that extension is only available if the host has compiled it into the PHP Selector — frequently it isn't, and you cannot install PECL extensions yourself. The historical fallback — Atlas's HTTPS Data API — was **deprecated by MongoDB with end-of-life in September 2025**, so there is no supported driver-free HTTP path anymore. Building this feature on an access method that may not exist on the host is the single biggest project risk, and it's avoidable.
2. **The dashboard's core query is a cross-store join if leads live in Mongo.** "Which chatbot-referred users completed an application?" joins chatbot leads against the MySQL `applications` table. With both in MySQL it's one `LEFT JOIN`. With leads in Atlas it's two round-trips and application-side matching in PHP — more code, more failure modes, for the most important query the sales team runs.
3. **The volume makes the "MySQL can't handle session data" concern moot.** ~100 users/month × ~15 messages ≈ a few thousand rows/month. The "document-shaped" part (the message transcript) fits cleanly in one `LONGTEXT` JSON column per conversation — we never query inside individual messages; we only ever fetch a transcript whole by `session_id`, which is an indexed key lookup.
4. **Operationally free.** Same `rsp_get_connection()` pattern as `apply-submit.php`, same phpMyAdmin the team already uses, same backup story, one `db/*.sql` import like `db/applications.sql`.

**Where MongoDB would win, for the record:** if traffic grew ~10×+ and you wanted message-level analytics (querying inside transcripts), or if the backend ever moves off shared cPanel to a VPS/container where the driver is installable. The Atlas credits are better preserved for a future dedicated backend (e.g., if the Cognify extension's server side grows) than spent working around a hosting limitation here. Revisit at 10× per §11.

### 3.2 Schema — `db/chatbot.sql` (new file, imported once via phpMyAdmin, same DB as `applications`)

```sql
-- Chatbot leads: one row per chat session that yielded ANY lead signal.
CREATE TABLE IF NOT EXISTS chatbot_leads (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id CHAR(36) NOT NULL,              -- client-generated UUID v4
  referral_token CHAR(36) NULL,              -- set when bot hands off to apply.html
  source ENUM('chatbot','chatbot_apply') NOT NULL DEFAULT 'chatbot',
  status ENUM('chat_only','gated_captured','referred','apply_started','apply_completed')
    NOT NULL DEFAULT 'chat_only',
  -- Contact (filled progressively; email is the gate minimum alongside name)
  name VARCHAR(150) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  organization VARCHAR(150) NULL,
  -- Classification
  intent ENUM('general','enrollment','partnership','pricing','donation',
              'investment','support','other') NOT NULL DEFAULT 'general',
  program_interest VARCHAR(80) NULL,         -- free text from a controlled list, see §7
  -- Meta
  page_first_seen VARCHAR(255) NULL,         -- path where the widget was opened
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_session (session_id),
  KEY idx_referral (referral_token),
  KEY idx_status (status),
  KEY idx_intent (intent),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Full transcripts: one row per session; messages appended as a JSON array.
CREATE TABLE IF NOT EXISTS chatbot_conversations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id CHAR(36) NOT NULL,
  messages LONGTEXT NOT NULL,                -- JSON: [{"role":"user|model","text":"...","t":unixtime}, ...]
  message_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ip_address VARCHAR(45) NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_message_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_session (session_id),
  KEY idx_last (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Event stream: powers abandonment detection and dashboard stats.
CREATE TABLE IF NOT EXISTS chatbot_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id CHAR(36) NULL,                  -- null for apply-page events matched by token
  referral_token CHAR(36) NULL,
  event ENUM('widget_open','greeted','gate_shown','gate_passed','referred',
             'apply_started','apply_step','apply_completed','quota_fallback')
    NOT NULL,
  detail VARCHAR(255) NULL,                  -- e.g. step number, program name
  page VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_token (referral_token),
  KEY idx_event_time (event, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Link a completed application back to the chat session that referred it.
ALTER TABLE applications
  ADD COLUMN chat_ref CHAR(36) NULL AFTER ip_address,
  ADD KEY idx_chat_ref (chat_ref);
```

### 3.3 Abandonment model

There are **no background workers on shared hosting**, so "abandoned" is a *derived state computed at read time*, not a status written by a cron:

- `apply_started` event exists for a `referral_token`, **and**
- no `applications.chat_ref` row matches that token, **and**
- the `apply_started` event is older than 30 minutes.

The dashboard query (§8) computes this with one `LEFT JOIN`. No scheduler, no cleanup job, nothing to keep alive.

---

## 4. Gemini integration

### 4.1 Model choice: `gemini-2.5-flash`, with `gemini-2.5-flash-lite` as the configured fallback

- **Why 2.5 Flash:** the bot must simultaneously answer well from injected context *and* emit reliable structured output (intent classification + extracted contact fields) in a single call. Flash's instruction-following is meaningfully better than Flash-Lite for the gating logic, and its free-tier daily quota comfortably covers our volume.
- **Why Flash-Lite as fallback:** its free tier allows roughly 4× the requests per day. `chat.php` should read the model name from `chatbot/config.php` (`GEMINI_MODEL`) so switching is a config change, not a code change; on a 429 from Flash, retry once against Flash-Lite automatically.
- **Verify current limits at implementation time** at ai.google.dev/pricing — Google adjusts free-tier quotas. At the time of writing, free-tier 2.5 Flash allows on the order of 10 requests/min and 250 requests/day; Flash-Lite ~15/min and ~1,000/day; both ~250k tokens/min.

### 4.2 Budget math for ~100 users/month

- 100 users/month ≈ 3–4 sessions/day average; assume a peak day of 15 sessions.
- Average ~12 user messages/session → ~180 Gemini calls on a peak day. Under the ~250 RPD Flash quota with headroom; far under Flash-Lite's if we fall back.
- Per-call tokens: knowledge base ≤ 12k (enforced, §5) + system persona ~1k + trimmed history ~2k + reply ≤ 512 output tokens ≈ **~15k tokens/call**. At 10 req/min worst case that's 150k TPM — inside the ~250k TPM limit.
- Conclusion: no context caching, no batching, no paid tier needed. **Guardrails so one abusive visitor can't burn the day's quota:** hard cap of 30 messages/session and 60 messages/IP/day, enforced in `chat.php` against `chatbot_conversations.message_count` and a `chatbot_events` count. Over-cap or quota-exhausted responses degrade gracefully (§4.5).

### 4.3 Call structure

One Gemini `generateContent` REST call per user message from `chatbot/api/chat.php`:

```
POST https://generativelanguage.googleapis.com/v1beta/models/{GEMINI_MODEL}:generateContent
Header: x-goog-api-key: {GEMINI_API_KEY from chatbot/config.php}

{
  "system_instruction": { "parts": [{ "text": "<persona + rules + knowledge base + gate state>" }] },
  "contents": [ ...conversation history as alternating user/model turns..., {"role":"user","parts":[{"text": userMessage}]} ],
  "generationConfig": {
    "temperature": 0.4,
    "maxOutputTokens": 512,
    "responseMimeType": "application/json",
    "responseSchema": {
      "type": "OBJECT",
      "properties": {
        "reply":   { "type": "STRING" },
        "intent":  { "type": "STRING", "enum": ["general","enrollment","partnership","pricing","donation","investment","support","other"] },
        "program": { "type": "STRING", "enum": ["Research Scholars Program","Software Engineering Basics","Engineering Basics","Law Basics","Medicine Basics","Unsure",""] },
        "contact": { "type": "OBJECT", "properties": {
            "name": {"type":"STRING"}, "email": {"type":"STRING"},
            "phone": {"type":"STRING"}, "organization": {"type":"STRING"} } },
        "action":  { "type": "STRING", "enum": ["none","refer_apply","request_gate"] }
      },
      "required": ["reply","intent","action"]
    }
  }
}
```

The single structured-output call gives us the visible reply, the intent classification, extracted contact details, and a proposed action — **one API call per message does everything**, which is what keeps us on the free tier. The `program` enum must mirror `apply.html`/`apply-submit.php`/`db/applications.sql` values (the existing three-way sync becomes a four-way sync — flag this in code comments in all four places), with `Research Scholars Program` and `Unsure` as chatbot-only additions that never flow into `applications`.

### 4.4 Conversation memory

- History lives server-side in `chatbot_conversations.messages`; the client only ever sends `{session_id, message}` — the client cannot forge history or gate state.
- Send Gemini the **last 12 turns** (6 exchanges) verbatim. Older turns are dropped, except: once contact details or a program interest are captured, they're stored on the lead row and restated in the system prompt ("Known visitor info: name=…, program=…"), so the bot never re-asks. This bounds history at ~2k tokens regardless of conversation length.

### 4.5 System prompt design principles (full text authored during implementation, `chatbot/api/prompt.php`)

1. Persona: warm, concise Ethioware guide; answers **only** from the provided knowledge; if the answer isn't in the knowledge, say so and offer `info@ethioware.org` — never invent program details, prices, or dates.
2. Always steer toward one of three outcomes: program interest → apply referral; partnership/pricing → gate; everything else → helpful answer + soft ask.
3. The gate rule (see §7.3) and the current gate state (`GATE: LOCKED` / `GATE: PASSED`) are injected per call.
4. Formatting: ≤ 3 short paragraphs, no markdown tables, plain links.
5. English only. (Amharic note: adding it later means per-language knowledge files and widget strings; Gemini itself handles Amharic — the model layer wouldn't change. Do not build any of this now.)

**Quota/failure fallback:** if Gemini returns 429/5xx after the one fallback-model retry, `chat.php` returns a static reply: "I'm briefly unavailable — leave your name and email and our team will get back to you," and the widget renders its built-in contact mini-form straight into `chatbot_leads` (`status='chat_only'`, `intent` from last known classification). An outage becomes lead capture instead of a dead widget. Log a `quota_fallback` event.

---

## 5. Knowledge base folder scaffolding

```
chatbot/knowledge/
├── 00-org.md            # mission, story, team, milestones, contact channels
├── 10-programs.md       # every program/track: audience, duration, outcomes, cohort dates
├── 20-enrollment.md     # how to apply, eligibility, what apply.html asks, FAQs
├── 30-mentorship.md     # mentorship model, publication pathways, certificates
├── 40-partnerships.md   # ⚠ GATED — partnership/sponsorship/pricing/donation details
└── 90-faq.md            # everything else, one "## Question" heading per answer
```

**Format rules (enforce socially, not in CI, for now):**
- Plain Markdown, one `#` title per file, `##` sections. Content team edits these directly on GitHub's web editor if they don't use git locally.
- Numeric prefixes control injection order; `40-partnerships.md` is **special-cased by filename** in `chat.php` — it is *excluded* from the system prompt until the session's gate is passed. This is the hard gate's teeth: the model cannot leak information it was never given (§7.3).
- Total budget: `chat.php` reads the folder with `glob()`, concatenates in filename order, and **truncates at 12,000 tokens (~48,000 chars) with a logged warning** — the content team gets a size ceiling, not a silent quality cliff. At this volume, re-reading the files per request costs nothing measurable; no cache layer.

**Update workflow:** knowledge edits are normal file edits → PR → CI → merge → rsync. No code changes, no schema changes; the required CI checks don't touch `chatbot/knowledge/`, so a content PR is green in minutes. This *does* require one `.deployignore` fix, because it currently excludes `*.md` globally — see §10.

---

## 6. Widget design

### 6.1 Loading and integration

- **One line added to every page**, before `</body>`:
  `<script src="/assets/js/chatbot.js" defer></script>`
  The script injects its own `<link>` to `/assets/css/chatbot.css` and builds its DOM — so the per-page footprint is a single tag. Use the leading-slash absolute path so it resolves identically on `/`, `/apply`, `/WI10092516` (certificate short URLs, served at root per `.htaccess`), and `/cognify/…`.
- Add the tag to the 7 primary pages, `pages/template.html`, and all `certificates/*.html` via a one-time scripted edit (`python3` or `sed` over the tree, then run `python3 ci/check-assets.py` and `npm run lint:html` locally before the PR). **Exclude `cognify/auth.html`** — it's a machine-facing OAuth redirect page for the Chrome extension; a chat popup there helps no one and could interfere with the token redirect.

### 6.2 Trigger logic

- On load, render only a small floating launcher bubble (bottom-right, 56 px circle, Ethioware logo).
- After **10 s** on the page, if the visitor hasn't interacted with the widget, expand a small greeting card above the bubble: "👋 Hi! Have questions about our programs? I can help." with the quick-reply chips visible. It does **not** open the full panel, does not overlay content, and auto-collapses after 15 s if ignored.
- Set `sessionStorage.cb_greeted = "1"` when the greeting shows — it never re-triggers during the session, including across page navigations. If a chat exists (`sessionStorage.cb_session`), skip greeting entirely and show an unread-dot on the bubble instead.
- Respect `prefers-reduced-motion` (no slide/bounce animation) and viewports < 380 px (greeting card suppressed; bubble only).

### 6.3 UX flow — what we're borrowing from Synthesia's chatbot, and why

Synthesia's widget is a SaaS sales-qualification bot; we borrow its *mechanics* and repurpose them for non-profit lead-gen:

1. **Proactive but polite greeting** (delayed popup, easily dismissed) — borrowed as-is; it's what surfaces the bot to visitors who'd never click a bubble.
2. **Quick-reply chips alongside free text** — greeting/opening chips: `Explore programs` · `How do I apply?` · `Partner with us` · `Something else`. Chips route intent *without* an LLM call for the first hop (each chip maps to a canned opening + sets a provisional intent), which saves quota and gives non-technical users a guided path. This matters more for us than for Synthesia: our visitors include parents and students who won't know what to type.
3. **Progressive information gathering** — the bot asks for name/email only when intent warrants it (enrollment interest or the partnership gate), never as an opening demand. For a non-profit, trust precedes contact info; a form-first bot reads as spam.
4. **Explicit human/sales handoff** — Synthesia routes high-intent users to sales; we route enrollment intent to `apply.html` (with the referral token) and partnership intent to a gated capture that the team follows up by email. The bot always offers `info@ethioware.org` as the human escape hatch.

Deliberately **not** borrowed: account-signup pressure, meeting-scheduling integration, and multi-step qualification questionnaires — wrong register for students and unnecessary at 100 users/month.

### 6.4 Theming

- Reuse the site's CSS custom properties from `assets/css/styles.css` (primary `hsl(215, 80%, 32%)`, existing background/text variables) so the widget tracks the dark/light theme automatically; read the same `localStorage` theme key `assets/js/main.js` uses, and fall back to `prefers-color-scheme`.
- Glassmorphism per the site pattern: `backdrop-filter: blur(10px)`, semi-transparent panel, subtle border. Poppins via the already-loaded Google Fonts (don't add a new font request; declare a system-font fallback since certificate pages may not load Poppins).
- Panel: 360 × 520 px desktop, full-width bottom sheet ≤ 480 px viewports. `z-index` above the site nav; never covers the nav toggle on mobile (bottom-anchored).

---

## 7. Lead capture & gating logic

### 7.1 Flow A — general inquiry

1. Visitor opens chat (or taps a chip). Widget generates `session_id` (`crypto.randomUUID()`), stores in `sessionStorage.cb_session`, sends first message.
2. `chat.php` upserts `chatbot_leads` (`status='chat_only'`, `page_first_seen`), appends to `chatbot_conversations`, calls Gemini, returns `{reply, chips?, gate:null}`.
3. If the model's extracted `contact` fields contain a plausible email (server-side `filter_var` validation — never trust extraction blindly), persist to the lead row. The bot may make **one** soft ask per session ("Want me to have someone email you more details?") — declining is respected; the system prompt forbids repeat asks.

### 7.2 Flow B — enrollment interest → apply.html handoff → abandonment tracking

1. Model classifies `intent='enrollment'`. The bot's job before handoff: **identify the program** (`program` field in the structured output; asks a clarifying question with program-name chips if `Unsure`), answer readiness questions from `10-programs.md`/`20-enrollment.md`, and softly collect name/email ("so our education team can follow up if you don't finish applying" — honest framing that directly serves the abandonment follow-up).
2. When the model returns `action='refer_apply'`, `chat.php` generates `referral_token` (UUID), sets it on the lead row with `status='referred'`, `source='chatbot_apply'`, `program_interest`, logs a `referred` event, and returns the reply plus a link button: `/apply.html?ref=<token>`.
3. **`apply.html` changes** (small additive JS in its existing inline script; no form-flow changes):
   - On load: if `?ref=` matches `^[0-9a-f-]{36}$`, store in `sessionStorage.cb_ref` and fire `navigator.sendBeacon('/chatbot/api/track.php', JSON.stringify({token, event:'apply_started'}))`.
   - On each step transition (the multi-step form already has a step-advance handler): beacon `apply_step` with the step number in `detail`. This gives staff *where* people drop, not just *that* they dropped.
   - On submit: append `chat_ref` to the posted form data when `cb_ref` exists.
4. **`apply-submit.php` changes:** accept optional `chat_ref`; validate format (36-char UUID) or discard silently; store in `applications.chat_ref`; then `UPDATE chatbot_leads SET status='apply_completed' WHERE referral_token=?` and log an `apply_completed` event. **Note on the whitelist sync:** `chat_ref` is *not* an enumerated field — it's nullable, format-validated free input, so it does **not** join the `apply.html`/`apply-submit.php`/`db/applications.sql` ENUM sync triad. It must not be added to the whitelist arrays; it just needs the format check. A missing/invalid `chat_ref` must never fail an application.
5. **Abandonment** = derived at dashboard read time per §3.3. Follow-up is manual by staff using the captured email/Telegram from the lead row — which is why step 1's soft contact capture matters: a referral without contact info is visible in stats but not actionable.
6. `track.php` hardening: POST only, same-origin `Origin`/`Referer` check, token must already exist in `chatbot_leads`, ≤ 40 events per token. It writes events and flips `status='apply_started'`; it can never create leads.

### 7.3 Flow C — partnership / pricing / donation / investment gate

**Intent detection: LLM self-report via the structured `intent` field (§4.3), with a server-side keyword backstop.** Rationale: the classification rides the same single Gemini call we're already making (zero marginal cost — decisive under free-tier budget), and an LLM handles phrasing a keyword list never will ("what would it cost our foundation to sponsor a cohort?"). The backstop — a short PHP regex list (`partner|sponsor|donat|invest|pricing|cost of partnership|fund`) checked *before* the Gemini call — exists because the gate is a hard business rule and shouldn't rest solely on model compliance.

**The hard gate is structural, not behavioral:** `40-partnerships.md` is **never included in the system prompt until the gate is passed** (§5). The model can't leak content it doesn't have. Prompt instructions alone would be a soft gate; withholding the source material makes it hard.

Sequence:
1. Gated intent detected (either path) and lead row lacks name+email → `chat.php` sets `gate_state='locked'`, logs `gate_shown`, and instructs the model (system prompt: `GATE: LOCKED`) to acknowledge the question, explain briefly ("Partnership and sponsorship details are shared by our partnerships team — may I take your name and email so I can share the overview and have them follow up?"), and ask for **name + email (required), organization + phone (optional)**. Organization is worth the extra field: it's the first thing the team needs to prioritize partnership follow-ups, and optional fields don't depress completion the way required ones do.
2. Widget renders a small inline form (not free-text extraction — a form is unambiguous and validates client-side). Submission goes to `chat.php` as a typed `gate_submit` payload; server validates (`filter_var` email), updates the lead (`status='gated_captured'`, `intent` as classified), logs `gate_passed`, **and sends a notification email to `info@ethioware.org`** via the same `mail()` pattern as `apply-submit.php` (CR/LF stripped from all values — same header-injection guard). Partnership leads are the highest-value, lowest-volume lead class; they warrant a push notification, not a dashboard-check cadence. Mail failure never fails the save (same best-effort convention).
3. Next Gemini call includes `40-partnerships.md` and `GATE: PASSED` — the bot now answers the original question substantively (`chat.php` re-sends the pre-gate question from stored history so the visitor doesn't retype it).
4. Gate state lives server-side on the lead row for the whole session; it is never re-shown once passed.

### 7.4 Own additions beyond the stated requirements (per §I of the brief), and why

1. **Rate limiting / abuse caps** (§4.2) — the free-tier quota is a shared, exhaustible resource; without caps one script kiddie takes the bot down for the day.
2. **Quota-exhaustion lead-capture fallback** (§4.5) — converts the free tier's main weakness into a degraded-but-useful mode.
3. **Partnership email notification** (§7.3) — objective (b), a credible funding pipeline, dies on slow follow-up; a dashboard nobody checks daily isn't a pipeline.
4. **`apply_step` granularity in abandonment tracking** (§7.2) — knowing *which step* loses applicants is a program-design signal for the education team, nearly free to capture.
5. **Google Analytics events** — fire `gtag('event', ...)` (the site already loads GA, ID `G-RYB205PY5C`) for `chatbot_open`, `chatbot_lead`, `chatbot_referred`, `chatbot_gate_passed`, so chatbot impact shows up in the analytics the team already looks at. No new service.
6. **`privacy.html` update + retention rule** — the bot stores names, emails, and transcripts; the privacy policy must say so. Add a transcript retention practice (delete `chatbot_conversations` rows older than 12 months — a manual quarterly phpMyAdmin action documented in the admin page footer, honest about the no-cron constraint).
7. **CSV export on the dashboard** (§8) — non-technical staff live in spreadsheets; a download button is cheaper than teaching SQL.

---

## 8. Admin dashboard

**Location:** `chatbot/admin/index.php` (+ `login.php`, `export.php`). Plain PHP + inline CSS reusing site variables; no framework, no JS beyond a sortable-table helper if desired.

**Access control:** PHP session login. Single shared password, stored as a `password_hash()` value in `chatbot/config.php` (`ADMIN_PASSWORD_HASH`); `password_verify()` on login; 5 attempts / 15 min per IP (tracked in `chatbot_events`); session cookie `HttpOnly` + `Secure` + `SameSite=Lax`; 8-hour idle timeout. Add `Disallow: /chatbot/` to `robots.txt`. One shared password is a deliberate simplicity trade for a ~5-person non-technical team; per-user accounts are listed in §12.

**Main view — leads table** (newest first, 50/page):

| Column | Source |
|---|---|
| Date | `chatbot_leads.created_at` |
| Name / Email / Phone / Org | contact fields ("—" if absent) |
| Intent | `intent` badge |
| Program | `program_interest` |
| Source | `chatbot` / `chatbot→apply` |
| Status | derived per §3.3: `Chat only` · `Gated lead ✉` · `Referred` · `Started — abandoned ⚠` (with last step reached) · `Applied ✓` |
| Transcript | link → read-only transcript view (`view.php?session=…`) |

**Filters** (GET params, prepared statements): date range, intent, program, status — including the two money views: *"Abandoned applications"* and *"Partnership/pricing inquiries pending follow-up"* (`intent IN ('partnership','pricing','donation','investment') AND status='gated_captured'`), each with a one-click preset button.

**Stats strip** (plain SQL aggregates, rendered as numbers + a simple bar list, no chart library): leads this week vs last; referral→completion conversion % ; abandonment rate; top programs by interest; pending partnership follow-ups count.

The abandonment status derives from one query, e.g.:

```sql
SELECT l.*,
  MAX(CASE WHEN e.event='apply_started' THEN e.created_at END) AS started_at,
  MAX(CASE WHEN e.event='apply_step' THEN e.detail END)        AS last_step,
  a.id IS NOT NULL                                              AS completed
FROM chatbot_leads l
LEFT JOIN chatbot_events e ON e.referral_token = l.referral_token
LEFT JOIN applications  a ON a.chat_ref        = l.referral_token
GROUP BY l.id;
```

**Export:** `export.php` streams the filtered view as CSV.

---

## 9. New and changed files

**New:**

```
assets/js/chatbot.js               widget (launcher, greeting, panel, chips, gate form, beacons)
assets/css/chatbot.css             widget styles (theme-aware, glassmorphism)
chatbot/api/chat.php               main endpoint: rate limits → gate check → Gemini → persist → respond
chatbot/api/track.php              beacon receiver for apply.html events
chatbot/api/prompt.php             system-prompt builder + knowledge loader (require'd by chat.php)
chatbot/api/db.php                 thin wrapper around research-scholars/config.php's rsp_get_connection()
chatbot/admin/index.php            dashboard (leads, filters, stats)
chatbot/admin/login.php            session login
chatbot/admin/view.php             single-transcript view
chatbot/admin/export.php           CSV export
chatbot/knowledge/00-org.md        ┐
chatbot/knowledge/10-programs.md   │ scaffolding files committed with headings +
chatbot/knowledge/20-enrollment.md │ "TODO: content team" placeholders; populated later
chatbot/knowledge/30-mentorship.md │
chatbot/knowledge/40-partnerships.md (gated)
chatbot/knowledge/90-faq.md        ┘
chatbot/config.php                 ⚠ SERVER-ONLY, gitignored: GEMINI_API_KEY, GEMINI_MODEL,
                                   GEMINI_FALLBACK_MODEL, ADMIN_PASSWORD_HASH
chatbot/config.example.php         committed template with placeholder values
db/chatbot.sql                     schema from §3.2 (import once, like db/applications.sql)
```

**Modified:**

```
index.html, apply.html, pay.html, privacy.html,
anteneh.html, biniyam.html, samuel.html      + widget script tag (privacy.html also gets the
                                               chatbot-data section)
certificates/*.html (235 files)              + widget script tag (scripted one-time edit)
pages/template.html                          + widget script tag (future pages inherit it)
apply.html                                   + ?ref= capture, step beacons, chat_ref on submit (§7.2)
apply-submit.php                             + optional chat_ref handling + lead status update (§7.2)
robots.txt                                   + Disallow: /chatbot/
.deployignore                                *.md rule replaced (§10)
.github/workflows/ci.yml                     js-syntax job addition (§10)
db/  (no change to applications.sql)         chat_ref arrives via db/chatbot.sql's ALTER
```

Not modified: `.htaccess` (no new routes needed — `/chatbot/api/chat.php` is a real file, and the certificate rewrite's `!-f` condition ignores real files), `research-scholars/*`, `cognify/*`.

---

## 10. CI / deploy impact

1. **`.deployignore` — required change.** The current `*.md` rule would strip `chatbot/knowledge/` out of every deploy, silently lobotomizing the bot. The file is consumed via rsync `--exclude-from`, which doesn't support `+` include overrides — so replace the glob with explicit root-level excludes:
   ```
   # --- Internal docs — don't publish (root-level only; chatbot/knowledge/*.md MUST deploy) ---
   /README.md
   /DEPLOY.md
   /handoff.md
   /TECHNICAL_OVERVIEW.md
   /CHATBOT_SPEC.md
   /cognify/README.md
   ```
   `config.php` is already excluded at any depth by the existing rule — `chatbot/config.php` is covered with no change.
2. **`.github/workflows/ci.yml` — `js-syntax` job:** add `assets/js/chatbot.js` to the `node --check` loop.
3. **`php-syntax` job:** no change — it already lints every checked-in `*.php`, which picks up all new endpoints and `chatbot/config.example.php` automatically.
4. **`guard` job:** no change — its existing `config.php` check already protects `chatbot/config.php` from being committed.
5. **`assets` / `links-offline` / `html-primary` jobs:** no config change; the bulk script-tag edit must pass them (run `python3 ci/check-assets.py` and `npm run lint:html` locally before pushing — the certificate-page edit touches 235 files, so this is the PR most likely to trip a checker).
6. **Server one-time setup (documented in DEPLOY.md as a new step, mirroring the applications step):** import `db/chatbot.sql` via phpMyAdmin; create `chatbot/config.php` on the server from `config.example.php`; verify with `curl -X POST https://ethioware.org/chatbot/api/chat.php -d '{"ping":1}'` returning a JSON error envelope (not a PHP error).
7. **rsync without `--delete` caveat:** renaming a knowledge file leaves the old one live on the server, and `chat.php` globs the folder — a stale file would keep feeding the model outdated content. Rule: **edit knowledge files in place; if one must be renamed/removed, delete the old file on the server by hand** (same manual-removal convention DEPLOY.md already documents).

---

## 11. Rollout plan

**Phase 0 — Spike the riskiest piece first: abandonment tracking (2–3 days).**
The referral-token pipeline (`?ref=` → sessionStorage → beacon → `track.php` → `chat_ref` on submit → join) crosses the most components and depends on `apply.html`'s existing step-transition code, `sendBeacon` behavior on shared-host response codes, and LiteSpeed handling of the beacon endpoint. Build *only* `track.php` + the `apply.html` snippet + the `db/chatbot.sql` import on a staging docroot (the staging model in DEPLOY.md), hand-craft a token in the DB, and prove the full started→step→completed and started→abandoned paths before anything else is written. If this needs redesign, better to know before the widget exists.

**Phase 1 — Backend core (week 1).** `chatbot/api/db.php`, `chat.php` with a hardcoded "echo" reply (no Gemini), rate limits, session/lead/conversation persistence. Verify rows in phpMyAdmin.

**Phase 2 — Gemini + knowledge (week 1–2).** `prompt.php`, structured-output call, fallback model, quota fallback. Knowledge scaffolding files with placeholder content. Test the gate structurally: confirm `40-partnerships.md` content is absent from prompts pre-gate.

**Phase 3 — Widget (week 2).** `chatbot.js`/`chatbot.css` on **staging index.html only**: launcher, 10 s greeting, sessionStorage suppression, chips, chat panel, gate form, theming both modes, mobile bottom-sheet.

**Phase 4 — Flows end-to-end on staging (week 3).** Enrollment referral (joins Phase 0 plumbing), partnership gate + notification email, GA events, `apply-submit.php` changes against the staging DB.

**Phase 5 — Dashboard (week 3–4).** Login, leads table, filters, presets, stats, CSV. Demo to sales/education staff and adjust columns/filters from their feedback — they are the customer of this surface.

**Phase 6 — Site-wide rollout (week 4).** Content team populates knowledge files (can start during Phase 2). Bulk script-tag edit across all pages; full local CI; `privacy.html` + `robots.txt` + `.deployignore` + `ci.yml` changes; production `config.php` + schema import; deploy; smoke-test: widget on `/`, on a certificate URL, one full referral round-trip in production, one gated lead + email received.

**Post-launch (week 5+):** watch `chatbot_events` for quota fallbacks and abuse; review first ~20 transcripts for hallucination/tone and tighten the system prompt.

**If usage grows ~10× (≈1,000 users/month):** free-tier RPD becomes the binding constraint on peak days — move to Flash-Lite as primary or the paid tier (still cheap at this size); add context caching for the knowledge prompt; revisit MongoDB/managed backend if message-level analytics are wanted (§3.1); replace read-time abandonment derivation with a real scheduled job if the events table grows past ~100k rows.

---

## 12. Open decisions for the team

1. **Widget on `pay.html` donation flow** — the bot is installed there per "all pages," but should donation questions route to the *gate* (treats donors as pipeline; adds friction) or answer freely from a public knowledge section (friendlier; loses capture)? Spec currently gates `donation` intent per §C's letter. Tradeoff: capture vs. donor friction. Recommend revisiting after the first month's transcripts.
2. **Weekly email digest vs. dashboard-only** — partnership leads get instant emails (§7.3); everything else is dashboard-pull. If staff won't check weekly, add a digest — but with no cron on shared hosting it would need cPanel's cron (which *does* exist on most cPanel plans and could also automate the retention cleanup in §7.4.6). Worth confirming what the plan offers; the spec deliberately doesn't depend on it.
3. **Transcript retention period** — 12 months proposed (§7.4.6); shorten to 6 if the team prefers a stricter privacy posture. Leads (name/email) are kept indefinitely either way.
4. **Shared vs. per-user dashboard password** — shared is specified for simplicity; per-user rows in a `chatbot_admins` table is a ~half-day upgrade if accountability for who viewed/exported leads ever matters (e.g., data-protection commitments to partners).
5. **Greeting copy and chip labels** — the exact strings ("How may I help?" vs. program-led copy) should come from the education team; A/B judgment, not engineering. The spec fixes the *mechanics* (10 s, once per session), not the words.
6. **Exact `program_interest` list** — §4.3's enum mirrors current `apply.html` programs plus `Research Scholars Program`/`Unsure`. Confirm against the program materials before Phase 2, since it also drives the dashboard's program filter (and remember it joins the whitelist sync set).
