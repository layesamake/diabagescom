# Modèle de données

## Conventions générales

- PostgreSQL ; clés primaires UUID ou ULID ; dates au format UTC (`timestamptz`).
- Tous les montants monétaires sont des `bigint` en unité XOF, sans décimales. Ex. `12500` signifie 12 500 XOF.
- Toutes les tables métier portent `tenant_id NOT NULL` et sont indexées au minimum par `(tenant_id, id)`.
- Les tables de documents conservent `status`, `created_by`, `validated_by`, `validated_at`, `cancelled_by`, `cancelled_at`, `version` et horodatages appropriés.
- Préférer l’archivage (`archived_at`) à la suppression. Les tables de ledger, paiements, écritures et audit n’ont pas de suppression métier.
- Utiliser des contraintes `CHECK`, clés étrangères et index uniques composites pour faire respecter les invariants en base.

## Plateforme et identité

| Table | Champs essentiels | Contraintes |
|---|---|---|
| `tenants` | id, legal_name, display_name, country_code, currency_code, timezone, locale, status | devise `XOF` au lancement |
| `plans` | id, code, name, warehouse_limit, accounting_enabled, included_users, price_monthly, price_yearly, active | code unique |
| `subscriptions` | id, tenant_id, plan_id, status, trial_ends_at, read_only_ends_at, current_period_start/end, billing_cycle | une souscription active par tenant |
| `subscription_addons` | id, subscription_id, addon_code, quantity, status, period dates | quantité positive |
| `platform_users` | id, email, password_hash, status, mfa fields | e-mail unique normalisé |
| `tenant_memberships` | id, tenant_id, user_id, role_id, status | unique `(tenant_id,user_id)` |
| `roles` | id, tenant_id nullable, code, name, system | rôle plateforme séparé des rôles tenant |
| `permissions` | code, module, action | catalogue central |
| `role_permissions` | role_id, permission_code | unique composite |
| `warehouse_memberships` | warehouse_id, membership_id | unique composite |
| `audit_events` | id, tenant_id nullable, actor_id, action, entity_type/id, before/after sanitized, request_id, ip, created_at | append-only |

## Organisation et catalogue

| Table | Champs essentiels | Contraintes |
|---|---|---|
| `warehouses` | id, tenant_id, code, name, address, status | code unique par tenant |
| `tax_rates` | id, tenant_id, name, rate_basis_points, active | TVA par défaut 1800 |
| `units` | id, tenant_id, code, name, decimals_allowed | code unique par tenant |
| `product_categories` | id, tenant_id, parent_id, name, archived_at | arbre sans cycles |
| `products` | id, tenant_id, sku, name, type, category_id, unit_id, tax_rate_id, sale_price, standard_cost, active | SKU unique par tenant |
| `product_barcodes` | id, tenant_id, product_id, code, symbology, is_primary | code unique par tenant |
| `product_warehouse_settings` | product_id, warehouse_id, reorder_point, preferred_location | unique produit/dépôt |
| `price_lists` / `price_list_items` | tenant_id, name, dates, product_id, unit_price | préparation tarifs futurs |

## Tiers

| Table | Champs essentiels |
|---|---|
| `customers` | tenant_id, code, name, phone, email, address, credit_limit, payment_term_days, active |
| `suppliers` | tenant_id, code, name, phone, email, address, payment_term_days, active |
| `party_addresses` | tenant_id, party_type, party_id, label, address fields, is_default |

## Documents commerciaux

Les documents partagent un format : en-tête, lignes, taxes calculées, totaux figés à validation, séquence et références liées.

| Table | Champs essentiels |
|---|---|
| `sales_quotes` / `sales_quote_lines` | tenant_id, warehouse_id, number, customer_id nullable, status, expires_at, totals ; product snapshot, label, quantity, unit_price, discount, tax rate |
| `sales_orders` / `sales_order_lines` | mêmes champs, `source_quote_id` |
| `sales_invoices` / `sales_invoice_lines` | tenant_id, warehouse_id, number, customer_id nullable, source_order_id, status, issue/due dates, payment status, totals, offline_command_id nullable |
| `sales_credit_notes` / lines | tenant_id, invoice_id, reason, totals, status |
| `purchase_orders` / lines | tenant_id, warehouse_id, supplier_id, number, status, dates, expected totals |
| `goods_receipts` / lines | tenant_id, warehouse_id, supplier_id, purchase_order_id nullable, number, status, received_at |
| `supplier_invoices` / lines | tenant_id, supplier_id, goods_receipt_id nullable, number, status, dates, totals |
| `supplier_credit_notes` / lines | tenant_id, supplier_invoice_id nullable, reason, status, totals |

