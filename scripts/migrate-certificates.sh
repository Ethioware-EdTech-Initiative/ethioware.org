#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# migrate-certificates.sh — P4-2: Bulk certificate migration to Instatic
#
# Parses all certificate HTML files in the ethioware.org repo, extracts
# structured data (code, image, work-log iframe, partner logos), and creates
# corresponding pages in the Instatic CMS via the admin API.
#
# This script is idempotent — it checks if a page with the given slug already
# exists and skips it.
#
# Usage:
#   INSTATIC_API_URL=http://127.0.0.1:3001 \
#   INSTATIC_API_KEY=your-api-key \
#   ./scripts/migrate-certificates.sh [--dry-run]
#
# Options:
#   --dry-run     Parse and report but don't create pages
#   --limit N     Process only the first N certificates
#   --verbose     Show detailed extraction info
#
# Environment:
#   INSTATIC_API_URL    — Instatic base URL (e.g. http://127.0.0.1:3001)
#   INSTATIC_API_KEY    — Admin API key for Instatic
#   CERT_DIR            — Override certificate directory (default: ./certificates)
# ---------------------------------------------------------------------------

set -euo pipefail

# --- Parse arguments ---
DRY_RUN=false
LIMIT=0
VERBOSE=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --dry-run)  DRY_RUN=true; shift ;;
        --limit)    LIMIT="$2"; shift 2 ;;
        --verbose)  VERBOSE=true; shift ;;
        *)          echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

# --- Configuration ---
INSTATIC_API_URL="${INSTATIC_API_URL:-}"
INSTATIC_API_KEY="${INSTATIC_API_KEY:-}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
CERT_DIR="${CERT_DIR:-$REPO_ROOT/certificates}"
IMG_DIR="$REPO_ROOT/assets/img"

if [ "$DRY_RUN" = false ]; then
    if [ -z "$INSTATIC_API_URL" ] || [ -z "$INSTATIC_API_KEY" ]; then
        echo "[ERROR] INSTATIC_API_URL and INSTATIC_API_KEY must be set." >&2
        echo "  Use --dry-run to preview without an API connection." >&2
        exit 1
    fi
fi

# --- Counters ---
TOTAL=0
CREATED=0
SKIPPED=0
ERRORS=0

echo "============================================"
echo "  Certificate Migration (P4-2)"
echo "============================================"
echo "  Source:     $CERT_DIR"
echo "  Images:     $IMG_DIR"
echo "  API:        ${INSTATIC_API_URL:-<dry-run>}"
echo "  Dry run:    $DRY_RUN"
[ "$LIMIT" -gt 0 ] && echo "  Limit:      $LIMIT"
echo ""

