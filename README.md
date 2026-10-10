# DIABA GESCOM

SaaS de gestion commerciale et comptable multi-entreprise pour TPE, PME et PMI (XOF, SYSCOHADA).
La spécification de référence est dans [`docs/`](docs/README.md).

## Stack

Laravel 13 · PHP 8.5 · Vue 3 + TypeScript + Inertia · PostgreSQL 18 · Redis 8 · Docker Compose · GitHub Actions.

## Démarrage local (Docker uniquement)

Prérequis : Docker Desktop. PHP et Node ne sont pas nécessaires sur la machine.

```bash
cp .env.example .env          # puis renseigner DB_PASSWORD et REDIS_PASSWORD (valeurs locales)
docker compose up -d
docker compose exec app composer setup:docker
```

| Service                     | Adresse                                    |
| --------------------------- | ------------------------------------------ |
| Application                 | http://localhost:8000                      |
| Vite (rechargement à chaud) | `docker compose --profile vite up -d vite` |
| E-mails de test (Mailpit)   | http://localhost:8025                      |
| PostgreSQL (outils locaux)  | `127.0.0.1:54320`                          |

Tous les ports sont liés à `127.0.0.1`.

## Qualité

```bash
docker compose exec app php artisan test        # tests sur PostgreSQL (base diaba_testing)
docker compose exec app vendor/bin/pint --test  # format PHP
docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app npm run check           # format + lint TS/Vue
docker compose exec app npm run types:check     # TypeScript strict
```

La CI ([`.github/workflows/tests.yml`](.github/workflows/tests.yml)) exécute les mêmes contrôles, l'audit des dépendances et la construction des images.

## Déploiement

- `main` → CI → images `ghcr.io/layesamake/diabagescom*:<sha>` → déploiement automatique sur `dev.diaba.cloud`.
- Production `diaba.cloud` : déclenchement manuel d'un SHA déjà validé, avec approbation de l'environnement GitHub.

Voir [docs/runbooks/deploiement-vps.md](docs/runbooks/deploiement-vps.md) et [docs/runbooks/sauvegarde-restauration.md](docs/runbooks/sauvegarde-restauration.md).

## Règles non négociables

Isolation `tenant_id` côté serveur, montants XOF en entiers, documents validés et écritures immuables, mutations transactionnelles/auditées/idempotentes, aucun secret dans le dépôt, les images, les logs ou le navigateur. Détails : [docs/12-guide-ia.md](docs/12-guide-ia.md).
