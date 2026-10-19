# Runbook — Mise en service et déploiement VPS

S'applique à `dev.diaba.cloud` et `diaba.cloud`, chacun avec ses propres répertoires, bases, secrets et volumes. Lorsque les deux environnements partagent un VPS, seul dev exécute Caddy ; production rejoint son réseau edge externe.

## Architecture d'un environnement

```text
Internet ─► Caddy :443 (TLS Let's Encrypt, HSTS) ─► Nginx :8080 ─► PHP-FPM (app)
                                                                     │
             worker (queues) · scheduler · backup ─── réseau interne ─┤
                                                     PostgreSQL · Redis (jamais publiés)
```

Fichiers : [`deploy/compose.yaml`](../../deploy/compose.yaml), [`deploy/Caddyfile`](../../deploy/Caddyfile), [`deploy/deploy.sh`](../../deploy/deploy.sh), [`deploy/ssh-gate.sh`](../../deploy/ssh-gate.sh).

## 1. Préparation du VPS (une fois, par l'administrateur)

1. Mises à jour de sécurité automatiques (`unattended-upgrades`) ; pare-feu : 22 (restreint si possible), 80, 443 uniquement.
2. SSH : authentification par clé uniquement (`PasswordAuthentication no`, `PermitRootLogin no`).
3. Installer Docker Engine + plugin Compose depuis le dépôt officiel Docker.
4. Créer l'utilisateur `deploy` (membre du groupe `docker`), sans mot de passe.
5. Arborescence :
   ```text
   /opt/diaba/bin/ssh-gate.sh          (root:root 0755)
   /opt/diaba/<env>/compose.yaml       copie de deploy/compose.yaml
   /opt/diaba/<env>/Caddyfile
   /opt/diaba/<env>/deploy.sh          (0755)
   /opt/diaba/<env>/.env               (deploy:deploy 0600) — secrets applicatifs
   /opt/diaba/<env>/backup.env         (deploy:deploy 0600) — destination et clé publique de sauvegarde
   ```
6. DNS : enregistrement A/AAAA de `dev.diaba.cloud` / `diaba.cloud` vers le VPS.
7. Si le paquet GHCR est privé : `docker login ghcr.io` avec un jeton en **lecture seule** (`read:packages`) dédié au VPS.

## 2. Contenu de `/opt/diaba/<env>/.env`

Partir de `.env.example` et ajouter/adapter :

```dotenv
DIABA_ENV=dev                         # ou production
DIABA_IMAGE=ghcr.io/layesamake/diabagescom
DIABA_DOMAIN=dev.diaba.cloud
ACME_EMAIL=<adresse technique>
DIABA_EDGE_NETWORK=diaba-dev_edge     # réseau edge propre, ou partagé sur un même VPS
DIABA_EDGE_EXTERNAL=false              # true pour production sur le VPS partagé

APP_ENV=production                    # aussi sur dev : même image, même comportement
APP_DEBUG=false
APP_URL=https://dev.diaba.cloud
APP_KEY=base64:...                    # php artisan key:generate --show, propre à chaque env
LOG_CHANNEL=stderr
LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter
LOG_LEVEL=info
SESSION_SECURE_COOKIE=true
DB_PASSWORD=<aléatoire 32+>
REDIS_PASSWORD=<aléatoire 32+>
MAIL_*=<boîte de test (dev) / fournisseur transactionnel (prod)>
```

Les secrets de dev et de production sont **tous différents**.

Sur le VPS partagé : dev conserve `DIABA_EDGE_NETWORK=diaba-dev_edge` et `DIABA_EDGE_EXTERNAL=false`; production utilise le même nom avec `DIABA_EDGE_EXTERNAL=true`. Le service Caddy appartient au profil `edge`, activé automatiquement par `deploy.sh` uniquement pour dev. Installer alors `deploy/Caddyfile.shared` comme `/opt/diaba/dev/Caddyfile`, afin de router explicitement `dev-nginx` et `production-nginx`.

## 3. Clé de déploiement restreinte

Générer une paire ed25519 dédiée par environnement. Dans `~deploy/.ssh/authorized_keys` :

```text
command="/opt/diaba/bin/ssh-gate.sh dev",no-pty,no-port-forwarding,no-agent-forwarding,no-X11-forwarding ssh-ed25519 AAAA... github-actions-dev
```

Côté GitHub, environnements `dev` et `production` (Settings → Environments), avec les secrets :
`DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS` (sortie de `ssh-keyscan`), `DEPLOY_HOST`, `DEPLOY_USER`.
L'environnement `production` exige un **réviseur obligatoire** et n'autorise que la branche `main`.

## 4. Déroulé d'un déploiement

1. Push sur `main` → CI complète → construction et publication des images taguées par SHA.
2. Le job `deploy` se connecte en SSH ; la commande forcée n'accepte que `deploy <sha> <opérateur>`.
3. `deploy.sh` : verrou → pull → sauvegarde préalable → `migrate --force` → `up -d` → contrôle `https://<domaine>/up` (base + Redis vérifiés).
4. Succès : version enregistrée dans `.current-version` et `deployments.log` (date, env, version, précédente, opérateur, résultat).
5. Échec : retour automatique à l'image précédente. **Les migrations ne sont pas annulées** : toute migration doit rester compatible avec la version N-1 (ajout de colonnes/tables d'abord, suppression dans une version ultérieure).

Production : Actions → `deploy` → *Run workflow* → `production` + SHA déjà déployé et validé sur dev.

## 5. Premier démarrage

```bash
cd /opt/diaba/dev
./deploy.sh <sha> admin       # la sauvegarde préalable est ignorée au tout premier déploiement
```

## 6. Supervision (à brancher)

- Disponibilité : sonde externe sur `https://<domaine>/up` toutes les minutes.
- Sauvegardes : `BACKUP_HEARTBEAT_URL` (moniteur de type « cron heartbeat ») alerté en cas d'absence.
- Hôte : disque, mémoire, CPU, expiration TLS, état des conteneurs (`docker compose ps`).
- Logs : JSON sur stdout/stderr avec `request_id` ; rotation Docker 5 × 20 Mo par service.

Le choix de l'outil de supervision et d'alerte reste à valider (voir « Décisions ouvertes » dans `03-architecture-technique.md`).