`number` est unique par `(tenant_id, document_type, fiscal_year)` et attribué seulement au moment de la validation. Les libellés et prix des lignes sont des instantanés : ne pas les relire depuis le catalogue après validation.

## Stock et trésorerie

| Table | Champs essentiels | Règles |
|---|---|---|
| `inventory_movements` | tenant_id, warehouse_id, product_id, type, quantity_delta, unit_cost, source_type/id, occurred_at, created_by | append-only ; quantité non nulle |
| `inventory_balances` | tenant_id, warehouse_id, product_id, quantity_on_hand, quantity_reserved, average_cost, updated_at | projection transactionnelle ; unique produit/dépôt |
| `stock_transfers` / lines | tenant_id, source_warehouse_id, destination_warehouse_id, number, status, shipped/received timestamps | source ≠ destination |
| `inventory_counts` / lines | tenant_id, warehouse_id, status, counted quantity, theoretical quantity, variance, reason | validation requise |
| `cash_registers` | tenant_id, warehouse_id nullable, name, account_id, active |
| `cash_sessions` | tenant_id, cash_register_id, opened_by/at, opening amount, closed_by/at, expected/actual amount, difference, status | une session ouverte par caisse |
| `payments` | tenant_id, direction, party type/id, payment_method, amount, currency, status, paid_at, source_document type/id, external_reference | idempotency key unique par tenant |
| `treasury_transactions` | tenant_id, account_id, payment_id nullable, type, amount, occurred_at, source type/id | append-only |

## Comptabilité

| Table | Champs essentiels | Règles |
|---|---|---|
| `fiscal_years` | tenant_id, name, starts_on, ends_on, status | périodes non chevauchantes |
| `accounting_periods` | tenant_id, fiscal_year_id, month, status | ouvert/verrouillé/clôturé |
| `chart_of_accounts` | tenant_id, code, name, account_type, parent_id, active, system_mapping | code unique par tenant |
| `accounting_journals` | tenant_id, code, name, type, default debit/credit accounts | code unique par tenant |
| `journal_entries` | tenant_id, journal_id, number, entry_date, period_id, status, source type/id, description, posted_at | append-only après comptabilisation |
| `journal_entry_lines` | entry_id, account_id, debit, credit, party type/id, warehouse_id nullable, label | `(debit=0) <> (credit=0)` |
| `bank_reconciliations` / lines | tenant_id, account_id, statement dates, transaction references, status | rapprochement traçable |

Une écriture comptabilisée respecte obligatoirement `SUM(debit) = SUM(credit)` ; l’application et une contrainte différée/contrôle transactionnel doivent le vérifier.

## Tables d’intégration et PWA

| Table | Champs essentiels |
|---|---|
| `payment_provider_events` | provider, external_event_id, payload encrypted/redacted, status, processed_at ; unique provider/event |
| `webhook_deliveries` | tenant_id nullable, endpoint, event, payload reference, status, attempts, next_retry_at |
| `offline_devices` | tenant_id, user_id, device_id, public key/device metadata, last_seen_at, revoked_at |
| `sync_commands` | tenant_id, device_id, command_id, type, payload hash, status, accepted/result/error, created_at ; unique tenant/device/command |
| `sync_conflicts` | tenant_id, command_id, entity type/id, reason, resolution status, resolved_by/at |

## Mise en œuvre du catalogue (EPIC-03, 2026-10-12)

