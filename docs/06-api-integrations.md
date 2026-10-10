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
