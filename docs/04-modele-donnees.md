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
