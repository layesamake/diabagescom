# Backlog d’implémentation exécutable

Ce backlog est ordonné par dépendances. Une IA ne commence pas un élément si les prérequis ne sont pas validés. Chaque item inclut migrations, politiques, tests et interface lorsqu’applicable.

## EPIC-00 — Qualité et infrastructure

1. Créer l’application Laravel/Vue TypeScript avec conventions de formatage, lint, tests et analyse statique.
2. Ajouter Docker Compose : application, Nginx, PostgreSQL, Redis, worker, scheduler ; fichiers secrets exclus du dépôt.
3. Ajouter CI : installation verrouillée, format, lint, tests, analyse et build frontend.
4. Configurer environnements dev et production distincts, HTTPS, health checks, logs structurés, monitoring et alertes.
5. Implémenter sauvegarde chiffrée hors VPS et exercice de restauration documenté.

**Terminé lorsque :** un déploiement immuable sur dev passe CI, répond au health check et une base peut être restaurée dans un environnement isolé.

## EPIC-01 — Identité et multi-tenant

1. Modèles/migrations : utilisateurs, tenants, memberships, rôles, permissions, dépôts, audit.
2. Connexion, réinitialisation mot de passe, vérification e-mail, sessions, limitation de débit et MFA administrateur.
3. Middleware de contexte tenant et dépôt ; politiques d’autorisation ; tests de fuite croisée.
4. Écran de création d’entreprise et premier administrateur/dépôt.
5. Journal d’audit et consultation limitée.

**Terminé lorsque :** deux tenants peuvent être créés, leurs URL/API/recherches/export sont hermétiques et l’admin voit systématiquement son contexte.

## EPIC-02 — Abonnements et limites

1. Migrations plans, souscriptions, options, périodes d’essai et états lecture seule/suspendu.
2. Service d’éligibilité `FeatureAccess` utilisé par routes, menu, jobs et mutations.
3. Planifier les transitions : actif → lecture seule → suspendu ; notifications J-3, J-1, J0.
4. Console plateforme plans/tenants et actions manuelles auditables.

**Terminé lorsque :** la limite dépôt/utilisateurs et le module comptable sont appliqués côté serveur et le cycle d’essai est vérifié par tests de temps simulé.

## EPIC-03 — Catalogue et tiers

1. Catégories, unités, taxes, articles, codes-barres, paramètres par dépôt et images sécurisées.
2. Recherche paginée et lookup de code-barres avec index/contraintes uniques.
3. Clients, fournisseurs, adresses, conditions et plafond crédit.
4. Import CSV avec aperçu, validation ligne par ligne, rapport d’erreurs et idempotence.

**Terminé lorsque :** un article est recherchable/scanable dans le bon tenant et ne peut être dupliqué par code-barres.

## EPIC-04 — Ledger de stock

1. Modèles mouvements/balances, service transactionnel d’application de mouvement et verrous concurrents nécessaires.
2. Stock initial, réception, vente, retours, ajustements et règles de stock négatif.
3. Transfert expédié/reçu en deux étapes, inventaires et écarts motivés.
4. Projection de balance et rapports de mouvement ; alertes seuil.

**Terminé lorsque :** tout changement de stock est expliqué par un mouvement immuable unique, concurrent et testable.

## EPIC-05 — Ventes, caisse et achats

1. Brouillons/validation de devis, commandes, factures, lignes figées, numérotation et PDF.
2. Panier tactile, scan, paiement simple/partiel/multi-mode, crédit client, reçu.
3. Sessions caisse, encaissements, dépenses, fermeture et écarts.
4. Commandes achats, réceptions, factures fournisseurs, dettes, règlements et retours.
5. Avoirs/corrections ; aucune mutation directe d’un document validé.

**Terminé lorsque :** un parcours de vente et réception produit les documents, paiements, mouvements stock et audit attendus.

## EPIC-06 — PWA hors connexion

1. Manifest, service worker, cache app shell et stockage IndexedDB minimal.
2. File locale de commandes avec reprise après redémarrage et badge d’état.
3. Endpoint sync idempotent, changement incrémental par curseur, refus et conflit explicites.
4. Tests offline/e2e : vente, coupure pendant envoi, double appareil, révocation appareil, mise à jour PWA.

**Terminé lorsque :** une vente hors ligne ne se perd ni ne se double et obtient un statut final explicite après reconnexion.

## EPIC-07 — Comptabilité

1. Plan SYSCOHADA paramétrable, journaux, exercice, périodes, comptes de mappage et validation expert.
2. Ledger double entrée, contraintes d’équilibre, moteur d’écriture idempotent et liens sources.
3. Génération pour ventes, achats, paiements, stock, caisse, avoirs et inventaires.
4. Écritures diverses, clôtures, grand livre, balance, auxiliaires et banque.
5. Jeux de cas comptables signés/revus et tests de non-régression.

**Terminé lorsque :** chaque événement défini dans `05-comptabilite.md` a une écriture équilibrée, non modifiable et réconciliable.

## EPIC-08 — Paiements SaaS et mise à l’échelle

1. Abstraction prestataire et flux sandbox pour Wave, Orange Money, carte et virement manuel.
2. Webhooks signés, déduplication, états de facture SaaS, relances et rapprochement.
3. Option à la carte, changement de formule, console support tracée.
4. Rapports avancés, notifications, traductions et optimisation basée sur métriques.

**Terminé lorsque :** aucun webhook rejoué ne crée de double abonnement/paiement et l’état tenant dépend seulement d’un paiement vérifié.
