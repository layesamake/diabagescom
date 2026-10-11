# DIABA GESCOM — sauvegarde PostgreSQL chiffrée (age) envoyée hors VPS (rclone).
ARG POSTGRES_VERSION=18
FROM postgres:${POSTGRES_VERSION}-alpine

RUN apk add --no-cache age rclone tini

COPY --chmod=0755 docker/backup/backup.sh /usr/local/bin/diaba-backup
COPY --chmod=0755 docker/backup/restore-drill.sh /usr/local/bin/diaba-restore-drill
COPY docker/backup/integrity-checks.sql /usr/local/share/diaba/integrity-checks.sql
COPY docker/backup/crontab /etc/crontabs/root

ENTRYPOINT ["/sbin/tini", "--"]
CMD ["crond", "-f", "-l", "6"]
