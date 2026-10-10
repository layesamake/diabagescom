#!/usr/bin/env bash
# Commande SSH forcée pour la clé de déploiement GitHub Actions.
#
# Dans ~deploy/.ssh/authorized_keys :
#   command="/opt/diaba/bin/ssh-gate.sh <env>",no-pty,no-port-forwarding,no-agent-forwarding,no-X11-forwarding ssh-ed25519 AAAA... github-actions
#
# Seule commande acceptée : "deploy <sha> <opérateur>". Tout le reste est refusé.
set -euo pipefail

ENVIRONMENT="${1:?environnement requis}"
[[ "$ENVIRONMENT" =~ ^(dev|production)$ ]] || { echo "environnement refusé" >&2; exit 2; }

read -r -a ARGS <<< "${SSH_ORIGINAL_COMMAND:-}"

if [[ "${#ARGS[@]}" -eq 3 && "${ARGS[0]}" == "deploy" ]]; then
    exec "/opt/diaba/${ENVIRONMENT}/deploy.sh" "${ARGS[1]}" "${ARGS[2]}"
fi

logger -t diaba-ssh-gate "commande refusée pour ${ENVIRONMENT}"
echo "commande refusée" >&2
exit 2
