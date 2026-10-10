# Plan de réalisation par versions

## Règles de livraison

- Chaque version est démontrable sur `dev.diaba.cloud`, testée puis promue sur `diaba.cloud`.
- Aucune fonctionnalité comptable ne passe en production sans jeux de tests d’écritures et revue métier qualifiée.
- Chaque incrément inclut tests automatisés, permissions, audit, états vides/erreurs et responsive mobile.

## Version 0 — socle

**Objectif :** préparer une plateforme fiable et multi-entreprise.

- Initialisation Laravel, Vue/TypeScript, design system, CI et Docker.
- Environnements dev/production, HTTPS, logs, monitoring, sauvegardes et restauration testée.
- Authentification, MFA admin, rôles/permissions, audit.
- Tenants, abonnements, formules, essai, lecture seule, limites de dépôts/utilisateurs.
- Entreprise, dépôt, paramètres de base, taxes, unités, séquences.
- Assistant de démarrage, contexte entreprise/dépôt permanent.

**Sortie :** une entreprise de test peut être créée et correctement isolée d’une seconde entreprise.

## Version 1 — commerce mono-dépôt

**Objectif :** permettre les opérations quotidiennes de vente et achat.

- Articles, catégories, unités, prix, taxes et codes-barres.
- Clients, fournisseurs, import CSV contrôlé.
- Vente tactile, devis, facture, reçu PDF, paiements et crédit client.
- Achats, réceptions, stock initial, mouvements et seuils d’alerte.
- Caisse, encaissements, dépenses, fermeture de caisse.
- Rapports commerciaux essentiels.

**Sortie :** un commerce mono-dépôt réalise ses ventes, réceptions et suivi de caisse sans tableur.

## Version 2 — multi-dépôts et PWA

**Objectif :** fonctionner sur plusieurs sites, y compris avec réseau instable.

- Stock par dépôt, transferts en deux étapes, inventaires et ajustements contrôlés.
- Affectation utilisateurs/dépôts et filtres de visibilité.
- PWA installable, catalogue local, ventes/encaissements hors ligne, synchronisation et résolution de conflits.
- Scan caméra et validation lecteurs matériels.
- Alertes de stock, synchronisation et caisse.

**Sortie :** deux dépôts peuvent travailler sans mélanger leurs stocks et les ventes hors ligne se synchronisent sans doublon.

## Version 3 — comptabilité

**Objectif :** offrir une comptabilité complète et traçable aux formules Compta.

- Plan SYSCOHADA modèle, journaux, exercices, périodes et paramètres de mappage.
- Écritures automatiques de ventes, achats, paiements, stock et caisse.
- Écritures diverses, pièces jointes, grand livre, balance, auxiliaires, banque et rapprochement.
- Clôtures, corrections par régularisation et rapports comptables.
- Revue par expert-comptable et tests de scénarios réels.

**Sortie :** les opérations commerciales validées sont comptabilisées, équilibrées et exportables avec traçabilité.

## Version 4 — industrialisation SaaS

**Objectif :** automatiser l’exploitation commerciale de Diaba.

- Paiements SaaS Wave, Orange Money, carte et virement, webhooks signés et rapprochement.
- Gestion d’options, changements de formule, relances et console super-admin.
- Rapports avancés, exports, notifications configurables et support tracé.
- Préparation traductions françaises/wolof.

**Sortie :** les clients peuvent s’abonner, payer, évoluer de formule et être administrés à grande échelle.

## Ordre technique recommandé

Ne pas construire la comptabilité, l’offline ou les paiements réels avant d’avoir des documents commerciaux validés, une identité multi-tenant solide et un ledger de stock fiable. Construire les interfaces à partir de composants et contrats stables, plutôt qu’en dupliquant la logique par écran.
