# ❌ RAPPORT D'ANOMALIES TECHNIQUES - DIABA GESCOM

Date de l'audit : 15 octobre 2026  
Commit audité : `85f286dd0bfdd7d3652365ad60a79845ee385ebf`  
Branche de travail : `hermes/pilotage-stock-insights`  
Périmètre : Laravel 13, Inertia/Vue 3, PostgreSQL 18, Redis, PWA/IndexedDB, Catalog, Sales, Treasury/Cash, Inventory et Reporting/Pilotage.

## Synthèse

L'isolation inter-entreprises est globalement robuste : les modèles métier utilisent le scope global tenant, le contexte est résolu avant le route-model binding et PostgreSQL impose des clés étrangères composites incluant `tenant_id`. Aucune fuite inter-tenant serveur n'a été démontrée dans Catalog, Sales ou Inventory.

L'audit a toutefois confirmé des anomalies importantes :

- le rôle système « Vendeur / caissier » peut interroger le Pilotage contrairement à l'exigence fournie ;
- ce même rôle reçoit le coût standard d'un produit dans une réponse Inertia ;
- un caissier peut utiliser ou fermer la session de caisse d'un autre caissier du même dépôt ;
- la synchronisation Offline permet un paiement sans permission `cash.receive` ;
- trois mutations d'inventaire sont vulnérables à des IDOR inter-dépôts ;
- la PWA peut restituer ou déplacer des données privées entre utilisateurs ou tenants sur un appareil partagé ;
- plusieurs mécanismes d'idempotence ne vérifient pas que le rejeu contient exactement la même opération.

Le flux nominal de vente comptoir est en revanche atomique : document, stock, paiement et mouvement de caisse sont exécutés dans une transaction PostgreSQL englobante. Une exception métier provoque le rollback des écritures déjà effectuées.

## 1. Failles Critiques (Multi-tenancy, Sécurité, Transactions SQL)

### CRITIQUE — Le rôle Vendeur peut interroger le Reporting/Pilotage

**[Identity / `app/Domain/Identity/PermissionCatalog.php:59-65` / rôle `cashier`]**

- **Risque / Bug détecté :** le rôle intégré « Vendeur / caissier » reçoit explicitement `report.view_own`. `SalesReportController::index()` accepte cette permission (`app/Http/Controllers/SalesReportController.php:34-40`) et exécute le read model Pilotage. Le lien est également exposé par la sidebar, les raccourcis et les permissions partagées Inertia.
- **Impact sur les données (XOF / Stock) :** le vendeur peut consulter ses indicateurs, ventilations, moyens de paiement, articles et créances. Cela viole directement l'exigence selon laquelle un Vendeur ne doit interroger aucun contrôleur Reporting/Pilotage.
- **Correction Laravel recommandée :** retirer `report.view_own` du rôle `cashier`, synchroniser les rôles système avec `php artisan diaba:sync-permissions`, protéger `/reports` par middleware et Gate serveur, puis adapter Dashboard, sidebar, guide et tests.
- **Test de non-régression :** un `cashier` doit recevoir `403` sur HTML, CSV, XLSX et PDF.

### CRITIQUE — Un caissier peut utiliser ou fermer la caisse d'un autre caissier

**[Cash / `app/Domain/Cash/CashService.php:88-103` / verrouillage de session]**

- **Risque / Bug détecté :** les sessions ouvertes sont sélectionnées par dépôt, sans filtrage `opened_by`. Le verrouillage vérifie le statut et le dépôt, mais pas que l'utilisateur courant est le propriétaire de la session. Le rôle `cashier` possède à la fois `cash.receive` et `cash.open_close`.
- **Impact sur les données (XOF / Stock) :** un caissier B peut imputer ses ventes espèces à la session de A ou la fermer. Exemple destructif : A ouvre avec 50 000 XOF, B encaisse 300 000 XOF dans cette session ; le système attend 350 000 XOF dans le tiroir de A et lui attribue un déficit artificiel de 300 000 XOF. Le stock et les ventes restent comptabilisés, mais l'attribution physique de l'argent est fausse.
- **Correction Laravel recommandée :** passer l'acteur à la méthode de verrouillage et imposer `opened_by === actor.id` pour les droits « propres ». Créer une permission séparée, telle que `cash.operate_all`, pour les superviseurs. Filtrer toutes les listes de sessions et protéger vente, mouvement manuel et clôture dans le service métier.

