# Guide pour l’IA de développement

## Mission

Implémenter Diaba de manière incrémentale à partir des documents de ce dossier, en privilégiant intégrité des données, sécurité multi-tenant, simplicité d’usage et testabilité.

## Règles impératives

1. Lire `README.md`, `01-decisions-produit.md`, `03-architecture-technique.md`, `04-modele-donnees.md` et `09-securite-exploitation.md` avant une première modification.
2. Ne jamais écrire une requête métier sans filtre `tenant_id` résolu côté serveur.
3. Ne jamais stocker de montant en float ni formater une monnaie avant les couches de présentation.
4. Ne jamais supprimer ou modifier directement une facture, un mouvement de stock, un paiement, une écriture postée ou une trace d’audit.
5. Toute mutation doit être transactionnelle, autorisée, auditée lorsque sensible et idempotente si elle peut être rejouée.
6. Les règles de stock et comptabilité doivent vivre dans des services métier testables, pas dans les contrôleurs ou composants Vue.
7. Produire des migrations réversibles lorsque possible, avec contraintes et index ; ne pas introduire une migration destructrice sans procédure de sauvegarde/migration validée.
8. Écrire les textes de l’interface en français clair via clés de traduction ; ne pas disperser de chaînes métier non traduisibles.
9. Concevoir et vérifier chaque écran aux formats mobile, tablette et desktop, avec états vide, chargement, erreur et hors connexion.
10. Ne pas activer un vrai paiement ni une règle comptable réglementaire sans validation des identifiants, contrats fournisseurs et conseil métier qualifié.

## Ordre de travail

1. Inspecter le dépôt, les instructions locales, l’état Git et l’architecture existante avant d’éditer.
2. Mettre en place tests, lint, analyse statique et CI avant les modules risqués.
3. Livrer Version 0 puis Version 1 selon `10-plan-realisation.md`.
4. Pour chaque fonctionnalité : migration → modèle/contrainte → politique/permission → service transactionnel → endpoint → interface → tests unitaires/intégration/E2E pertinents → audit/observabilité.
5. Démontrer la fonctionnalité sur `dev.diaba.cloud` avant toute promotion production.

## Conventions de qualité

- TypeScript strict ; PHP typé lorsque le framework le permet ; DTO/Value Objects pour montants, quantités, périodes et identifiants.
- Nommage stable et en anglais dans le code/schéma ; interface française et traduisible.
- Tests unitaires pour calculs/prix/TVA ; intégration pour transactions, droits, tenant et idempotence ; E2E pour connexion, vente tactile, scan simulé et offline.
- Préférer les erreurs de domaine explicites (`InsufficientStock`, `ClosedAccountingPeriod`) aux erreurs génériques.
- Ajouter métriques et logs structurés aux intégrations, synchronisation et opérations asynchrones, sans données secrètes.

## Vérifications avant fusion

- Tous les tests sont verts, y compris isolation tenant et idempotence.
- Migration vérifiée sur une copie/jeu de données représentatif.
- Permissions et rôles vérifiés avec un compte autorisé et non autorisé.
- Aucun secret ni donnée sensible dans diff, fixtures, logs ou captures.
- États mobile/hors ligne et erreur vérifiés si le changement touche un parcours utilisateur.
- Documents mis à jour si une décision, endpoint, schéma ou règle métier change.

## Décisions à faire confirmer avant implémentation définitive

- Prix, limites d’utilisateurs et limites de stockage de chaque formule.
- Pays précis du lancement, règles fiscales et mentions obligatoires sur factures.
- Prestataires/API de Wave, Orange Money et paiement par carte retenus.
- Référentiel comptable final et validation des états avec un expert-comptable.
- Durées légales de conservation, politique de confidentialité et conditions d’utilisation.
- Politique exacte de prix hors ligne et traitement d’une vente en conflit.
