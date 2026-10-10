# Architecture technique cible

## Choix technologiques

| Couche | Choix |
|---|---|
| Backend / rendu web | Laravel, version LTS disponible lors du démarrage |
| Interface | Vue 3 + TypeScript + Inertia ; composants accessibles et responsive |
| Base de données | PostgreSQL, version stable supportée |
| Cache / files d’attente | Redis |
| Tâches asynchrones | Laravel queues + workers supervisés |
| PDF | service Laravel dédié exécuté en file d’attente |
| Recherche / index | PostgreSQL au MVP ; moteur dédié seulement si une métrique le justifie |
| PWA | manifest, service worker, IndexedDB et stratégie de synchronisation décrite dans `08-pwa-hors-ligne.md` |
| Déploiement | Docker Compose sur VPS Hostinger, GitHub Actions par SSH restreint |
| Proxy HTTPS | Nginx ou Caddy ; certificats TLS renouvelés automatiquement |

## Principes d’architecture

1. Monolithe modulaire : une application déployable, avec frontières explicites entre domaines.
2. Toute donnée métier porte `tenant_id`; l’isolement est obligatoire dans les requêtes, politiques et tests.
3. Les opérations financières et de stock s’exécutent dans une transaction PostgreSQL et sont idempotentes.
4. Les documents validés et mouvements sont immuables. Les corrections créent de nouvelles traces liées aux originales.
5. Les effets externes (PDF, e-mail, webhooks, paiement) sont déclenchés après validation de la transaction, par une file d’attente idempotente.
6. Le navigateur ne détient jamais de secret ni de privilège d’administration.

## Modules applicatifs

```text
Platform       tenants, plans, subscriptions, billing, support
Identity       accounts, roles, permissions, sessions, MFA, audit
Organization   company profile, warehouses, settings, sequences
Catalog        products, barcodes, categories, units, prices, taxes
CRM            customers, suppliers, addresses, credit terms
Sales          quotes, orders, invoices, payments, returns
Purchasing     purchase orders, receptions, supplier invoices, returns
Inventory      ledger, balances, transfers, adjustments, counts
Treasury       cash sessions, accounts, expenses, bank operations
Accounting     chart, journals, entries, periods, reports
Offline        device registry, commands, sync, conflict management
Reporting      read models, exports, dashboards, alerts
```

Chaque module expose des services applicatifs ; aucun contrôleur ne doit contenir de logique métier complexe. Les modules communiquent par événements internes documentés et transactions, sans appels HTTP internes.

## Environnements

| Élément | Développement | Production |
|---|---|---|
| URL | `dev.diaba.cloud` | `diaba.cloud` |
| Base PostgreSQL | distincte | distincte |
| Redis | distinct | distinct |
| Clés de paiement | sandbox | live |
| E-mails | boîte de test / destinataires limités | fournisseur transactionnel |
| Erreurs | diagnostic complet protégé | suivi d’erreurs sans données personnelles excessives |

## Déploiement attendu

1. Une branche ou une étiquette validée déclenche les tests dans GitHub Actions.
2. Après succès et approbation de l’environnement, l’image applicative est construite et envoyée au VPS.
3. Le VPS récupère l’image identifiée par son SHA ; aucune mise à jour dépendante de `latest`.
4. Exécuter migrations avec sauvegarde préalable et vérification de compatibilité.
5. Redémarrer les services de façon contrôlée, lancer les contrôles de santé, puis basculer le trafic.
6. Enregistrer version, migration et opérateur dans le journal de déploiement.
7. Revenir à l’image antérieure si le contrôle de santé échoue ; ne jamais annuler une migration de données sans plan approuvé.

## Services Docker minimaux

- `web` : application Laravel/PHP-FPM.
- `nginx` : TLS et fichiers statiques.
- `worker` : files d’attente Laravel.
- `scheduler` : planificateur Laravel.
- `postgres` : non exposé publiquement ; volume chiffré selon les capacités du VPS.
- `redis` : non exposé publiquement.
- `backup` : processus planifié avec destination hors VPS.

## Observabilité

- Logs structurés avec identifiant de requête, entreprise pseudonymisée, utilisateur pseudonymisé et corrélation de commande.
- Métriques : latence p50/p95, taux d’erreur, jobs en échec, longueur des files, synchronisations en attente, sauvegardes récentes.
- Alertes : indisponibilité, sauvegarde échouée, stockage critique, workers arrêtés, erreurs de paiement répétées et taux de conflits anormal.
- Aucun mot de passe, token, numéro complet de carte ou contenu sensible de facture dans les logs.

## Décisions d'implémentation (EPIC-00, 2026-10-10)

| Sujet | Décision |
|---|---|
| Versions | Laravel 13, PHP 8.5, Node 24, PostgreSQL 18, Redis 8 ; base issue du starter kit officiel Vue (Inertia, Fortify : 2FA, passkeys, vérification e-mail) |
| Tests | toujours sur PostgreSQL (base `diaba_testing`), jamais SQLite, pour valider contraintes, index et futures politiques RLS |
| Qualité | Pint, PHPStan niveau 7 (Larastan), Vite+ (format/lint TS/Vue), `vue-tsc` strict, `composer audit`, `npm audit` |
| Images | une image applicative unique (`app`) pour web/worker/scheduler + image `nginx` + image `backup` ; tag = SHA Git ; conteneurs en lecture seule, utilisateur `www-data`, `no-new-privileges` |
| TLS | Caddy en bordure (certificats automatiques, HSTS) → Nginx interne (statiques, FastCGI) → PHP-FPM |
| Réseau | PostgreSQL et Redis sur un réseau Docker `internal`, aucun port publié ; sortie Internet via un réseau `egress` réservé à app/worker/scheduler/backup |
| Santé | `/up` vérifie PostgreSQL et Redis ; utilisé par le déploiement pour basculer ou revenir à l'image précédente |
| Logs | JSON sur stderr (`LOG_STDERR_FORMATTER`), `request_id` (en-tête `X-Request-Id`) propagé dans le contexte Laravel |
| Déploiement | GitHub Actions → GHCR → SSH avec commande forcée (`deploy <sha> <opérateur>`) ; dev automatique depuis `main`, production manuelle avec approbation |
| Sauvegardes | `pg_dump` custom chiffré avec age (clé publique sur le VPS), envoyé par rclone hors VPS ; exercice de restauration scripté |

### Décisions ouvertes (à valider par le propriétaire)

- Fournisseur du stockage de sauvegarde hors VPS et durée de rétention.
- Outil de supervision/alerte (sonde `/up`, heartbeat de sauvegarde, métriques hôte).
- Fournisseur d'e-mails transactionnels de production.
- Un VPS par environnement (recommandé) ou VPS partagé dev/production.