### HAUTE — Contournement de `cash.receive` via OfflineSync

**[Offline / `app/Domain/Sync/OfflineSync.php:148-187` / traitement d'une vente]**

- **Risque / Bug détecté :** le POS en ligne exige `cash.receive` lorsque des règlements sont fournis, mais le chemin Offline ne contrôle que `sale.create` et `sale.validate`. `PaymentService::receive()` ne fait pas lui-même le contrôle RBAC.
- **Impact sur les données (XOF / Stock) :** un rôle personnalisé autorisé à créer/valider une vente, mais non à encaisser, peut créer paiements et mouvements de caisse en envoyant une commande sur `/sync/commands`.
- **Correction Laravel recommandée :** centraliser l'autorisation dans `PointOfSale::checkout()` ou `PaymentService::receive()`. Exiger `cash.receive` dès qu'un tender positif est présent, quel que soit le contrôleur appelant.

### HAUTE — Idempotence non liée au contenu de l'opération

**[Sales/Cash / `PointOfSale.php:30-34,73-81`, `PaymentService.php:64-68,191-203`, `CashService.php:163-180`]**

- **Risque / Bug détecté :** une clé d'idempotence déjà utilisée renvoie l'opération précédente sans comparer un hash canonique du nouveau payload. Pour les mouvements manuels, la clé n'est pas comparée à la session, au type ou au montant.
- **Impact sur les données (XOF / Stock) :** une vente de 4 000 XOF et 2 unités rejouée avec la même clé pour 40 000 XOF et 20 unités est annoncée comme réussie alors que seules 2 unités sont sorties. Un encaissement de 10 000 XOF rejoué pour 100 000 XOF peut également recevoir une réponse de succès sur l'ancienne opération.
- **Correction Laravel recommandée :** persister un fingerprint canonique avec chaque opération. Même clé et même hash : retourner le résultat existant. Même clé et hash différent : retourner HTTP `409` et ne rien écrire.

### HAUTE — Course concurrente Offline avec `command_id` identique et payload divergent

**[Offline / `app/Domain/Sync/OfflineSync.php:109-118,161-187,245-253`]**

- **Risque / Bug détecté :** deux requêtes simultanées peuvent constater l'absence du journal. La contrainte SQL empêche la double vente, mais le second traitement peut récupérer la vente du premier sans vérifier son payload ; le journal final peut alors associer le hash d'une requête au résultat de l'autre.
- **Impact sur les données (XOF / Stock) :** le client peut considérer comme exécuté un panier différent de celui réellement facturé et décrémenté. Les rejeux ultérieurs peuvent produire des conflits inversés.
- **Correction Laravel recommandée :** réserver `command_id` et son hash avant tout effet métier, dans la même transaction, verrouiller la ligne de commande, puis comparer `payload_hash`, `type`, `device_id` et `created_by` sur toute collision.

### HAUTE — PostgreSQL n'interdit pas un mouvement dans une caisse fermée

**[Cash / `app/Domain/Cash/CashService.php:117-139` / `recordMovement()`]**

- **Risque / Bug détecté :** les chemins HTTP actuels verrouillent normalement une session ouverte, mais `recordMovement()` est public et ne revérifie pas lui-même le statut. Les contraintes SQL n'interdisent pas l'ajout d'un mouvement ou d'un paiement sur une session fermée.
- **Impact sur les données (XOF / Stock) :** une caisse clôturée et équilibrée à 200 000 XOF peut recevoir ultérieurement 75 000 XOF. La clôture reste figée à 200 000 XOF alors que l'historique atteint 275 000 XOF.
- **Correction Laravel recommandée :** rendre la méthode interne ou verrouiller systématiquement la session avec `FOR UPDATE` et vérifier `status = open`. Ajouter des triggers PostgreSQL défensifs sur `cash_movements` et les paiements rattachés à une session.

### MOYENNE — IDOR lors de l'annulation d'un transfert

**[Inventory / `TransferController.php:107-110,156-163` et `TransferService.php:160-178`]**

- **Risque / Bug détecté :** l'affichage et l'expédition/réception tiennent compte des dépôts accessibles, mais l'annulation exige seulement `inventory.transfer_create`. Le service verrouille le transfert sans vérifier l'accès au dépôt source.
- **Impact sur les données (XOF / Stock) :** un gestionnaire d'un autre dépôt du même tenant peut annuler un transfert brouillon s'il connaît son UUID, provoquant un déni opérationnel et une rupture du processus logistique.
- **Correction Laravel recommandée :** après verrouillage, appeler `StockAccess::warehouse($locked->source_warehouse_id)` dans le service. Aligner `can.cancel` côté Inertia.

### MOYENNE — IDOR lors de l'annulation d'un inventaire physique

**[Inventory / `CountController.php:156-163` et `CountService.php:164-181`]**

- **Risque / Bug détecté :** la consultation et la validation contrôlent le dépôt ; l'annulation ne le fait pas.
- **Impact sur les données (XOF / Stock) :** un gestionnaire affecté au dépôt A peut annuler l'inventaire brouillon du dépôt B avec son UUID, entraînant perte du travail de comptage et perturbation de la clôture de stock.
- **Correction Laravel recommandée :** appeler `StockAccess::warehouse($locked->warehouse_id)` après verrouillage dans `CountService::cancel()`.

### MOYENNE — IDOR lors de la suppression d'une ligne d'inventaire

**[Inventory / `CountController.php:135-139` et `CountService.php:85-91`]**

- **Risque / Bug détecté :** le service vérifie l'appartenance de la ligne au comptage, mais pas l'accès au dépôt du comptage.
- **Impact sur les données (XOF / Stock) :** suppression silencieuse d'une ligne d'un inventaire d'un autre dépôt, pouvant produire une validation incomplète et omettre un ajustement nécessaire.
- **Correction Laravel recommandée :** verrouiller le comptage, vérifier le dépôt, puis rechercher la ligne depuis `$locked->lines()` avant suppression.

### MOYENNE — Agrégats de facture non garantis contre les journaux financiers

**[Sales/Cash / migrations ventes et caisse / agrégats `amount_paid`, `amount_credited`, `payment_status`]**

- **Risque / Bug détecté :** PostgreSQL n'impose pas que les agrégats de facture correspondent à la somme signée des paiements append-only, ni qu'un paiement espèces possède exactement un mouvement de caisse cohérent.
- **Impact sur les données (XOF / Stock) :** une modification SQL ou un futur chemin applicatif peut marquer une facture de 500 000 XOF payée sans justificatif de paiement ni mouvement de caisse.
- **Correction Laravel recommandée :** faire du journal des paiements la source de vérité, recalculer/contrôler les agrégats par trigger ou procédure transactionnelle, et ajouter une unicité sur `cash_movements.payment_id` lorsqu'il est renseigné.

## 2. Problèmes de Logique Métier & Désynchronisations (Inertia/Vue/Offline)

### HAUTE — Fuite de `standard_cost` dans la fiche produit Inertia

**[Catalog / `ProductController.php:163-167,286-295` / `ProductForm.vue`]**

- **Anomalie constatée :** la route d'édition exige seulement `catalog.view`, droit possédé par le Vendeur. `formData()` sérialise toujours `standard_cost`; désactiver visuellement le champ ne retire pas la donnée de la réponse JSON Inertia.
- **Comportement en mode Offline / Cache Redis :** la fuite est directement observable dans `$page.props.product.standard_cost`. Elle peut être combinée aux volumes de vente pour estimer des marges. Le catalogue Offline principal ne transmet toutefois pas ce coût.
- **Correction :** omettre ou mettre à `null` `standard_cost` sans `report.margin_view`; construire explicitement la prop et masquer le bloc Vue. Séparer idéalement la fiche consultable du formulaire d'édition.

### MOYENNE — Politique de coût incohérente dans la recherche produit

**[Catalog / `ProductController.php:99-135` / endpoint de recherche]**

- **Anomalie constatée :** `standard_cost` est autorisé avec `inventory.adjust` plutôt qu'avec `report.margin_view`. Un gestionnaire de dépôt peut donc lire un coût sans permission de marge.
- **Comportement en mode Offline / Cache Redis :** la réponse JSON expose le coût à un rôle non autorisé par la politique documentée ; le Vendeur par défaut reste protégé sur cet endpoint.
- **Correction :** utiliser `report.margin_view` pour la lecture du coût. Si la saisie de coût initial reste nécessaire, séparer permission d'écriture et permission de lecture.

### HAUTE — Contamination IndexedDB lors d'un changement de tenant

**[PWA / `resources/js/offline/sync.ts:103-106,166-180,296-375`]**

- **Anomalie constatée :** `db`, `scope` et `deviceKey` sont des variables globales mutables. Une synchronisation lancée pour A peut terminer après que `initOffline()` a remplacé la base active par celle de B.
- **Comportement en mode Offline / Cache Redis :** la réponse de A peut être écrite dans la base IndexedDB de B ; la commande de A peut rester bloquée en `sending`. Il s'agit d'une fuite locale inter-tenant et d'un risque de perte temporaire de rejouabilité.
- **Correction :** capturer un contexte immuable au début du push, utiliser un `AbortController` par scope et vérifier une génération de contexte avant toute écriture.

### HAUTE — Pages authentifiées mises en cache sans isolation utilisateur/tenant

**[PWA / `public/sw.js:18,114-135`]**

- **Anomalie constatée :** `/dashboard`, `/sales/pos` et `/sync` sont mises en cache sous une clé fondée uniquement sur le chemin. En cas d'échec réseau, la page privée mise en cache est restituée sans revalidation de session.
- **Comportement en mode Offline / Cache Redis :** sur un appareil partagé, un utilisateur déconnecté ou désactivé peut laisser visibles contexte, permissions et données du compte précédent. Les anciennes props peuvent également rouvrir sa base IndexedDB.
- **Correction :** utiliser un shell Offline neutre sans données privées, ou partitionner et purger strictement les caches par compte et tenant. Ne pas mettre `/dashboard` en cache avec des props authentifiées.

### HAUTE — Perte de commandes nécessitant une intervention lors de la déconnexion

**[PWA / `UserMenuContent.vue:22-29,69-73`, `sync.ts:143-155,611-614`, `db.ts:113-119`]**

- **Anomalie constatée :** la déconnexion avertit uniquement pour `pending`, pas pour `attention`. L'effacement n'est pas attendu ; seule la base active est visée et `onblocked` est traité comme un succès.
- **Comportement en mode Offline / Cache Redis :** une commande `conflict` ou `requires_review` peut être supprimée sans avertissement. Un autre onglet peut empêcher la purge tout en laissant l'interface croire qu'elle a réussi. Les bases d'autres tenants du même compte peuvent rester sur l'appareil.
- **Correction :** avertir si `pending + attention > 0`, attendre la purge, traiter `onblocked` comme un échec et maintenir un registre des bases à supprimer pour le compte.

### MOYENNE — Collision locale de `command_id` écrasant le panier

**[PWA / `resources/js/offline/db.ts:26-29,67-75` et `sync.ts:520-540`]**

- **Anomalie constatée :** IndexedDB utilise `command_id` comme clé et `put()` comme upsert. Une seconde commande portant le même UUID remplace le payload initial.
- **Comportement en mode Offline / Cache Redis :** le premier panier peut être perdu avant toute synchronisation ; seule la dernière version est envoyée.
- **Correction :** utiliser `add()` pour la création, comparer un fingerprint en cas de collision et interdire la mutation du payload d'une commande existante.

### MOYENNE — Rejeu non lié à l'appareil et à l'utilisateur

**[Offline / `OfflineSync.php:109-118,227-253`]**

- **Anomalie constatée :** la recherche d'une commande existante porte uniquement sur `command_id` dans le tenant et intervient avant les contrôles de permission. `device_id` et `created_by` sont enregistrés mais non comparés au rejeu.
- **Comportement en mode Offline / Cache Redis :** un autre utilisateur du même tenant possédant un UUID divulgué peut récupérer numéro, identifiant et montant de l'opération.
- **Correction :** comparer propriétaire, appareil, type et hash ; renvoyer `command_owner_mismatch` en cas de divergence.

### MOYENNE — Catalogue Offline définitivement incomplet au-delà de 5 000 lignes

**[Sync / `SyncController.php:56-98` et `sync.ts:444-447`]**

- **Anomalie constatée :** produits et clients sont limités à 5 000 sans pagination ni `has_more`, tandis que le curseur est avancé à l'heure du début de la requête.
- **Comportement en mode Offline / Cache Redis :** le 5 001e élément n'est jamais récupéré tant que son `updated_at` ne change pas.
- **Correction :** pagination stable sur `(updated_at, id)`, boucle client avec `has_more`, puis validation du curseur global seulement après toutes les pages.

## 3. Conformité de l'Architecture

### Éléments conformes

- Les modèles Catalog, Sales et Inventory inspectés utilisent `BelongsToTenant`.
- `TenantScope` impose `tenant_id` sur les requêtes Eloquent.
- `tenant_id` est injecté à la création et rendu immuable.
- Le contexte tenant est résolu avant `SubstituteBindings`, ce qui protège le route-model binding inter-tenant.
- Le contexte échoue fermé lorsqu'aucun tenant valide n'est disponible.
- Les relations sensibles utilisent des clés étrangères composites incluant `tenant_id`.
- PostgreSQL refuse les relations structurantes entre tenants.
- Les mutations effectives de vente vérifient les dépôts accessibles dans les services.
- Les mouvements de stock sont append-only et les soldes sont verrouillés dans un ordre stable.
- La vente comptoir englobe création/validation, stock, paiements et caisse dans une transaction unique.
- Une caisse fermée rencontrée pendant le flux POS provoque une exception et le rollback du document, du stock et du paiement.
- Les exports Pilotage CSV/XLSX/PDF sont déjà refusés au rôle `cashier`; c'est l'accès HTML/JSON au contrôleur qui reste non conforme.
- Le POS et le catalogue Offline ne sérialisent pas les coûts pour le Vendeur.

### Risques de durcissement

Plusieurs requêtes `DB::table()` et jointures se reposent sur des UUID globaux et les contraintes composites, sans prédicat `tenant_id` explicite. Aucune fuite n'est démontrée avec le schéma actuel, mais les requêtes brutes devraient systématiquement inclure le tenant afin de préserver une défense en profondeur et de rendre les audits futurs moins fragiles.

`StockLedger` valide les produits via Eloquent, mais fait confiance aux identifiants de dépôt fournis par ses appelants. Les services actuels vérifient généralement ces dépôts ; une validation d'intégrité tenant dans le ledger réduirait le risque introduit par un futur appelant.

### CI, PHPUnit et PHPStan

La CI GitHub Actions du commit audité est passée avec succès le 15 octobre 2026 :

- exécution : `38696376592` ;
- commit : `85f286dd0bfdd7d3652365ad60a79845ee385ebf` ;
- PHPStan : succès ;
- niveau configuré : **7** dans `phpstan.neon` ;
- PHPUnit/Laravel sur PostgreSQL 18 : succès ;
- Pint : succès ;
- audit Composer et npm : succès ;
- lint/format JavaScript : succès ;
- vérification TypeScript : succès ;
- build frontend : succès ;
- construction des images Docker : succès ;
- contrôle d'absence de secrets dans l'image : succès.

### Limite des tests destructifs

Un `.env` local de test a été créé dans le worktree avec des secrets dédiés non réels. Le lancement Docker Compose a échoué avant création de conteneurs parce que le démon Docker n'est pas disponible depuis l'environnement d'exécution. Les scénarios destructifs ci-dessus ont donc été validés par traçage statique du code, contraintes SQL et tests existants, mais pas encore par une nouvelle suite automatisée exécutée localement.

Les tests existants ne couvrent pas :

- l'annulation inter-dépôts d'un transfert ou d'un inventaire ;
- la suppression inter-dépôts d'une ligne d'inventaire ;
- deux caissiers opérant sur la même session ;
- OfflineSync sans `cash.receive` ;
- deux commandes simultanées de même UUID avec payload divergent ;
- le changement de tenant pendant une requête de synchronisation ;
- la purge IndexedDB multi-onglets et multi-tenants ;
- la fuite de `standard_cost` dans la fiche produit.

À l'inverse, certains tests actuels consacrent l'ancien comportement et devront être modifiés : `SalesReportTest` attend que le caissier accède au Pilotage, `DashboardActionsTest` attend l'action Rapports et `StartGuideTest` attend l'étape Rapports pour le Vendeur.

## Ordre de correction recommandé

1. Retirer l'accès Pilotage au Vendeur et supprimer la fuite `standard_cost`.
2. Protéger les sessions de caisse par propriétaire et permission de supervision.
3. Corriger le contournement Offline de `cash.receive`.
4. Réserver atomiquement les commandes Offline et lier toutes les idempotences à un hash canonique.
5. Fermer les trois IDOR inter-dépôts dans les services Inventory.
6. Remplacer le cache de pages authentifiées par un shell Offline neutre et fiabiliser la purge locale.
7. Isoler les synchronisations IndexedDB par contexte immuable.
8. Ajouter pagination Offline, contraintes SQL de caisse et cohérence des journaux financiers.
9. Ajouter les tests destructifs de non-régression avant toute mise en production.
