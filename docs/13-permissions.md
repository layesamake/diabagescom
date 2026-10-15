# Matrice de permissions

## Modèle

Une permission est une action atomique exprimée en `domaine.action`. Les rôles assemblent des permissions ; les affectations de dépôt limitent ensuite les ressources accessibles. `all` ne doit pas être utilisé comme permission de base de données ; il est résolu au niveau applicatif pour l’administrateur de son tenant uniquement.

## Catalogue de permissions

| Domaine | Permissions |
|---|---|
| Organisation | `organization.view`, `organization.manage`, `warehouse.view`, `warehouse.manage`, `audit.view` (journal d’audit de l’entreprise, administrateur par défaut) |
| Membres | `member.view`, `member.invite`, `member.manage`, `role.view`, `role.manage` |
| Catalogue | `catalog.view`, `catalog.create`, `catalog.update`, `catalog.archive`, `catalog.price_override` |
| Clients/fournisseurs | `customer.view/manage`, `supplier.view/manage` |
| Ventes | `sale.view`, `sale.create`, `sale.update_draft`, `sale.validate`, `sale.cancel`, `sale.discount_standard`, `sale.discount_exception`, `sale.sell_negative_stock` |
| Achats | `purchase.view`, `purchase.create`, `purchase.update_draft`, `purchase.receive`, `purchase.validate`, `purchase.cancel` |
| Demandes d’achat | `purchase_request.view`, `purchase_request.create`, `purchase_request.approve` — vendeur et dépôt : voir et créer (leurs propres demandes) ; achats : voir, créer, approuver et voir toutes celles de leurs dépôts ; direction : voir et approuver. L’approbation par l’auteur lui-même est permise mais signalée dans l’audit. |
| Stock | `inventory.view`, `inventory.transfer_create`, `inventory.transfer_ship`, `inventory.transfer_receive`, `inventory.count_create`, `inventory.count_validate`, `inventory.adjust` |
| Trésorerie | `cash.view_own`, `cash.view_all`, `cash.open_close`, `cash.receive`, `cash.expense`, `cash.refund`, `bank.view`, `bank.reconcile` |
| Comptabilité | `accounting.view`, `accounting.manual_entry`, `accounting.post`, `accounting.period_close`, `accounting.settings`, `accounting.export` |
| Rapports | `report.view_own`, `report.view_all`, `report.margin_view`, `report.export` |
| Plateforme | `platform.tenants.manage`, `platform.plans.manage`, `platform.billing.manage`, `platform.support_access`, `platform.audit.view` |

## Rôles intégrés tenant

| Permission / rôle | Administrateur | Vendeur/Caissier | Dépôt | Achats | Comptable | Direction |
|---|---:|---:|---:|---:|---:|---:|
| Configurer entreprise, dépôts et membres | Oui | Non | Non | Non | Non | Non |
| Voir catalogue | Oui | Oui | Oui | Oui | Oui | Oui |
| Modifier produit/prix | Oui | Non | Non | Selon délégation | Non | Non |
| Créer et valider vente | Oui | Oui | Consultation | Non | Non | Non |
| Modifier prix / remise exceptionnelle | Oui | Délégation | Non | Non | Non | Non |
| Vente avec stock négatif | Oui | Délégation | Non | Non | Non | Non |
| Gérer achats et réception | Oui | Non | Réception | Oui | Consultation | Consultation |
| Gérer stock / inventaire | Oui | Non | Oui | Consultation | Consultation | Consultation |
| Encaisser / caisse | Oui | Caisse affectée | Non | Non | Consultation | Consultation |
| Comptabilité | Oui | Non | Non | Consultation | Oui | Consultation |
| Rapports globaux / marges | Oui | Non | Dépôt affecté | Achats | Oui | Oui |
| Exporter | Oui | Non par défaut | Dépôt autorisé | Autorisé | Oui | Autorisé |

## Contraintes supplémentaires

- Le super-administrateur Diaba n’est jamais automatiquement membre d’un tenant. L’accès support est ponctuel, justifié, journalisé, limité dans le temps et visible dans l’audit du tenant.
- `cash.view_own` ne donne accès qu’aux sessions et transactions de caisses affectées à l’utilisateur.
- Toute permission de validation est évaluée avec le statut du document, le dépôt, la période comptable et la souscription.
- Les rôles personnalisés sont autorisés après le MVP ; ils ne peuvent jamais attribuer une permission plateforme.
- La suppression est remplacée par archivage ou correction : aucune permission `delete` pour les événements financiers ou de stock.

## Implémentation (EPIC-01)

- Catalogue et rôles intégrés : `app/Domain/Identity/PermissionCatalog.php`, synchronisés en base par `php artisan diaba:sync-permissions` (idempotent, exécuté à chaque déploiement).
- Codes des rôles intégrés : `admin`, `cashier`, `warehouse_manager`, `purchaser`, `accountant`, `management` ; rôle plateforme `super_admin`.
- MFA (TOTP ou passkey) imposée aux rôles `admin` et `super_admin` avant tout accès à l’espace entreprise.
- Un compte n’est jamais supprimé physiquement : la clôture anonymise les données personnelles, révoque les moyens d’authentification et conserve les traces d’audit. Elle est refusée tant que le compte appartient à une entreprise active.
