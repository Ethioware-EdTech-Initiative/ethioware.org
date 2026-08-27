#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# start-instatic-prod.sh — Process manager for production Instatic
#
# Starts the production Instatic CMS server on port 3001. Designed for:
#   - Manual invocation: ~/bin/start-instatic-prod.sh
#   - @reboot crontab: @reboot sleep 30 && nohup ~/bin/start-instatic-prod.sh &
#
# Logs to ~/logs/instatic-prod.log
# ---------------------------------------------------------------------------

set -euo pipefail

PROD_DIR="$HOME/instatic"
BUN="$HOME/.bun/bin/bun"
LOG_DIR="$HOME/logs"
LOG_FILE="$LOG_DIR/instatic-prod.log"
PID_FILE="$HOME/.instatic-prod.pid"

mkdir -p "$LOG_DIR"

# --- Check if already running ---
if [ -f "$PID_FILE" ]; then
    OLD_PID=$(cat "$PID_FILE")
    if kill -0 "$OLD_PID" 2>/dev/null; then
        echo "[$(date -Iseconds)] Instatic prod already running (PID $OLD_PID)" | tee -a "$LOG_FILE"
        exit 0
    fi
    rm -f "$PID_FILE"
fi

# --- Preflight ---
if [ ! -d "$PROD_DIR" ]; then
    echo "[ERROR] Production directory $PROD_DIR does not exist. Run setup-instatic-prod.sh first." >&2
    exit 1
fi

if [ ! -f "$PROD_DIR/.env" ]; then
    echo "[ERROR] $PROD_DIR/.env missing. Run setup-instatic-prod.sh first." >&2
    exit 1
fi

# --- Start ---
echo "[$(date -Iseconds)] Starting Instatic production server..." | tee -a "$LOG_FILE"

cd "$PROD_DIR"
nohup $BUN run start >> "$LOG_FILE" 2>&1 &
echo $! > "$PID_FILE"

sleep 3

# --- Verify ---
PID=$(cat "$PID_FILE")
if kill -0 "$PID" 2>/dev/null; then
    echo "[$(date -Iseconds)] Instatic prod started (PID $PID, port 3001)" | tee -a "$LOG_FILE"
else
    echo "[$(date -Iseconds)] ERROR: Instatic prod failed to start. Check $LOG_FILE" | tee -a "$LOG_FILE" >&2
    exit 1
fi