- `tax_rates.rate_basis_points` (1800 = 18 %), un seul taux par défaut par entreprise (index partiel). Valeurs initiales : TVA 18 % et Exonéré 0 %, à valider par l'expert-comptable.
- `units.decimals_allowed` ; quantités futures en `numeric(15,3)` (jamais de flottant), ex. `product_warehouse_settings.reorder_point`.
- `products.sku` et `product_barcodes.code` uniques par entreprise **sans tenir compte de la casse** ; un seul code principal par article ; EAN-13/UPC-A vérifiés par leur clé de contrôle ; codes internes EAN-13 à préfixe `2`.
- `products.version` : verrou optimiste (une modification concurrente est refusée, jamais écrasée).
- Catégories : arbre sans cycle (trigger PostgreSQL), photo facultative.
- Photos d'articles et de catégories : ré-encodées en WebP (1200 px max, métadonnées supprimées), stockage privé `tenants/{id}/…`, affichage par URL signée de 30 minutes.
- `tenant_sequences` : compteurs par entreprise verrouillés (`SELECT … FOR UPDATE`) — codes clients `C-00001`, fournisseurs `F-00001`, codes-barres internes ; réutilisés pour la numérotation des documents (EPIC-05).
- Recherche insensible aux accents et à la casse : fonction `diaba_search_text()` (`unaccent` + `lower`) et index trigramme `pg_trgm`.
- `catalog_imports` : import CSV d'articles (aperçu, erreurs par ligne, application unique et tout-ou-rien, rapprochement par SKU).
- Écart assumé : `party_addresses` (adresses multiples) est reporté ; clients et fournisseurs portent une adresse principale (`address_line`, `city`) et le NINEA (`tax_number`).

## Mise en œuvre du stock (EPIC-04, 2026-10-13)

