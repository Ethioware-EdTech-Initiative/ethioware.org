# Chatbot production implementation plan

Updated 2026-09-27 from the owner's decisions and repository review. This
plan supersedes conflicting assumptions in CHATBOT_SPEC.md. Knowledge
preparation and publication safeguards are implemented locally; all other
work below remains planned. No deployment or public enablement has occurred.

## Confirmed requirements

- Existing cPanel/LiteSpeed, PHP and MySQL hosting.
- Approximately 50 users each week. The owner corrected the audience to
  learners aged 18 or older; this supersedes the earlier under-18 answer.
- Desired immediate launch and zero AI spend; retain Gemini free APIs and
  the existing configurable primary/fallback models.
- Knowledge approval and lead follow-up owner: admin@ethioware.org.
- English only. Partnership, sponsorship, donation and investment inquiries
  retain name/email capture. Learner fee questions receive a pricing handoff.
- Never quote pricing, including after a contact gate; terms change by cohort.
- Knowledge lives in the folder and Git is the publishing authority; no CMS sync.
- Permanent retention, interpreted as no automatic expiration for knowledge,
  transcripts and leads. Personal records stay in the protected database,
  never Git. Keep a controlled correction/deletion procedure for explicit
  requests and applicable obligations; permanent retention is not a promise
  that every record is immutable. Update disclosures before collecting data.

## Gemini launch prerequisites

Gemini remains the selected provider following the owner's confirmation that
learners are at least 18. Reconcile older public pages and source descriptions
that advertise ages 15–18 or 17–19 with the actual adult-only offering before
launch. The terms exclude clients directed toward or likely accessed by
under-18s; an owner clarification alone does not update the deployed site.

Unpaid services also instruct users not to submit personal information. The
current implementation includes names, emails and phone numbers in prompts
and forwards conversation text. Keep contact capture in first-party forms and
MySQL, replace model-visible contact data with a contact-captured flag, and
exclude personal data from outgoing messages, history and logs. Input handling
must cover volunteered personal information as well as structured fields;
a regex-only filter is not proof that arbitrary text contains none. Establish
and test this data boundary before enabling the free API in production.

Source checked: https://ai.google.dev/gemini-api/terms (2026-09-27).
The alternative non-AI launch proposal is not selected. Static answers and
contact capture remain outage/quota fallbacks rather than a replacement for
Gemini. No provider change or paid service is authorised by this plan.

## Capacity assumption

At an assumed 12 messages per user, 50 weekly users means 600 messages per
week, approximately 86 per day averaged across a week. A fallback attempt can
double provider requests. Average traffic does not establish peak capacity
or available free quota: validate the chosen provider's actual project limits
and run burst tests. Keep PHP/MySQL; no vector database or hosting migration
is justified by this volume alone.

## Implementation sequence

### 1. Prepare reviewed knowledge — implemented locally

Seven topic files cover the organisation, four tracks, enrollment, mentorship,
partnerships, Research Scholars and learner FAQ. Updated eight-week duration,
group mentorship, assessment and graduation rules. Removed monetary amounts,
old package promises and personal records. Source mapping and unresolved
content conflicts are documented in chatbot-knowledge-sources.md.

The loader now uses an explicit list. Git and rsync allow only reviewed
knowledge. Directory-level HTTP denial prevents direct source downloads on
compatible Apache/LiteSpeed configurations. Validate this on staging.

### 2. Harden the Gemini serving path — launch blocker

Retain the configured Gemini primary and fallback models; verify availability
and project quotas. Implement the personal-data boundary described above,
reviewed knowledge loading and strict structured-output validation. Provide
program buttons, deterministic price handoff, separate Research Scholars
routing and first-party contact forms. Do not depend on an LLM to authorise
gates or decide whether to disclose pricing. Preserve static/contact fallbacks
for timeouts, errors and quota exhaustion.

Acceptance: English answers cover key learner tasks; unsupported questions
hand off; price questions never return amounts; no personal data reaches an
unapproved provider. Both locked and passed gates obey the same price rule.

