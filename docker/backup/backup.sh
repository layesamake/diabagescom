#!/bin/sh
# Sauvegarde logique PostgreSQL chiffrée puis envoyée hors VPS.
#
# Variables requises :
#   PGHOST PGPORT PGDATABASE PGUSER PGPASSWORD   connexion (lecture)
#   BACKUP_AGE_RECIPIENT     clé PUBLIQUE age (la clé privée n'est jamais sur le VPS)
#   BACKUP_REMOTE            destination rclone, ex. "offsite:diaba-backups/production"
#   RCLONE_CONFIG_*          configuration rclone par variables d'environnement
# Optionnelles :
#   BACKUP_RETENTION_DAYS    défaut 35
#   BACKUP_MIN_BYTES         taille minimale attendue, défaut 1024
#   BACKUP_HEARTBEAT_URL     URL appelée en cas de succès (monitoring)
set -eu

: "${PGDATABASE:?}" "${BACKUP_AGE_RECIPIENT:?}" "${BACKUP_REMOTE:?}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-35}"
MIN_BYTES="${BACKUP_MIN_BYTES:-1024}"

log() { printf '{"time":"%s","component":"backup","level":"%s","message":"%s"}\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" "$2"; }

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
NAME="${PGDATABASE}-${STAMP}.dump.age"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

log info "start ${NAME}"

# Le dump n'est jamais écrit en clair sur le disque.
pg_dump --format=custom --no-owner --no-privileges \
    | age --encrypt --recipient "$BACKUP_AGE_RECIPIENT" --output "$WORK/$NAME"

SIZE="$(wc -c < "$WORK/$NAME" | tr -d ' ')"
if [ "$SIZE" -lt "$MIN_BYTES" ]; then
    log error "archive trop petite (${SIZE} octets) : abandon"
    exit 1
fi

sha256sum "$WORK/$NAME" | awk '{print $1}' > "$WORK/$NAME.sha256"

rclone copy --no-traverse "$WORK/$NAME" "$BACKUP_REMOTE/"
rclone copy --no-traverse "$WORK/$NAME.sha256" "$BACKUP_REMOTE/"

# Vérifie que l'objet distant a la bonne taille avant de considérer le succès.
REMOTE_SIZE="$(rclone size --json "$BACKUP_REMOTE/$NAME" | sed -n 's/.*"bytes":\([0-9]*\).*/\1/p')"
if [ "$REMOTE_SIZE" != "$SIZE" ]; then
    log error "taille distante ${REMOTE_SIZE} différente de ${SIZE}"
    exit 1
fi

rclone delete --min-age "${RETENTION_DAYS}d" "$BACKUP_REMOTE/"

log info "ok ${NAME} ${SIZE} octets"

if [ -n "${BACKUP_HEARTBEAT_URL:-}" ]; then
    wget -q -O /dev/null --timeout=10 "$BACKUP_HEARTBEAT_URL" || log warning "heartbeat injoignable"
fi