- `stock_operations` (une par action, clé d'idempotence unique par entreprise) et `inventory_movements` (lignes) : **append-only** (trigger PostgreSQL + modèles). Seul `App\Domain\Inventory\StockLedger` les écrit.
- `inventory_balances` : projection mise à jour dans la même transaction ; verrouillage `FOR UPDATE` dans l'ordre (dépôt, article) contre pertes de mise à jour et interblocages. Invariant testé : solde = somme des mouvements.
- Quantités `numeric(15,3)` manipulées en bcmath (`Quantity`), jamais en flottant ; unités non fractionnables refusant les décimales ; services sans stock.
- Valorisation au **coût moyen pondéré** en XOF entiers (arrondi au franc) ; sorties valorisées au coût moyen courant ; transferts reçus au coût de sortie du dépôt d'origine.
- Stock négatif refusé par défaut ; autorisé seulement sur demande explicite avec motif (opération marquée et auditée). Les ajustements manuels ne peuvent jamais rendre le stock négatif.
- Transferts : brouillon → expédié (`in_transit`, sortie du dépôt d'origine, numéro `TR-AAAA-NNNNN`) → reçu (entrée au dépôt de destination ; écart justifié, quantité manquante jamais entrée). Annulation possible seulement en brouillon.
- Inventaires : quantité théorique figée à la validation sous verrou des soldes ; motif obligatoire pour tout écart ; numéro `INV-AAAA-NNNNN`.
- Accès : un utilisateur n'agit que sur les dépôts qui lui sont affectés (administrateur : tous). Coûts et valeurs visibles seulement avec `report.margin_view`.
- Types de mouvements prévus pour EPIC-05 : `receipt`, `sale`, `customer_return`, `supplier_return`.

## Mise en œuvre des ventes, de la caisse et des achats (EPIC-05, 2026-10-14)

- **Documents de vente** : une table unique `sales_documents` (+ `sales_document_lines`) portant `type` = `quote` | `order` | `invoice` | `credit_note`, au lieu de quatre paires de tables. Écart assumé : le format est identique, et la numérotation reste unique par `(tenant_id, type, number)`. Les ventes comptoir sont des factures `channel = pos`.
- **Numérotation annuelle** attribuée à la validation, dans la transaction (`Sequences::document`) : devis `DV-AAAA-NNNNN`, commande client `CC-`, facture `FA-`, avoir `AV-`, reçu de paiement `RC-`, remboursement `RB-`, commande fournisseur `CF-`, réception `BR-`, facture fournisseur `FF-`, règlement fournisseur `PF-`, retour fournisseur `RF-`, dépense `DP-`, recette diverse `RD-`.
- **Prix TTC** côté vente (prix catalogue) ; HT = TTC × 10000 / (10000 + taux) arrondi au franc, TVA = TTC − HT, calculés par ligne puis sommés. Côté achat, coût HT et TVA = HT × taux arrondie. Règles d'arrondi à valider par l'expert-comptable.
- **Lignes figées** : instantané (SKU, libellé, unité, prix, taxe) écrit à la saisie ; un devis converti garde ses prix même si le catalogue change. Contraintes `CHECK` : `line_total = round(quantity × unit_price) − discount_amount`, `total = HT + TVA`.
- **Immutabilité en base** (triggers) : un document de vente validé ne change plus (montants, numéro, client, lignes) ; seuls évoluent le suivi des règlements (`amount_paid`, `amount_credited`, `payment_status`) et la conversion/annulation d'un devis ou d'une commande. Une facture se corrige uniquement par un **avoir** lié (`source_document_id`), qui remet en stock au coût de la vente d'origine les articles rapportés. Même principe pour les commandes fournisseurs confirmées (seule la quantité reçue progresse) et les factures fournisseurs validées.
- **Append-only** : `payments`, `cash_movements`, `goods_receipts`(+lignes), `supplier_returns`(+lignes).
- **Paiements** : simple, partiel ou multi-mode (`cash`, `wave`, `orange_money`, `card`, `transfer`, `cheque`), jamais au-delà du reste dû ; clé d'idempotence unique par entreprise ; les espèces passent obligatoirement par une **session de caisse ouverte** du même dépôt (contrainte `payments_cash_session`). Remboursement seulement d'un trop-perçu (après avoir), avec motif.
- **Crédit client** : une facture non soldée exige un client avec un plafond (`credit_limit`, null = pas de crédit) ; encours + nouvelle dette ≤ plafond, ligne client verrouillée pour sérialiser les ventes simultanées.
- **Remises** : jusqu'à `diaba.sales.standard_discount_max_bp` (10 %, provisoire) avec `sale.discount_standard`, au-delà `sale.discount_exception` ; prix différent du catalogue seulement avec `catalog.price_override`. Stock négatif seulement avec `sale.sell_negative_stock` et un motif (tracé sur la facture et l'opération de stock).
- **Caisse** : `cash_registers` (une « Caisse principale » créée avec chaque dépôt), `cash_sessions` (une seule ouverte par caisse, index unique partiel), `cash_movements` signés. Une sortie ne peut dépasser les espèces présentes ; fermeture avec montant compté, écart calculé (`CHECK difference = counted − expected`) et justification obligatoire si écart.
- **Achats** : commande fournisseur (brouillon → confirmée → partiellement reçue → reçue, ou soldée) ; **réception** = entrée en stock au coût HT (coût moyen pondéré) ; **facture fournisseur** : la dette naît à sa validation (politique à confirmer par l'expert-comptable), une même référence fournisseur ne peut être validée deux fois ; règlements partiels ; **retour fournisseur** depuis une réception : sortie de stock et avoir imputé sur la facture liée.
- **PDF** (dompdf) : A4 pour devis, commande, facture et avoir ; ticket 80 mm pour le reçu. Seuls les documents numérotés s'impriment. Les mentions légales de l'entreprise (NINEA, RCCM, adresse, logo client) seront ajoutées avec le profil entreprise.

## Fiche de l'entreprise (`tenant_profiles`)

Une ligne par entreprise : `vat_regime` (`real` assujetti au régime du réel, `cgu` contribution globale unique, `exempt` non assujetti/exonéré), `maps_url` (lien de partage Google Maps uniquement, https), `logo_path`, `stamp_path` (cachet/signature), `cover_path` (devanture, facultative), `version`.

- Édition : Paramètres › Entreprise (`organization.manage` ; lecture avec `organization.view`) ; chaque changement est audité.
- Images ré-encodées en WebP sans métadonnées (`ImageStore`), rangées sous `tenants/<id>/profile/` donc incluses dans la sauvegarde ; servies par lien signé dans l'application et, pour le logo et la devanture, par la boutique publique.
- Le logo, le cachet/signature et le régime de TVA figurent sur les devis, factures et avoirs PDF. Le régime est une **mention** : il ne modifie pas les taux appliqués (réglés dans Catalogue › Réglages) ; à valider avec l'expert-comptable.
