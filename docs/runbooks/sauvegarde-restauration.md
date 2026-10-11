# Runbook — Sauvegarde chiffrée et restauration

## Principe

| Élément | Choix |
|---|---|
| Format | `pg_dump --format=custom` (restauration sélective possible) |
| Chiffrement | [age](https://age-encryption.org), clé **publique** sur le VPS, clé **privée** hors VPS (coffre de l'administrateur) |
| Transfert | rclone vers un stockage externe au VPS (S3 compatible, Backblaze B2, SFTP…) |
| Fréquence | quotidienne 02:15 UTC + avant chaque migration de déploiement |
| Rétention | 35 jours par défaut (`BACKUP_RETENTION_DAYS`), à valider avec la politique légale |
| Contrôles | taille minimale, SHA-256 publié à côté, taille distante vérifiée, heartbeat de supervision |

Le dump n'est jamais écrit en clair sur disque : `pg_dump | age` en flux. Un VPS compromis ne peut pas déchiffrer les archives.

Fichiers : [`docker/backup/`](../../docker/backup/).

## Configuration (`/opt/diaba/<env>/backup.env`, chmod 600)

```dotenv
BACKUP_AGE_RECIPIENT=age1...                     # clé publique
BACKUP_REMOTE=offsite:diaba-backups/dev          # préfixe distinct par environnement
BACKUP_RETENTION_DAYS=35
BACKUP_HEARTBEAT_URL=https://...                 # optionnel
# Exemple S3 compatible, sans fichier de configuration rclone :
RCLONE_CONFIG_OFFSITE_TYPE=s3
RCLONE_CONFIG_OFFSITE_PROVIDER=<fournisseur>
RCLONE_CONFIG_OFFSITE_ACCESS_KEY_ID=<clé limitée au bucket>
RCLONE_CONFIG_OFFSITE_SECRET_ACCESS_KEY=<secret>
RCLONE_CONFIG_OFFSITE_ENDPOINT=<endpoint>
```

Recommandé : identifiants limités à l'écriture/listing du bucket et verrouillage d'objets (object lock / versioning) côté fournisseur pour résister à une suppression malveillante.

### Générer la paire de clés (poste administrateur, une fois par environnement)

```bash
age-keygen -o diaba-<env>-backup.key     # clé privée : coffre hors ligne, jamais sur le VPS
age-keygen -y diaba-<env>-backup.key     # clé publique → BACKUP_AGE_RECIPIENT
```

## Sauvegarde manuelle

```bash
docker compose exec -T backup diaba-backup
```

## Exercice de restauration (mensuel, obligatoire)

À réaliser sur une machine **isolée** (jamais sur le PostgreSQL de production) disposant de la clé privée :

```bash
docker network create diaba-drill
docker run -d --name drill-pg --network diaba-drill -e POSTGRES_PASSWORD=<jetable> postgres:18-alpine
docker run --rm --network diaba-drill \
  --env-file backup.env \
  -v "$PWD/diaba-<env>-backup.key:/keys/backup.key:ro" \
  -e BACKUP_AGE_IDENTITY_FILE=/keys/backup.key \
  -e RESTORE_PGHOST=drill-pg -e RESTORE_PGUSER=postgres -e RESTORE_PGPASSWORD=<jetable> \
  ghcr.io/layesamake/diabagescom-backup:<sha> diaba-restore-drill [nom-archive]
docker rm -f drill-pg && docker network rm diaba-drill
```

Le script vérifie la somme SHA-256, déchiffre, restaure avec `--exit-on-error`, puis affiche le nombre de migrations, de tables et d'utilisateurs. Consigner dans le registre d'exploitation : date, archive, durée, résultats, opérateur.

Validé localement le 2026-10-10 : sauvegarde → archive chiffrée (`age-encryption.org/v1`) → restauration isolée → contrôles OK.

### Contrôles métier (depuis le 2026-10-11)

Après la restauration, `docker/backup/integrity-checks.sql` vérifie que chaque contrôle renvoie **0 anomalie** ; sinon l'exercice échoue (« sauvegarde NON validée ») :

| Domaine | Contrôle |
|---|---|
| Comptabilité | écritures validées équilibrées (≥ 2 lignes) ; balance générale débit = crédit ; écritures automatiques tracées dans `accounting_postings` |
| Stock | chaque solde = somme de ses mouvements ; aucune opération sans mouvement |
| Ventes | totaux des documents = somme des lignes ; montant payé = encaissements − remboursements ; montant crédité = avoirs validés |
| Achats | montant réglé des factures fournisseurs = règlements |
| Caisse | solde attendu des sessions fermées = fond + mouvements ; chaque paiement en espèces a son mouvement |
| Numérotation | chaque série (entreprise + préfixe annuel) va de 1 à N sans trou ni doublon |
| Isolation | lignes et en-têtes appartiennent à la même entreprise |

Un module absent d'une ancienne archive est signalé « module absent » et ignoré. Le test `tests/Feature/Backup/IntegrityChecksTest.php` garantit l'absence de fausse alerte sur des opérations réelles et la détection d'une corruption.

Premier exercice réel sur dev : 2026-10-11, archive `diaba_dev-20261011T093734Z.dump.age`, intégrité OK, 13 migrations, 70 tables (avant ajout des contrôles métier).

## Procédure d'incident (restauration réelle)

1. Mettre l'application en maintenance (`php artisan down`) pour geler les mutations.
2. Identifier l'archive cible et estimer la perte (RPO) ; informer le propriétaire.
3. Restaurer d'abord dans un environnement isolé et valider l'intégrité.
4. Restaurer en production uniquement après validation explicite du propriétaire.
5. Vérifier `/up`, connexion, consultation de documents récents ; lever la maintenance.
6. Communiquer aux clients concernés ; rédiger le post-mortem.

RPO/RTO initiaux : RPO ≤ 24 h, restauration testée mensuellement — à confirmer avant commercialisation.
