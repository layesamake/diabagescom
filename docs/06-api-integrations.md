# API et intégrations

## Principes d’API

- API versionnée sous `/api/v1` pour l’application mobile/PWA et les futures intégrations ; l’interface Inertia peut utiliser des routes web dédiées.
- HTTPS uniquement ; JSON UTF-8 ; dates ISO 8601 en UTC ; montants monétaires en entiers XOF.
- Authentification par session sécurisée pour le navigateur ; tokens à durée courte et rotation pour une API externe ou appareil enregistré.
- Chaque requête résout le tenant via le compte authentifié, jamais via un `tenant_id` librement fourni par le client.
- Les mutations requièrent une clé `Idempotency-Key` ; le serveur mémorise la réponse pour une même commande.
- Pagination par curseur pour listes volumineuses ; filtrage et tri explicitement documentés.
- Réponses d’erreur : code stable, message lisible, `request_id` et détails de champ non sensibles.

## Ressources minimales

| Domaine | Routes indicatives |
|---|---|
| Session | `POST /auth/login`, `POST /auth/logout`, `POST /auth/forgot-password`, `POST /auth/mfa/verify` |
| Contexte | `GET /me`, `GET /context`, `POST /context/warehouse` |
| Catalogue | `GET/POST /products`, `GET/PATCH /products/{id}`, `GET /products/barcode/{code}` |
| Tiers | `GET/POST /customers`, `GET/POST /suppliers` |
| Ventes | `GET/POST /sales/invoices`, `POST /sales/invoices/{id}/validate`, `POST /sales/invoices/{id}/credit-notes` |
| Paiements | `POST /payments`, `POST /payments/{id}/allocate`, `GET /cash-sessions/current` |
| Achats | `GET/POST /purchase-orders`, `POST /goods-receipts`, `POST /goods-receipts/{id}/validate` |
| Stock | `GET /inventory/balances`, `GET /inventory/movements`, `POST /stock-transfers`, `POST /inventory-counts` |
| Comptabilité | `GET /accounting/journals`, `GET /accounting/entries`, `POST /accounting/manual-entries`, `POST /accounting/periods/{id}/close` |
| Synchronisation | `POST /sync/commands`, `GET /sync/changes?cursor=…`, `POST /sync/conflicts/{id}/resolve` |

## Exemple de commande idempotente

```http
POST /api/v1/sales/invoices
Idempotency-Key: 4a0f…
Content-Type: application/json

{
  "warehouse_id": "uuid",
  "customer_id": "uuid ou null",
  "lines": [
    { "product_id": "uuid", "quantity": 2, "unit_price": 12500, "discount_amount": 0 }
  ],
  "payments": [
    { "method": "cash", "amount": 25000 }
  ],
  "offline_command_id": "uuid optionnel"
}
```

Le serveur valide permissions, statut de souscription, dépôt, prix/remise, taxe, stock et caisse ouverte. Il renvoie le document avec `status: draft` ou `validated` selon la commande demandée ; toute validation a une référence stable.

## Scan de codes-barres

- Le client local lit la caméra ou le lecteur matériel, normalise le code et appelle `GET /products/barcode/{code}`.
- En hors ligne, rechercher en IndexedDB dans le catalogue du dépôt synchronisé.
- En cas d’ambiguïté, afficher une sélection ; ne jamais ajouter arbitrairement le premier résultat.
- Tout scan inconnu propose « Créer un article » uniquement aux utilisateurs autorisés.

## Paiements SaaS

Créer une couche `BillingProvider` indépendante du fournisseur. Elle expose au minimum : création d’intention, statut, annulation/remboursement si pris en charge, vérification de signature webhook et rapprochement de transaction.

- Wave, Orange Money et carte : utiliser les SDK/API officiels ou un prestataire agréé, jamais automatiser un paiement via interface web utilisateur.
- Virement : créer une référence unique, mettre le paiement `pending_manual_review`, puis exiger une validation par le super-administrateur et lier le justificatif.
- Tous les webhooks sont vérifiés cryptographiquement, stockés de façon dédupliquée et traités de manière idempotente.
- L’état d’abonnement ne change qu’après confirmation vérifiée du fournisseur ou validation manuelle auditée.

