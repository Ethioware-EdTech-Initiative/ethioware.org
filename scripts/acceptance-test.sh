#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# acceptance-test.sh — P5-1: Post-cutover acceptance test suite
#
# Runs automated smoke tests against the production site to verify the
# Instatic CMS integration is working correctly.
#
# Usage:
#   ./scripts/acceptance-test.sh [--host https://ethioware.org]
#
# Tests:
#   T01: Homepage loads (200)
#   T02: Certificate short URLs work (spot-check 20 random codes)
#   T03: Apply form POST returns valid JSON
#   T04: Chatbot API responds
#   T05: CMS admin is reachable
#   T06: No Microsoft Forms embeds in homepage
#   T07: Static assets load (CSS, JS)
#   T08: Robots.txt is present
#   T09: Sitemap.xml is present
# ---------------------------------------------------------------------------

set -euo pipefail

HOST="${1:-https://ethioware.org}"
CMS_HOST="https://cms.ethioware.org"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
CERT_DIR="$REPO_ROOT/certificates"

PASS=0
FAIL=0
WARN=0

# --- Helpers ---
test_url() {
    local label="$1"
    local url="$2"
    local expected_code="${3:-200}"

    local actual_code
    actual_code=$(curl -sf -o /dev/null -w "%{http_code}" --max-time 10 "$url" 2>/dev/null || echo "000")

    if [ "$actual_code" = "$expected_code" ]; then
        echo "  ✅ $label → $actual_code"
        PASS=$((PASS + 1))
    else
        echo "  ❌ $label → $actual_code (expected $expected_code)"
        FAIL=$((FAIL + 1))
    fi
}

test_no_content() {
    local label="$1"
    local url="$2"
    local forbidden_pattern="$3"

    local body
    body=$(curl -sf --max-time 10 "$url" 2>/dev/null || echo "")

    if echo "$body" | grep -qi "$forbidden_pattern"; then
        echo "  ❌ $label — found forbidden content: '$forbidden_pattern'"
        FAIL=$((FAIL + 1))
    else
        echo "  ✅ $label — no '$forbidden_pattern' found"
        PASS=$((PASS + 1))
    fi
}

echo "============================================"
echo "  Ethioware Acceptance Tests (P5-1)"
echo "============================================"
echo "  Target:  $HOST"
echo "  CMS:     $CMS_HOST"
echo "  Time:    $(date -Iseconds)"
echo ""

# === T01: Homepage loads ===
echo "[T01] Homepage"
test_url "GET /" "$HOST/"
echo ""

# === T02: Certificate short URLs ===
echo "[T02] Certificate short URLs (20 random)"
if [ -d "$CERT_DIR" ]; then
    # Pick 20 random certificate codes
    SAMPLE_CODES=$(ls "$CERT_DIR"/*.html 2>/dev/null | shuf | head -20 | while read -r f; do
        basename "$f" .html
    done)

    for CODE in $SAMPLE_CODES; do
        test_url "/$CODE" "$HOST/$CODE"
    done
else
    echo "  ⚠️  Certificate directory not found — skipping spot-check"
    WARN=$((WARN + 1))
fi
echo ""

# === T03: Apply form POST ===
echo "[T03] Apply form endpoint"
APPLY_RESP=$(curl -sf -X POST \
    -H "Content-Type: application/x-www-form-urlencoded" \
    -d "website=honeypot-test" \
    --max-time 10 \
    "$HOST/apply-submit.php" 2>/dev/null || echo '{"error":"unreachable"}')

if echo "$APPLY_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); assert d.get('success') == True" 2>/dev/null; then
    echo "  ✅ POST /apply-submit.php → honeypot accepted (expected)"
    PASS=$((PASS + 1))
else
    echo "  ❌ POST /apply-submit.php — unexpected response: $APPLY_RESP"
    FAIL=$((FAIL + 1))
fi
echo ""

# === T04: Chatbot API ===
echo "[T04] Chatbot API"
CHAT_RESP=$(curl -sf -X POST \
    -H "Content-Type: application/json" \
    -d '{"ping":1}' \
    --max-time 10 \
    "$HOST/chatbot/api/chat.php" 2>/dev/null || echo "unreachable")

if echo "$CHAT_RESP" | python3 -c "import sys,json; json.load(sys.stdin)" 2>/dev/null; then
    echo "  ✅ POST /chatbot/api/chat.php → valid JSON response"
    PASS=$((PASS + 1))
else
    echo "  ❌ POST /chatbot/api/chat.php — non-JSON or unreachable: $CHAT_RESP"
    FAIL=$((FAIL + 1))
fi
echo ""

# === T05: CMS admin reachable ===
echo "[T05] CMS admin"
CMS_CODE=$(curl -sf -o /dev/null -w "%{http_code}" --max-time 10 "$CMS_HOST/admin" 2>/dev/null || echo "000")
if [ "$CMS_CODE" = "200" ] || [ "$CMS_CODE" = "302" ]; then
    echo "  ✅ GET $CMS_HOST/admin → $CMS_CODE"
    PASS=$((PASS + 1))
else
    echo "  ❌ GET $CMS_HOST/admin → $CMS_CODE (expected 200 or 302)"
    FAIL=$((FAIL + 1))
fi
echo ""

# === T06: No Microsoft Forms ===
echo "[T06] No Microsoft Forms embeds"
test_no_content "Homepage" "$HOST/" "microsoft.com/forms"
echo ""

# === T07: Static assets ===
echo "[T07] Static assets"
test_url "CSS (styles.css)" "$HOST/assets/css/styles.css"
test_url "JS (chatbot.js)" "$HOST/assets/js/chatbot.js"
test_url "Favicon" "$HOST/assets/img/logo.png"
echo ""

# === T08: robots.txt ===
echo "[T08] robots.txt"
test_url "robots.txt" "$HOST/robots.txt"
echo ""

# === T09: sitemap.xml ===
echo "[T09] sitemap.xml"
test_url "sitemap.xml" "$HOST/sitemap.xml"
echo ""

# === Manual verification checklist ===
echo "============================================"
echo "  Manual Verification (do these by hand)"
echo "============================================"
echo ""
echo "  [ ] T06b: Edit chatbot_knowledge in CMS → wait 15 min → verify chatbot uses new answer"
echo "  [ ] T07b: Submit a REAL test application → verify MySQL row + Instatic CMS row"
echo "  [ ] T09b: Log into $CMS_HOST/admin as Marketing role:"
echo "        - Can publish a certificate page"
echo "        - Can edit chatbot knowledge"
echo "        - Cannot manage users or site structure"
echo "  [ ] T10:  View audit log in CMS dashboard"
echo ""

# === Summary ===
echo "============================================"
echo "  Results"
echo "============================================"
echo "  ✅ Passed:   $PASS"
echo "  ❌ Failed:   $FAIL"
echo "  ⚠️  Warnings: $WARN"
echo ""

if [ "$FAIL" -gt 0 ]; then
    echo "  🔴 SOME TESTS FAILED — review output above before considering cutover complete."
    exit 1
else
    echo "  🟢 ALL AUTOMATED TESTS PASSED"
    echo "  Complete the manual verification checklist above."
    exit 0
fi