# ---------------------------------------------------------------------------
# Extract certificate data from an HTML file using Python
# Returns JSON: { code, image, worklog_url, partners: [{url, img, alt}] }
# ---------------------------------------------------------------------------
extract_cert_data() {
    local html_file="$1"
    python3 -c "
import sys, re, json, os

html_file = sys.argv[1]
img_dir = sys.argv[2]

with open(html_file, 'r', encoding='utf-8', errors='replace') as f:
    html = f.read()

# Extract certificate code from filename
basename = os.path.basename(html_file)
code = basename.replace('.html', '').strip()

# Extract certificate image
# Pattern: src=\"assets/img/<CODE>.webp\" or .png or .jpg
img_match = re.search(r'src=[\"\\']assets/img/' + re.escape(code) + r'\\.(webp|png|jpg|jpeg)[\"\\']', html)
image = f'assets/img/{code}.{img_match.group(1)}' if img_match else ''

# Check if image actually exists
if image:
    full_path = os.path.join(os.path.dirname(html_file), '..', image)
    if not os.path.isfile(full_path):
        image = ''

# Extract work-log iframe URL (Google Drive preview)
iframe_match = re.search(r'<iframe[^>]*src=[\"\\']([^\"\\'>]*drive\\.google\\.com[^\"\\'>]*)[\"\\']', html)
worklog_url = iframe_match.group(1) if iframe_match else ''

# Extract partner logos
partners = []
partner_blocks = re.findall(
    r'<div class=[\"\\']partner[\"\\'][^>]*>.*?</div>',
    html, re.DOTALL
)
for block in partner_blocks:
    link_match = re.search(r'href=[\"\\']([^\"\\'>]+)[\"\\']', block)
    img_match = re.search(r'<img[^>]*src=[\"\\']([^\"\\'>]+)[\"\\']', block)
    alt_match = re.search(r'alt=[\"\\']([^\"\\'>]*)[\"\\']', block)
    if img_match:
        partners.append({
            'url': link_match.group(1) if link_match else '',
            'img': img_match.group(1),
            'alt': alt_match.group(1) if alt_match else ''
        })

result = {
    'code': code,
    'image': image,
    'worklog_url': worklog_url,
    'partners': partners
}

print(json.dumps(result))
" "$html_file" "$IMG_DIR"
}

# ---------------------------------------------------------------------------
# Check if page with slug already exists in Instatic
# ---------------------------------------------------------------------------
page_exists() {
    local slug="$1"
    local response
    response=$(curl -sf \
        -H "Authorization: Bearer $INSTATIC_API_KEY" \
        "${INSTATIC_API_URL}/api/pages?slug=${slug}" 2>/dev/null || echo '{"pages":[]}')

    echo "$response" | python3 -c "
import sys, json
try:
    data = json.load(sys.stdin)
    pages = data.get('pages', data.get('rows', []))
    if isinstance(pages, list):
        for p in pages:
            if p.get('slug') == sys.argv[1]:
                print('true')
                sys.exit(0)
except:
    pass
print('false')
" "$slug"
}

# ---------------------------------------------------------------------------
# Create certificate page in Instatic (duplicate template + set fields)
# ---------------------------------------------------------------------------
create_cert_page() {
    local cert_json="$1"
    local code slug

    code=$(echo "$cert_json" | python3 -c "import sys,json; print(json.load(sys.stdin)['code'])")
    slug="$code"

    # Create page by duplicating the certificate template
    if curl -sf \
        -X POST \
        -H "Authorization: Bearer $INSTATIC_API_KEY" \
        -H "Content-Type: application/json" \
        -d "{
            \"template\": \"_certificate-template\",
            \"slug\": \"${slug}\",
            \"title\": \"Certificate & Work Log — ${code}\",
            \"status\": \"published\",
            \"data\": ${cert_json}
        }" \
        "${INSTATIC_API_URL}/api/pages" >/dev/null 2>&1; then
        echo "created"
    else
        echo "error"
    fi
}

# ---------------------------------------------------------------------------
# Main loop
# ---------------------------------------------------------------------------
for html_file in "$CERT_DIR"/*.html; do
    [ -f "$html_file" ] || continue

    TOTAL=$((TOTAL + 1))

    if [ "$LIMIT" -gt 0 ] && [ "$TOTAL" -gt "$LIMIT" ]; then
        TOTAL=$((TOTAL - 1))
        break
    fi

    # Extract data
    CERT_DATA=$(extract_cert_data "$html_file")
    # Field separator is \x1f (ASCII unit separator), not a whitespace char,
    # so `read` won't collapse the empty fields that IFS=<tab>/<space> would.
    IFS=$'\x1f' read -r CODE IMAGE WORKLOG NUM_PARTNERS <<< "$(echo "$CERT_DATA" | python3 -c "
import sys, json
d = json.load(sys.stdin)
print('\x1f'.join([d['code'], d['image'], d['worklog_url'], str(len(d['partners']))]))
")"

    if [ "$VERBOSE" = true ]; then
        echo "  [$TOTAL] $CODE"
        echo "    Image:    ${IMAGE:-<missing>}"
        echo "    Worklog:  ${WORKLOG:-<none>}"
        echo "    Partners: $NUM_PARTNERS"
    fi

    if [ "$DRY_RUN" = true ]; then
        printf "  %-15s image=%-5s worklog=%-5s partners=%s\n" \
            "$CODE" \
            "$([ -n "$IMAGE" ] && echo 'yes' || echo 'NO')" \
            "$([ -n "$WORKLOG" ] && echo 'yes' || echo 'no')" \
            "$NUM_PARTNERS"
        continue
    fi

    # Check if already exists
    EXISTS=$(page_exists "$CODE")
    if [ "$EXISTS" = "true" ]; then
        SKIPPED=$((SKIPPED + 1))
        [ "$VERBOSE" = true ] && echo "    → Skipped (already exists)"
        continue
    fi

    # Create page
    RESULT=$(create_cert_page "$CERT_DATA")
    if [ "$RESULT" = "created" ]; then
        CREATED=$((CREATED + 1))
        echo "  ✅ $CODE"
    else
        ERRORS=$((ERRORS + 1))
        echo "  ❌ $CODE — failed to create" >&2
    fi

    # Rate limit: small delay to avoid overwhelming the API
    sleep 0.2
done

echo ""
echo "============================================"
echo "  Migration Summary"
echo "============================================"
echo "  Total scanned:   $TOTAL"
if [ "$DRY_RUN" = false ]; then
    echo "  Created:         $CREATED"
    echo "  Skipped:         $SKIPPED (already existed)"
    echo "  Errors:          $ERRORS"
fi
echo ""

if [ "$DRY_RUN" = true ]; then
    echo "  This was a dry run. Use without --dry-run to create pages."
fi

if [ "$ERRORS" -gt 0 ]; then
    echo "  ⚠️  $ERRORS errors occurred. Check the output above." >&2
    exit 1
fi