## Webhooks sortants futurs

Prévoir `invoice.validated`, `payment.received`, `stock.low`, `subscription.changed`. Les livraisons sont signées, rejouables, journalisées et désactivables. Ne livrer aucun secret ni donnée comptable non nécessaire.

## Documentation générée

Produire une spécification OpenAPI à partir des routes/DTO validés. Chaque endpoint de mutation doit documenter permissions, idempotence, effets sur stock/comptabilité, codes erreurs et exemples.

## Mise en œuvre de la facturation SaaS (EPIC-08, 2026-10-17)

> **Aucun paiement réel n'est activé.** Seuls le bac à sable (simulation signée) et le virement validé manuellement sont disponibles. Wave, Orange Money et la carte nécessitent un contrat, des clés officielles (variables d'environnement uniquement, jamais dans le dépôt) et la **validation explicite du propriétaire** avant activation (`config/billing.php`).

- **Factures d'abonnement** (`saas_invoices`, numéro global `DG-AAAA-NNNNNN`) : formule, cycle mensuel ou annuel, options à la carte (`extra_user`, `extra_warehouse`) au tarif fixé dans la console (formules) et dans `config/diaba.php` (options). Pas de tarif = pas de facture (« Tarif communiqué prochainement »). Une seule facture ouverte par entreprise (index unique partiel).
- **Abstraction `BillingProvider`** : `checkout()` (redirection vers le prestataire) et `parseWebhook()` (vérification cryptographique). Le prestataire de démonstration `sandbox` reproduit le contrat d'un vrai prestataire : page de paiement, webhook `POST /webhooks/billing/{provider}` signé HMAC-SHA256 horodaté (`X-Diaba-Signature: t=…,v1=…`, tolérance 5 min), sans session ni CSRF.
- **L'état de l'abonnement ne dépend que d'un paiement vérifié** : webhook signé dont le montant correspond, ou virement validé par le super-administrateur avec la référence du justificatif. Le retour du navigateur ne change rien. Le paiement prolonge la période depuis la fin de la période en cours (un renouvellement anticipé ne fait perdre aucun jour), applique la formule et les options de la facture, et lève la lecture seule.
- **Idempotence et double paiement** : événements dédupliqués par `(prestataire, identifiant)` ; au plus un paiement réussi par facture (index unique partiel) ; un second paiement d'une facture réglée passe « à revoir » (remboursement) sans prolonger ; montant divergent → « à revoir ». Signatures invalides tracées (`payment_provider_events`, charge utile expurgée).
- **Virement** : référence unique `VIR-<facture>-XXXX` à porter sur le virement ; validation dans Console › Facturation SaaS (`platform.billing.manage`).
- **Relances et renouvellements** (`diaba:billing:remind`, chaque jour à 8 h, idempotent) : facture de renouvellement émise 7 jours avant la fin de période ; rappels par e-mail aux administrateurs à J-3, à l'échéance et à J+3, une seule fois par étape.
- **Rapprochement** : la console liste les virements à valider, les paiements à revoir et les webhooks rejetés, ignorés (référence inconnue) ou en erreur.
- **Accès support** (`platform.support_access`) : ponctuel (15 min à 4 h), motivé, révocable, en lecture seule (abonnement, membres, dépôts, dernières actions, synchronisations à revoir) ; ouverture, consultation et fermeture visibles dans le journal d'audit de l'entreprise.
- **Rapports** (`/reports`) : chiffre d'affaires TTC net des avoirs, nombre de ventes, panier moyen, par jour, dépôt, vendeur et moyen de paiement, meilleurs articles ; marge, coûts et valeur du stock avec `report.margin_view` ; créances et dettes avec `report.view_all` ; `report.view_own` limite aux ventes de l'utilisateur ; export CSV journalisé (`report.export`).
- **Alertes d'accueil** : facture d'abonnement à régler, ventes hors connexion à revoir (en plus du stock bas, des factures échues, des caisses à fermer et des transferts).
