#!/bin/sh
# TaskWeaver SQLite backup.
#
# Takes an online (WAL-safe) copy of the application's SQLite database into a
# timestamped snapshot directory. Uses sqlite3's native `.backup` command,
# which is safe to run while the app is writing — no file locking issues.
#
# Sources DATABASE_URL from the environment, falling back to the committed
# .env defaults:
#   sqlite:////absolute/path/to/db.db       (4 slashes = absolute)
#   sqlite:///var/data/taskweaver.db        (specific for the project layout)
#
# Usage:
#   bin/backup-db.sh                        # default: backups under var/backups/
#   bin/backup-db.sh /mnt/backup-share      # custom target directory
#   BACKUP_KEEP=30 bin/backup-db.sh         # keep last N snapshots (default: 14)
#
# Cron example (host, daily at 03:15):
#   15 3 * * * cd /opt/taskweaver && bin/backup-db.sh /var/backups/taskweaver
#
# Restore:
#   sqlite3 /path/to/taskweaver.db ".restore '/var/backups/taskweaver/TIMESTAMP/taskweaver.db'"
#
# Env vars: PROJECT_DIR (override repo root), DATABASE_URL (path to the live
# DB), BACKUP_KEEP (snapshots to retain, default 14).

set -eu

# Allow the caller to override the project root (useful for tests or running
# the script from a different CWD on the same host).
PROJECT_DIR="${PROJECT_DIR:-$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)}"
BACKUP_ROOT="${1:-$PROJECT_DIR/var/backups}"
BACKUP_KEEP="${BACKUP_KEEP:-14}"

# Resolve the SQLite file path from DATABASE_URL (or fall back to the
# prod/test default used by .env.example).
DB_URL="${DATABASE_URL:-sqlite:///%kernel.project_dir%/var/data/taskweaver.db}"
DB_URL="${DB_URL#sqlite://}"
# %kernel.project_dir% placeholder → real path; absolute URLs are 4-slashes.
DB_PATH="$(printf '%s' "$DB_URL" | sed "s|%kernel.project_dir%|$PROJECT_DIR|g; s|^//\?/|/|")"

if [ ! -f "$DB_PATH" ]; then
    echo "ERROR: database file not found at '$DB_PATH'" >&2
    echo "Hint: set DATABASE_URL or create the database first." >&2
    exit 1
fi

if ! command -v sqlite3 >/dev/null 2>&1; then
    echo "ERROR: sqlite3 CLI is required for backups (apt-get install sqlite3)." >&2
    exit 1
fi

TIMESTAMP="$(date -u +'%Y%m%dT%H%M%SZ')"
DEST_DIR="$BACKUP_ROOT/$TIMESTAMP"
mkdir -p "$DEST_DIR"

# Online backup — safe against concurrent writes (and WAL journals).
sqlite3 "$DB_PATH" ".timeout 5000" ".backup '$DEST_DIR/taskweaver.db'"

# Basic integrity check on the snapshot (cheap, catches mid-copy corruption).
if [ "$(sqlite3 "$DEST_DIR/taskweaver.db" 'PRAGMA quick_check;' 2>/dev/null)" != 'ok' ]; then
    echo "ERROR: backed-up snapshot failed PRAGMA quick_check" >&2
    exit 1
fi

# Rotate: keep only the newest N snapshots.
ls -1d "$BACKUP_ROOT"/*/ 2>/dev/null \
    | sort -r \
    | tail -n +$((BACKUP_KEEP + 1)) \
    | xargs -r rm -rf

echo "OK  $DEST_DIR/taskweaver.db  ($(du -h "$DEST_DIR/taskweaver.db" | cut -f1))"
echo "Restore: sqlite3 \"$DB_PATH\" \".restore '$DEST_DIR/taskweaver.db'\""
