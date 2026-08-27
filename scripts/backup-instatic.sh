#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# backup-instatic.sh — P4-5: Daily backup for Instatic CMS data
#
# Backs up the SQLite database and uploads directory for both production
# and staging Instatic instances. Retains 7 days of backups.
#
# Usage:
#   ./scripts/backup-instatic.sh
#
# Cron (add to crontab -e):
#   0 3 * * * /home/ethiowzj/public_html/scripts/backup-instatic.sh >> ~/logs/backup.log 2>&1
# ---------------------------------------------------------------------------

set -euo pipefail

BACKUP_DIR="$HOME/backups/instatic"
RETENTION_DAYS=7
DATE_STAMP=$(date +%Y-%m-%d_%H%M)
LOG_PREFIX="[$(date -Iseconds)]"

# Directories to back up
PROD_DIR="$HOME/instatic"
STAGING_DIR="$HOME/instatic-staging"

mkdir -p "$BACKUP_DIR"

echo "$LOG_PREFIX Starting Instatic backup..."

# --- Production backup ---
if [ -d "$PROD_DIR/storage" ]; then
    PROD_BACKUP="$BACKUP_DIR/instatic-prod-${DATE_STAMP}.tar.gz"

    # Use a list of existing paths to avoid tar errors
    TAR_PATHS=()
    [ -f "$PROD_DIR/storage/cms.db" ] && TAR_PATHS+=("instatic/storage/cms.db")
    [ -d "$PROD_DIR/uploads" ] && TAR_PATHS+=("instatic/uploads")
    [ -f "$PROD_DIR/.env" ] && TAR_PATHS+=("instatic/.env")

    if [ ${#TAR_PATHS[@]} -gt 0 ]; then
        tar -czf "$PROD_BACKUP" -C "$HOME" "${TAR_PATHS[@]}" 2>/dev/null
        PROD_SIZE=$(du -h "$PROD_BACKUP" | cut -f1)
        echo "$LOG_PREFIX  ✅ Production backup: $PROD_BACKUP ($PROD_SIZE)"
    else
        echo "$LOG_PREFIX  ⚠️  No production data files found to back up"
    fi
else
    echo "$LOG_PREFIX  ⚠️  Production Instatic not installed at $PROD_DIR — skipping"
fi

# --- Staging backup ---
if [ -d "$STAGING_DIR/storage" ]; then
    STAGING_BACKUP="$BACKUP_DIR/instatic-staging-${DATE_STAMP}.tar.gz"

    TAR_PATHS=()
    [ -f "$STAGING_DIR/storage/cms.db" ] && TAR_PATHS+=("instatic-staging/storage/cms.db")
    [ -d "$STAGING_DIR/uploads" ] && TAR_PATHS+=("instatic-staging/uploads")
    [ -f "$STAGING_DIR/.env" ] && TAR_PATHS+=("instatic-staging/.env")

    if [ ${#TAR_PATHS[@]} -gt 0 ]; then
        tar -czf "$STAGING_BACKUP" -C "$HOME" "${TAR_PATHS[@]}" 2>/dev/null
        STAGING_SIZE=$(du -h "$STAGING_BACKUP" | cut -f1)
        echo "$LOG_PREFIX  ✅ Staging backup: $STAGING_BACKUP ($STAGING_SIZE)"
    else
        echo "$LOG_PREFIX  ⚠️  No staging data files found to back up"
    fi
else
    echo "$LOG_PREFIX  ℹ️  Staging Instatic not installed — skipping"
fi

# --- MySQL backup (applications + chatbot tables) ---
# Only if mysqldump is available and config exists
MYSQL_CONFIG="$HOME/public_html/research-scholars/config.php"
if command -v mysqldump &>/dev/null && [ -f "$MYSQL_CONFIG" ]; then
    # Extract credentials from PHP config (best-effort)
    DB_HOST=$(php -r "require '$MYSQL_CONFIG'; echo DB_HOST ?? 'localhost';" 2>/dev/null || echo "")
    DB_USER=$(php -r "require '$MYSQL_CONFIG'; echo DB_USER ?? '';" 2>/dev/null || echo "")
    DB_PASS=$(php -r "require '$MYSQL_CONFIG'; echo DB_PASS ?? '';" 2>/dev/null || echo "")
    DB_NAME=$(php -r "require '$MYSQL_CONFIG'; echo DB_NAME ?? '';" 2>/dev/null || echo "")

    if [ -n "$DB_USER" ] && [ -n "$DB_NAME" ]; then
        MYSQL_BACKUP="$BACKUP_DIR/mysql-${DATE_STAMP}.sql.gz"
        mysqldump -h "${DB_HOST:-localhost}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
            --tables applications rsp_signups chatbot_leads chatbot_conversations chatbot_events supporters 2>/dev/null \
            | gzip > "$MYSQL_BACKUP"
        MYSQL_SIZE=$(du -h "$MYSQL_BACKUP" | cut -f1)
        echo "$LOG_PREFIX  ✅ MySQL backup: $MYSQL_BACKUP ($MYSQL_SIZE)"
    fi
else
    echo "$LOG_PREFIX  ℹ️  MySQL backup skipped (mysqldump not available or config missing)"
fi

# --- Cleanup old backups ---
DELETED=$(find "$BACKUP_DIR" -name "instatic-*.tar.gz" -mtime +${RETENTION_DAYS} -delete -print | wc -l)
DELETED_SQL=$(find "$BACKUP_DIR" -name "mysql-*.sql.gz" -mtime +${RETENTION_DAYS} -delete -print | wc -l)
TOTAL_DELETED=$((DELETED + DELETED_SQL))

if [ "$TOTAL_DELETED" -gt 0 ]; then
    echo "$LOG_PREFIX  🗑️  Cleaned up $TOTAL_DELETED backups older than ${RETENTION_DAYS} days"
fi

# --- Summary ---
TOTAL_SIZE=$(du -sh "$BACKUP_DIR" | cut -f1)
TOTAL_COUNT=$(find "$BACKUP_DIR" -name "*.tar.gz" -o -name "*.sql.gz" | wc -l)
echo "$LOG_PREFIX Backup complete. Total: ${TOTAL_COUNT} files, ${TOTAL_SIZE} used in $BACKUP_DIR"
