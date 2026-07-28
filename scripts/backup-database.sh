#!/usr/bin/env bash
#
# NFR-4 -- daily MySQL backup.
#
# The SRS requires backups because V1 runs real venues' booking operations:
# losing the reservations table means owners cannot tell who is turning up
# tonight, and there is no payment provider holding a second copy of anything.
#
# Install as a daily cron entry (see docs/DEPLOYMENT.md):
#   15 3 * * * /var/www/html/book-your-spot/scripts/backup-database.sh >> /var/log/bys-backup.log 2>&1
#
set -Eeuo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${BYS_BACKUP_DIR:-/var/backups/bookyourspot}"
RETENTION_DAYS="${BYS_BACKUP_RETENTION_DAYS:-14}"

log() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
fail() { log "ERROR: $*"; exit 1; }

# Read credentials from .env rather than duplicating them here, so rotating a
# password in one place does not silently break backups.
[[ -f "${APP_DIR}/.env" ]] || fail "No .env at ${APP_DIR}"

env_value() {
  # Strips optional surrounding quotes; returns empty if the key is absent.
  sed -n "s/^$1=//p" "${APP_DIR}/.env" | head -1 | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

DB_HOST="$(env_value DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_value DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
DB_NAME="$(env_value DB_DATABASE)"
DB_USER="$(env_value DB_USERNAME)"
DB_PASS="$(env_value DB_PASSWORD)"

[[ -n "${DB_NAME}" ]] || fail "DB_DATABASE is not set"

mkdir -p "${BACKUP_DIR}"
chmod 700 "${BACKUP_DIR}"

STAMP="$(date '+%Y%m%d-%H%M%S')"
TARGET="${BACKUP_DIR}/${DB_NAME}-${STAMP}.sql.gz"

log "Backing up ${DB_NAME} to ${TARGET}"

# The password goes via a temporary defaults file, never on the command line --
# argv is world-readable through /proc on a shared host.
CNF="$(mktemp)"
trap 'rm -f "${CNF}"' EXIT
chmod 600 "${CNF}"
cat > "${CNF}" <<EOF
[client]
host=${DB_HOST}
port=${DB_PORT}
user=${DB_USER}
password=${DB_PASS}
EOF

# --single-transaction gives a consistent snapshot of InnoDB tables without
# locking writers, so a nightly backup never blocks a late-night booking.
mysqldump --defaults-extra-file="${CNF}" \
  --single-transaction \
  --routines \
  --triggers \
  --events \
  --default-character-set=utf8mb4 \
  "${DB_NAME}" | gzip -9 > "${TARGET}"

# A dump that silently truncated is worse than no dump, because it looks like a
# backup. Verify the archive is intact and ends with mysqldump's completion marker.
gzip -t "${TARGET}" || fail "Backup archive is corrupt: ${TARGET}"
gunzip -c "${TARGET}" | tail -5 | grep -q 'Dump completed' \
  || fail "Backup is truncated (no completion marker): ${TARGET}"

SIZE="$(du -h "${TARGET}" | cut -f1)"
log "Backup complete (${SIZE})"

DELETED="$(find "${BACKUP_DIR}" -name "${DB_NAME}-*.sql.gz" -mtime "+${RETENTION_DAYS}" -print -delete | wc -l)"
[[ "${DELETED}" -gt 0 ]] && log "Pruned ${DELETED} backup(s) older than ${RETENTION_DAYS} days"

log "Retained: $(find "${BACKUP_DIR}" -name "${DB_NAME}-*.sql.gz" | wc -l) archive(s)"
