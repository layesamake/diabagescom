#!/usr/bin/env bash
# DIABA GESCOM — déploiement d'une version immuable sur le VPS.
#
# Usage (dans /opt/diaba/<env>, via deploy/ssh-gate.sh) :
#   ./deploy.sh <sha-git-40-caractères> [opérateur]
#
# Étapes : pull de l'image par SHA → sauvegarde → migrations → redémarrage →
# contrôle de santé → bascule, ou retour à l'image précédente si échec.
# Les migrations ne sont JAMAIS annulées automatiquement : elles doivent rester
# compatibles avec la version N-1 (stratégie expand/contract).
set -euo pipefail

cd "$(dirname "$(readlink -f "$0")")"

NEW_VERSION="${1:-}"
OPERATOR="${2:-unknown}"
[[ "$NEW_VERSION" =~ ^[0-9a-f]{40}$ ]] || { echo "SHA invalide : '$NEW_VERSION'" >&2; exit 2; }
[[ "$OPERATOR" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || OPERATOR="invalid"

PREV_VERSION="$(cat .current-version 2>/dev/null || true)"
DEPLOY_ENV="$(sed -n 's/^DIABA_ENV=//p' .env | tr -d '"')"
HEALTH_BASE_URL="$(sed -n 's/^DIABA_DOMAIN=//p' .env | tr -d '"')"
HEALTH_BASE_URL="https://${HEALTH_BASE_URL}"
LOG_FILE="deployments.log"

COMPOSE_ARGS=()
if [[ "$DEPLOY_ENV" == "dev" ]]; then
    COMPOSE_ARGS=(--profile edge)
fi

compose() {
    docker compose "${COMPOSE_ARGS[@]}" "$@"
}

log_deploy() {
    printf '%s env=%s version=%s previous=%s operator=%s result=%s\n' \
        "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$DEPLOY_ENV" \
        "$NEW_VERSION" "${PREV_VERSION:-none}" "$OPERATOR" "$1" >> "$LOG_FILE"
}

wait_healthy() {
    for _ in $(seq 1 30); do
        if curl -fsS --max-time 5 -o /dev/null "${HEALTH_BASE_URL}/up" \
            && curl -fsS --max-time 5 -o /dev/null "${HEALTH_BASE_URL}/" \
            && curl -fsS --max-time 5 -o /dev/null "${HEALTH_BASE_URL}/login"; then
            return 0
        fi
        sleep 4
    done
    return 1
}

reload_edge_proxy() {
    if [[ -n "$(compose ps -q caddy)" ]]; then
        compose exec -T caddy caddy reload \
            --config /etc/caddy/Caddyfile --adapter caddyfile
    fi
}

rollback_deployment() {
    if [[ -z "$PREV_VERSION" ]]; then
        log_deploy "failed"
        return 1
    fi

    echo "==> Retour à l'image ${PREV_VERSION}" >&2
    DIABA_VERSION="$PREV_VERSION" compose up -d --remove-orphans

    if ! reload_edge_proxy; then
        echo "!! Rechargement du proxy impossible après retour arrière" >&2
        log_deploy "rollback_failed"
        return 1
    fi

    if wait_healthy; then
        log_deploy "rolled_back"
        return 0
    fi

    log_deploy "rollback_failed"
    return 1
}

exec 9> .deploy.lock
flock -n 9 || { echo "Un déploiement est déjà en cours." >&2; exit 3; }

echo "==> Récupération des images ${NEW_VERSION}"
DIABA_VERSION="$NEW_VERSION" compose pull app nginx backup

if [[ -n "$PREV_VERSION" ]]; then
    echo "==> Sauvegarde préalable aux migrations"
    DIABA_VERSION="$PREV_VERSION" compose exec -T backup diaba-backup
fi

echo "==> Migrations"
DIABA_VERSION="$NEW_VERSION" compose run --rm app sh -c "php artisan migrate --force && php artisan diaba:sync-permissions && php artisan diaba:sync-plans"

echo "==> Démarrage de la nouvelle version"
DIABA_VERSION="$NEW_VERSION" compose up -d --remove-orphans

if ! reload_edge_proxy; then
    echo "!! Rechargement du proxy impossible" >&2
    rollback_deployment || true
    exit 1
fi

if wait_healthy; then
    echo "$NEW_VERSION" > .current-version
    log_deploy "success"
    docker image prune -f --filter "until=168h" > /dev/null || true
    echo "==> Déploiement réussi : ${NEW_VERSION}"
    exit 0
fi

echo "!! Contrôle de santé en échec" >&2
rollback_deployment || true
exit 1
