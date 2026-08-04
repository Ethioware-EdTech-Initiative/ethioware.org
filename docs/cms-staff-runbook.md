# Ethioware CMS Staff Runbook

> **Admin URL:** [https://cms.ethioware.org/admin](https://cms.ethioware.org/admin)
> **Staging URL:** [https://staging.ethioware.org/admin](https://staging.ethioware.org/admin)
> **Role required:** Marketing (or Owner)

---

## Table of Contents

1. [Logging In](#1-logging-in)
2. [Publishing a New Certificate](#2-publishing-a-new-certificate)
3. [Posting an Announcement](#3-posting-an-announcement)
4. [Toggling Registrations Open/Closed](#4-toggling-registrations-openclosed)
5. [Editing Homepage Content (FAQ, Partners, Stats)](#5-editing-homepage-content)
6. [Editing Chatbot Knowledge](#6-editing-chatbot-knowledge)
7. [Viewing Applications (Read-Only)](#7-viewing-applications-read-only)
8. [Viewing the Audit Log](#8-viewing-the-audit-log)

---

## 1. Logging In

1. Go to **https://cms.ethioware.org/admin**
2. Enter your email and password (provided by the system owner)
3. You'll land on the **Dashboard**

> **Note:** If you see "access denied" for any action, contact the system owner
> — your Marketing role may not include that permission.

---

## 2. Publishing a New Certificate

Certificates are published as pages with a **short URL**: `ethioware.org/<CODE>`
(e.g., `ethioware.org/WI10092516`).

### Steps

1. Open **Site workspace → Pages**
2. Find the page named `_certificate-template` (it may be hidden/unpublished)
3. Click **Duplicate** on the template page
4. Set the **page slug** to exactly the certificate code (e.g., `WI10092516`)
   - ⚠️ The slug MUST be alphanumeric only — no slashes, no `/certificates/` prefix
5. In the page editor:
   - **Certificate image:** Upload the `.webp` certificate image (from `assets/img/`)
   - **Work log iframe:** Paste the Google Drive preview URL
   - **Logo carousel:** Verify the partner logos are correct for this cohort
   - **Title/meta:** Update the page title if needed
6. Click **Publish**
7. Verify: visit `https://ethioware.org/<CODE>` — it should show the certificate

### Time estimate: < 10 minutes per certificate

---

## 3. Posting an Announcement

1. Open **Content workspace → Data → announcements**
2. Click **+ New Row**
3. Fill in the fields:
   - **slug:** URL-friendly slug (e.g., `aug-2026-cohort-open`)
   - **title:** Announcement headline
   - **body:** Full text (supports markdown)
   - **published_at:** Publication date/time
   - **pinned:** Check if this should appear at the top
4. Click **Save** (and **Publish** if applicable)

Announcements appear at: `ethioware.org/announcements/<slug>`

---

## 4. Toggling Registrations Open/Closed

1. Open **Content workspace → Data → site_flags**
2. Find or create the row with these fields:
   - **registrations_open:** Toggle on/off
   - **banner_text:** e.g., "Applications for August 2026 are now open!"
   - **banner_link:** e.g., `/apply`
3. Click **Save**
4. The homepage will reflect the change on next publish/refresh

---

## 5. Editing Homepage Content

### FAQ, Partners, Stats

1. Open **Site workspace → Pages → Homepage** (or the relevant section page)
2. Use the visual editor to modify:
   - FAQ questions and answers
   - Partner logos and links
   - Statistics (learners served, cohort counts, etc.)
3. Click **Publish**

> **Tip:** Use the preview button to check changes before publishing.

---

## 6. Editing Chatbot Knowledge

The chatbot assistant uses knowledge files to answer questions. Editing them in
the CMS updates what the chatbot knows.

1. Open **Content workspace → Data → chatbot_knowledge**
2. Find the relevant row (e.g., `00-org` for organization info, `90-faq` for FAQ)
3. Edit the fields:
   - **slug:** Determines filename (e.g., `00-org` → `00-org.md`)
   - **title:** Becomes the `# Title` heading in the exported file
   - **body:** Markdown content with `## Section` subheadings
4. Click **Save**

### How it works
- A scheduled export (every 15 min) writes the CMS data as markdown files
- The chatbot PHP backend reads these files at runtime
- Changes typically take effect within 15 minutes

### Knowledge file format

```markdown
# Title of the Knowledge Section

## Subheading

Body text. Use ## for sub-sections.
```

---

## 7. Viewing Applications (Read-Only)

Applications are submitted via the `/apply` page and stored in MySQL. They are
synced to the CMS for viewing convenience.

1. Open **Content workspace → Data → applications**
2. Browse, search, and filter applications
3. Click any row to view full details

> **Note:** Applications are **read-only** in the CMS. The source of truth is
> MySQL. Do not attempt to edit application data here.

---

## 8. Viewing the Audit Log

The audit log records all content changes made by any user.

1. Open **Dashboard → Audit Log** (or Settings → Audit)
2. View entries showing:
   - Who made the change
   - What was changed (page, data row, etc.)
   - When the change was made
3. Use the search/filter to find specific actions

---

## Troubleshooting

| Symptom | Likely Cause | Fix |
|---------|-------------|-----|
| Can't publish a page | Missing `pages.publish` permission | Contact system owner |
| Certificate URL shows 404 | Slug has a prefix or special chars | Edit page → set slug to CODE only (e.g., `WI10092516`) |
| Chatbot not using new knowledge | Export hasn't run yet | Wait 15 min, or ask admin to run export manually |
| "CSRF invalid" error | Browser session expired | Reload the page and try again |
| Changes not visible on site | Page not published | Click "Publish" in the page editor |

---

## Contact

- **System owner/admin issues:** Contact the technical team
- **CMS bugs:** File in the Instatic repository
- **Content questions:** info@ethioware.org
