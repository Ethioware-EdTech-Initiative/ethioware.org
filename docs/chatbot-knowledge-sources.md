# Chatbot knowledge sources and publishing

Reviewed on 2026-09-27. The bot uses a curated Markdown knowledge base, not
verbatim document dumps. Source files remain unchanged locally in
`chatbot/knowledge/`, excluded from Git and deployment. Only the seven files
listed in `CHATBOT_KNOWLEDGE_FILES` are loaded. No raw email, payment record,
score sheet, screenshot, or internal working context is a chatbot source at
runtime. A contact gate is lead capture, not permission to disclose records.

## Source map

| Source supplied in knowledge folder | Treatment | Published destination |
| --- | --- | --- |
| Company Profile - Ethioware.docx, version 2, August 26 2026 | Extracted organisation, programs, platforms, career pathway; omitted personal staff contacts, pricing and inconsistent or dated metrics | 00-org.md, 10-programs.md, 30-mentorship.md, 40-partnerships.md |
| SOP - Pre-Training Cohort Programs.docx, version 1.1, September 2026 | Extracted learner-facing format, track topics, onboarding, assessment, capstone and support FAQ; excluded internal operations, payment records and prices | 10-programs.md, 20-enrollment.md, 90-faq.md |
| Mentor Guidebook.docx, September–November 2026 | Extracted group-call and asynchronous-review commitment; omitted stipend and conflicting group size | 30-mentorship.md |
| research-scholars-program-context.md, August 21 2026 | Extracted program outline and learning phases only; excluded named learners, grades, attendance, personal topics, follow-up notes and internal instructions | 50-research-scholars.md |
| Deck.pdf | Text extracted; mission corroborated; older monthly-session positioning and historical metrics superseded by newer profile/SOP | 00-org.md |
| Ethioware KPI.docx | Reviewed as internal targets and operational context, not learner-facing facts; targets are not achievements | Not published |
| Meetings/*.docx | Reviewed for context; historical dates, action items, planned partnerships and personnel details excluded | No standalone bot file |
| Pre-Training Payment Form (Responses).xlsx | Excluded as personal financial/registration records; individual rows were not converted | Not published |
| Score-sheet/*.csv | Excluded as learner performance records; individual rows were not converted | Not published |
| Past3yearsemail/Sent-Past-3yrs | Excluded as private correspondence; archive was not ingested or converted | Not published |
| LMS Data/*.png | Excluded as historical analytics screenshots; no new live metrics inferred | Not published |
| Trustpilot reviews/ | Saved dashboard and browser assets excluded; no named review or current rating claim published | Not published |
| Existing website and reviewed knowledge | Retained application destinations, general support addresses and certificate verification route | Across reviewed files |

## Conflicts resolved or deliberately omitted

- Duration: September SOP and August profile take precedence over old site
  copy: eight weeks total, including seven curriculum weeks. The public site
  still needs a separate consistency pass before launch.
- Mentor calls: the guide specifies group calls and written reviews, with no
  individual calls. Older overview promises of individual calls were removed.
- Pod size: the guide says both four and five. Publish “small groups” pending
  confirmation rather than choosing one silently.
- Age eligibility: the owner subsequently confirmed all learners are at least
  18. This supersedes profile/Research Scholars descriptions of 15–18 and
  older pre-training descriptions of 17–19. Reviewed knowledge now says 18
  or older; public pages still need alignment. Originals remain unchanged.
- Research delivery: overview says fully online; meeting notes mention
  cohort-specific in-person events. Describe online materials and let the
  coordinator confirm events; no blanket promise about physical attendance.
- Recommendation threshold and refunds: SOP marks these unresolved. No
  universal ranking cutoff, entitlement or refund policy is invented.
- Metrics: profile counts do not consistently reconcile (including account
  verification subtotals). No live traction counts or testimonial ratings are
  included in the bot knowledge.
- Pricing: owner instruction supersedes every source. No prices, ranges,
  discounts, historical offers, donation tiers, matching claims, stipend
  amounts, or “free” promises are published, even in gated knowledge.

## Git publication workflow

Knowledge approval and lead follow-up owner: admin@ethioware.org.

1. Keep the original source locally or in a restricted organisational archive.
   Git ignores everything in the knowledge directory except reviewed files.
2. Edit the relevant topic Markdown. Keep provenance and internal discussion
   in this document, outside the runtime knowledge folder.
3. To add a new topic, update the explicit lists in `api/prompt.php`,
   `.gitignore`, and `.deployignore` in the same reviewed change.
4. Run `php ci/chatbot-content-test.php` and the knowledge publication check.
5. Publish through the existing Git deployment. Do not run the Instatic
   exporter against this directory; disable any existing export cron at cutover.
6. Verify HTTP requests for knowledge files return 403 on the target host.
   PHP must still be able to read the reviewed files from disk.

Git preserves reviewed knowledge history permanently. It is not a backup for
ignored originals. Keep a restricted backup of original documents separately.
Do not commit learner records, private email, payment data, or database dumps.
Rsync exclusions do not delete older server copies: inspect for previously
published raw files at cutover and remove only confirmed copies after backup.
