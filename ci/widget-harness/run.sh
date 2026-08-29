#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Drives assets/js/chatbot.js in a real browser against a mock chat endpoint.
#
# The widget's failure modes are behavioural — a form that freezes mid-submit,
# a launcher covering the send button, a reply whose links aren't clickable —
# so they need a browser, not a unit test. This boots PHP's built-in server
# with router.php standing in for chat.php (the real one needs MySQL and a
# Gemini key), then runs two harness pages headlessly and prints their results.
#
# Not wired into CI: it needs a local Chrome. ci/chatbot-content-test.php
# covers the parts that can run on a bare runner.
#
# Usage:
#   ci/widget-harness/run.sh            # run both suites
#   ci/widget-harness/run.sh --shots    # also write screenshots to /tmp
# ---------------------------------------------------------------------------
set -euo pipefail

PORT="${PORT:-8799}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HARNESS="ci/widget-harness"
SHOT_DIR="${SHOT_DIR:-/tmp/ethioware-widget-shots}"

CHROME="${CHROME:-}"
if [ -z "$CHROME" ]; then
    for candidate in \
        "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
        "/Applications/Chromium.app/Contents/MacOS/Chromium" \
        "$(command -v google-chrome || true)" \
        "$(command -v chromium || true)"; do
        if [ -n "$candidate" ] && [ -x "$candidate" ]; then CHROME="$candidate"; break; fi
    done
fi
if [ -z "$CHROME" ]; then
    echo "[ERROR] No Chrome found. Set CHROME=/path/to/chrome" >&2
    exit 1
fi
command -v php >/dev/null || { echo "[ERROR] php not on PATH" >&2; exit 1; }

php -S "127.0.0.1:$PORT" -t "$ROOT" "$ROOT/$HARNESS/router.php" >/dev/null 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
sleep 1

# Pulls the <pre id="results"> block out of the rendered DOM.
results() {
    "$CHROME" --headless --disable-gpu --no-sandbox --virtual-time-budget=25000 \
        --window-size="$2" --dump-dom "http://127.0.0.1:$PORT/$1" 2>/dev/null |
    python3 -c 'import sys,re,html
m = re.search(r"<pre id=\"results\">(.*?)</pre>", sys.stdin.read(), re.S)
print(html.unescape(m.group(1)) if m else "NO RESULTS — the harness page did not finish")'
}

status=0

echo "=== widget behaviour (desktop) ==="
out=$(results "$HARNESS/driven-test.html" "1280,900")
echo "$out"
echo "$out" | grep -q "0 failed" || status=1

echo
echo "=== responsive layout (390px viewport, via iframe) ==="
# Headless clamps window.innerWidth to 500, so a phone viewport has to come
# from an iframe rather than --window-size.
out=$(results "$HARNESS/mobile-frame.html" "900,900")
echo "$out"
echo "$out" | grep -q "0 failed" || status=1

if [ "${1:-}" = "--shots" ]; then
    mkdir -p "$SHOT_DIR"
    echo
    echo "=== screenshots -> $SHOT_DIR ==="
    for spec in "desktop-light:1280,860:demo.html?scene=chat&theme=light" \
                "desktop-dark:1280,860:demo.html?scene=chat&theme=dark" \
                "phones:1290,860:phones.html"; do
        name="${spec%%:*}"; rest="${spec#*:}"; size="${rest%%:*}"; page="${rest#*:}"
        "$CHROME" --headless --disable-gpu --no-sandbox --hide-scrollbars \
            --virtual-time-budget=20000 --window-size="$size" \
            --screenshot="$SHOT_DIR/$name.png" \
            "http://127.0.0.1:$PORT/$HARNESS/$page" 2>/dev/null
        echo "  $SHOT_DIR/$name.png"
    done
fi

echo
[ "$status" -eq 0 ] && echo "widget harness: all suites passed" || echo "widget harness: FAILURES above"
exit "$status"
