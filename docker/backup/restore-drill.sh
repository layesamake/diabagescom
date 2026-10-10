#!/bin/sh
# Exercice de restauration : récupère la dernière sauvegarde (ou celle passée en
# argument), la déchiffre et la restaure dans un PostgreSQL ISOLÉ, puis vérifie.
#
# À exécuter hors production, sur un hôte détenant la clé privée age :
#   RESTORE_PGHOST / RESTORE_PGUSER / RESTORE_PGPASSWORD   PostgreSQL jetable
#   BACKUP_AGE_IDENTITY_FILE                               clé privée age
#   BACKUP_REMOTE                                          même destination rclone
set -eu

: "${BACKUP_REMOTE:?}" "${BACKUP_AGE_IDENTITY_FILE:?}" "${RESTORE_PGHOST:?}" "${RESTORE_PGUSER:?}"
TARGET_DB="${RESTORE_PGDATABASE:-diaba_restore_drill}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

NAME="${1:-$(rclone lsf --files-only --include '*.dump.age' "$BACKUP_REMOTE/" | sort | tail -n 1)}"
[ -n "$NAME" ] || { echo "Aucune sauvegarde trouvée sur $BACKUP_REMOTE" >&2; exit 1; }
echo "Sauvegarde : $NAME"

rclone copy "$BACKUP_REMOTE/$NAME" "$WORK/"
rclone copy "$BACKUP_REMOTE/$NAME.sha256" "$WORK/"
[ "$(sha256sum "$WORK/$NAME" | awk '{print $1}')" = "$(cat "$WORK/$NAME.sha256")" ] \
    || { echo "Somme de contrôle invalide" >&2; exit 1; }
echo "Intégrité de l'archive : OK"

export PGHOST="$RESTORE_PGHOST" PGUSER="$RESTORE_PGUSER" PGPASSWORD="${RESTORE_PGPASSWORD:-}" PGDATABASE=postgres
psql -v ON_ERROR_STOP=1 -q -c "DROP DATABASE IF EXISTS \"$TARGET_DB\"" -c "CREATE DATABASE \"$TARGET_DB\""

age --decrypt --identity "$BACKUP_AGE_IDENTITY_FILE" "$WORK/$NAME" \
    | pg_restore --exit-on-error --no-owner --no-privileges --dbname="$TARGET_DB"

echo "Restauration dans $TARGET_DB : OK"
echo "Contrôles :"
psql -v ON_ERROR_STOP=1 -d "$TARGET_DB" -At -c "SELECT 'migrations=' || count(*) FROM migrations" \
    -c "SELECT 'tables=' || count(*) FROM information_schema.tables WHERE table_schema = 'public'" \
    -c "SELECT 'users=' || count(*) FROM users"
