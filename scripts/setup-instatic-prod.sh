#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# setup-instatic-prod.sh — P4-1: Set up production Instatic instance
#
# Run on VPS as ethiowzj. Clones the Instatic repo, installs dependencies,
# builds, and prepares the .env for production. After running, you must:
#   1. Import the staging Site Transfer bundle
#   2. Start the server with start-instatic-prod.sh
#
# Usage:
#   SSH into VPS, then:
#   chmod +x scripts/setup-instatic-prod.sh
#   ./scripts/setup-instatic-prod.sh
#
# Prerequisites:
#   - Bun installed at ~/.bun/bin/bun
#   - Staging Instatic working at ~/instatic-staging (for Site Transfer export)
#   - INSTATIC_SECRET_KEY generated (or let this script generate one)
# ---------------------------------------------------------------------------

set -euo pipefail

PROD_DIR="$HOME/instatic"
BUN="$HOME/.bun/bin/bun"
PORT=3001

echo "============================================"
echo "  Instatic Production Setup (P4-1)"
echo "============================================"
echo ""

# --- Preflight checks ---
if [ ! -x "$BUN" ]; then
    echo "[ERROR] Bun not found at $BUN" >&2
    echo "  Install: curl -fsSL https://bun.sh/install | bash" >&2
    exit 1
fi

echo "[INFO] Bun version: $($BUN --version)"

# --- Clone or update Instatic ---
if [ -d "$PROD_DIR/.git" ]; then
    echo "[INFO] Instatic prod directory already exists at $PROD_DIR"
    echo "  Pulling latest..."
    cd "$PROD_DIR" && git pull --ff-only
else
    echo "[INFO] Cloning Instatic to $PROD_DIR..."
    git clone https://github.com/CoreBunch/Instatic.git "$PROD_DIR"
fi

cd "$PROD_DIR"

# --- Install + build ---
echo "[INFO] Installing dependencies..."
$BUN install

echo "[INFO] Building..."
$BUN run build

# --- Create directories ---
mkdir -p "$PROD_DIR/storage" "$PROD_DIR/uploads"

# --- Generate secret key if needed ---
if [ ! -f "$PROD_DIR/.env" ]; then
    echo "[INFO] Generating secret key..."
    SECRET_KEY=$($BUN run scripts/generate-secret-key.ts 2>/dev/null || openssl rand -hex 32)

    cat > "$PROD_DIR/.env" <<EOF
PORT=${PORT}
DATABASE_URL=sqlite:${PROD_DIR}/storage/cms.db
UPLOADS_DIR=${PROD_DIR}/uploads
STATIC_DIR=${PROD_DIR}/dist
INSTATIC_SECRET_KEY=${SECRET_KEY}
PUBLIC_ORIGIN=https://ethioware.org,https://www.ethioware.org,https://cms.ethioware.org
EOF
    chmod 600 "$PROD_DIR/.env"
    echo "[INFO] Created .env at $PROD_DIR/.env"
    echo ""
    echo "  ╔══════════════════════════════════════════════════════╗"
    echo "  ║  SAVE THIS SECRET KEY SECURELY:                     ║"
    echo "  ║  $SECRET_KEY  ║"
    echo "  ╚══════════════════════════════════════════════════════╝"
    echo ""
else
    echo "[INFO] .env already exists — skipping. Check PORT=$PORT is set."
fi

# --- Verify ---
echo "[INFO] Starting quick verification..."
echo "  Testing server can start on port $PORT..."

# Start server in background briefly to confirm it boots
$BUN run start &
SERVER_PID=$!
sleep 5

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${PORT}/admin" 2>/dev/null || echo "000")
kill $SERVER_PID 2>/dev/null || true
wait $SERVER_PID 2>/dev/null || true

if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "302" ]; then
    echo "  ✅ Server responded with HTTP $HTTP_CODE on port $PORT"
else
    echo "  ⚠️  Server responded with HTTP $HTTP_CODE (expected 200 or 302)"
    echo "  Check logs and .env configuration."
fi

echo ""
echo "============================================"
echo "  Setup complete!"
echo "============================================"
echo ""
echo "Next steps:"
echo "  1. Export Site Transfer bundle from staging:"
echo "     https://staging.ethioware.org/admin → Settings → Site Transfer → Export"
echo ""
echo "  2. Import into production:"
echo "     http://127.0.0.1:${PORT}/admin → Settings → Site Transfer → Import"
echo ""
echo "  3. Start the production server:"
echo "     ~/bin/start-instatic-prod.sh"
echo ""
echo "  4. Verify:"
echo "     curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:${PORT}/admin"
echo ""