### 3. Harden requests and persistence — launch blocker

- Validate request types, field types and body sizes before creating rows.
- Issue server-bound conversation credentials; reject cross-session writes.
- Apply atomic per-session, IP and global limits across message, gate,
  contact and tracking requests, including provider retries.
- Count user requests consistently. Current message_count counts both sides;
  capped conversations currently continue to grow on repeated submissions.
- Introduce unique request IDs and idempotent retries. Prevent concurrent
  requests from replacing each other's transcript using a processing lease
  and transactional/versioned persistence; do not hold a database transaction
  open during a network model request.
- Add uniform error handling, bounded timeouts and a server-side feature flag
  for disabled, FAQ and approved AI modes.

Acceptance: malformed inputs are controlled 4xx responses; parallel and
repeated requests cannot lose turns, repeat notifications or bypass quotas;
outages produce a usable handoff without unbounded writes.

### 4. Complete contact and admin operations — launch blocker

- Keep lead capture independent of model availability. Queue notifications
  durably, retry delivery and record status; add cPanel cron if available.
- Route lead notifications and knowledge review to admin@ethioware.org.
  Update the current gate notification recipient (info@ethioware.org) during
  implementation. Do not promise a reply deadline until the owner accepts it.
- Use individual Clerk accounts, validate verified identity, enforce bounded
  absolute session lifetime and recheck access on a documented interval.
  Current PHP sessions survive Clerk revocation while remaining active.
- Disable routine shared-password fallback; audit transcript reads and exports.
- Neutralise CSV formula prefixes and apply no-store headers to personal data.
- Publish permanent-retention disclosure; restrict database and backup access.
  Remove unnecessary personal fields from prompts and operational logs.

Acceptance: captured leads have a visible owner/delivery state; revoked staff
lose access within the chosen interval; unauthorised export/read attempts fail;
the disclosure matches what is stored and who can access it.

### 5. Validate staging and recovery — launch blocker

- Provide PHP with mysqli, curl, mbstring and openssl plus isolated MySQL data.
- Make schema changes repeatable and versioned; back up before migration.
- Run existing auth/content tests and browser harness in CI. Add API/database
  tests for abuse caps, concurrent submissions, contact capture and referrals.
- Verify /apply completion attribution and /research-scholars routing.
- Add health/error monitoring, request latency and notification-failure alerts;
  for AI mode also track actual requests/tokens, fallback and quota exhaustion.
- Test backup restore, Git rollback, asset cache versioning, and feature shutdown.
- Disable the CMS exporter; align public site copy with the eight-week SOP
  and the owner's corrected age eligibility of 18 or older.
- Check raw sources and private Markdown are neither Git-tracked nor deployed.

Acceptance: critical automated checks pass, mobile/keyboarding/retry flows
work, knowledge URLs are denied, restore succeeds, and a rollback rehearsal
preserves stored leads. Test sustained and burst traffic above the agreed peak.

### 6. Release in stages

Staff smoke test with synthetic data, then a limited public rollout, then
site-wide enablement. Require completed launch-blocker criteria, an operator
receiving alerts, and a confirmed follow-up owner. Review early questions and
failed handoffs daily. Do not describe the service as production-ready based
only on syntax checks or the desired immediate deadline.

## Ownership and decisions

Provider selection is settled: retain Gemini free APIs. Knowledge approval
and lead follow-up belong to admin@ethioware.org. The same mailbox is the
initial escalation contact; staff should still use individual Clerk accounts.
No shared-login requirement follows from mailbox ownership. A response-time
commitment remains unset; omit turnaround promises until agreed operationally.

## Validation status

The independent knowledge publication check passes: seven reviewed files,
within the 48,000-byte prompt budget. A local rsync dry run
includes only those files and the directory access rule from the knowledge
folder. `git diff --check` passes. Local PHP is unavailable, so the updated
PHP content tests and real-server HTTP checks remain required before release.
No provider key, production database, live quota or production hosting
configuration has been verified. Raw source archives were preserved.
