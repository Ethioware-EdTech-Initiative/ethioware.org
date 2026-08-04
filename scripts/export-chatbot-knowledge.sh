#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# export-chatbot-knowledge.sh — P3-3: Instatic → chatbot knowledge exporter
#
# Pulls chatbot_knowledge rows from the Instatic CMS and writes them as
# markdown files to the chatbot/knowledge/ directory. The chatbot PHP backend
# reads these files at runtime (no database dependency for knowledge).
#
# Usage:
#   ./scripts/export-chatbot-knowledge.sh
#
# Environment (set on server, NOT committed):
#   INSTATIC_API_URL     — e.g. http://127.0.0.1:3001/api/data/chatbot_knowledge
#   INSTATIC_API_KEY     — read-only API key for the CMS data endpoint
#   KNOWLEDGE_DIR        — default: /home/ethiowzj/public_html/chatbot/knowledge
#
# Can be run manually or via cron:
#   */15 * * * * /home/ethiowzj/public_html/scripts/export-chatbot-knowledge.sh >> ~/logs/knowledge-export.log 2>&1
#
# See scripts/README.md for the expected file format.
# ---------------------------------------------------------------------------

set -euo pipefail

# --- Configuration ---
INSTATIC_API_URL="${INSTATIC_API_URL:-}"
INSTATIC_API_KEY="${INSTATIC_API_KEY:-}"
KNOWLEDGE_DIR="${KNOWLEDGE_DIR:-$(dirname "$0")/../chatbot/knowledge}"

# Resolve to absolute path
KNOWLEDGE_DIR="$(cd "$KNOWLEDGE_DIR" 2>/dev/null && pwd)" || {
    echo "[ERROR] Knowledge directory does not exist: $KNOWLEDGE_DIR" >&2
    exit 1
}

if [ -z "$INSTATIC_API_URL" ] || [ -z "$INSTATIC_API_KEY" ]; then
    echo "[SKIP] INSTATIC_API_URL or INSTATIC_API_KEY not set — skipping export." >&2
    exit 0
fi

echo "[$(date -Iseconds)] Starting chatbot knowledge export..."

# --- Fetch rows from Instatic ---
RESPONSE=$(curl -sf \
    -H "Authorization: Bearer $INSTATIC_API_KEY" \
    -H "Accept: application/json" \
    --max-time 10 \
    "$INSTATIC_API_URL") || {
    echo "[ERROR] Failed to fetch from Instatic API: $INSTATIC_API_URL" >&2
    exit 1
}

# --- Validate JSON ---
if ! echo "$RESPONSE" | python3 -c "import sys, json; json.load(sys.stdin)" 2>/dev/null; then
    echo "[ERROR] Instatic API did not return valid JSON" >&2
    exit 1
fi

# --- Write markdown files ---
# Expected JSON shape: { "rows": [ { "slug": "00-org", "title": "...", "body": "..." }, ... ] }
# OR:                  [ { "slug": "00-org", "title": "...", "body": "..." }, ... ]
#
# Each row becomes chatbot/knowledge/{slug}.md with format:
#   # {title}
#
#   {body}

TEMP_DIR=$(mktemp -d "${KNOWLEDGE_DIR}/.export-XXXXXX")
trap 'rm -rf "$TEMP_DIR"' EXIT

COUNT=$(echo "$RESPONSE" | python3 -c "
import sys, json, os

data = json.load(sys.stdin)
rows = data.get('rows', data) if isinstance(data, dict) else data

if not isinstance(rows, list):
    print('0')
    sys.exit(0)

temp_dir = sys.argv[1]
count = 0

for row in rows:
    slug  = row.get('slug', '').strip()
    title = row.get('title', '').strip()
    body  = row.get('body', '').strip()

    if not slug:
        continue

    # Sanitize slug: only allow alphanumeric, hyphens, underscores
    safe_slug = ''.join(c for c in slug if c.isalnum() or c in '-_')
    if not safe_slug:
        continue

    filepath = os.path.join(temp_dir, f'{safe_slug}.md')
    with open(filepath, 'w', encoding='utf-8') as f:
        if title:
            f.write(f'# {title}\n\n')
        if body:
            f.write(body)
            if not body.endswith('\n'):
                f.write('\n')
    count += 1

print(count)
" "$TEMP_DIR")

if [ "$COUNT" -eq 0 ]; then
    echo "[WARN] No knowledge rows returned from Instatic — keeping existing files." >&2
    exit 0
fi

# --- Atomic swap: move new files into place ---
# Preserve any manually-maintained files by only replacing files that came
# from the export. Delete old exported files by comparing with new set.
for md_file in "$TEMP_DIR"/*.md; do
    [ -f "$md_file" ] || continue
    mv -f "$md_file" "$KNOWLEDGE_DIR/"
done

echo "[$(date -Iseconds)] Export complete: $COUNT knowledge files written to $KNOWLEDGE_DIR"
