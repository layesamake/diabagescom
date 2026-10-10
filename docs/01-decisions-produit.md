# Décisions produit validées

## Positionnement

- Produit : SaaS de gestion commerciale et comptable.
- Cibles : TPE, PME et PMI de commerce général.
- Marché initial : Sénégal ; conception compatible avec les autres pays de l’espace OHADA.
- Langue : français au lancement ; architecture de traduction prête pour le wolof ultérieurement.
- Devise : XOF ; stocker les montants en nombres entiers, jamais en flottants.
- TVA standard : 18 %, configurable par entreprise.

## Formules d’abonnement

| Code | Formule | Dépôts inclus | Comptabilité |
|---|---|---:|---|
| `essential` | Essentiel | 1 | Non |
| `essential_accounting` | Essentiel Compta | 1 | Oui |
| `business` | Business | Plusieurs | Non |
| `business_accounting` | Business Compta | Plusieurs | Oui |

- Le nombre d’utilisateurs inclus et les prix sont configurables par le super-administrateur.
- Les clients peuvent acheter des utilisateurs, dépôts et stockage supplémentaires.
- Essai gratuit : 10 jours calendaires.
- Fin d’essai : passage en lecture seule pendant 7 jours, puis suspension si aucun paiement n’est confirmé.
- Les données ne sont pas supprimées lors d’un déclassement ; les fonctions dépassant la formule deviennent indisponibles.
- Paiements prévus : Wave, Orange Money, carte bancaire et virement. Les virements sont validés manuellement.

## Hébergement et déploiement

- Code : dépôt GitHub `layesamake/diabagescom`.
- Développement : `dev.diaba.cloud` sur VPS Hostinger.
- Production : `diaba.cloud` sur VPS Hostinger.
- Données applicatives et base PostgreSQL : VPS Hostinger.
- Déploiement : GitHub Actions vers le VPS, avec migrations contrôlées.
- Sauvegardes : chiffrées, automatisées, externes au VPS et restaurées régulièrement sur un environnement de test.

## Expérience attendue

- Utilisable par des personnes non informaticiennes.
- Responsive ordinateur, tablette et smartphone ; tactile en priorité pour les tâches quotidiennes.
- L’utilisateur sait toujours quelle entreprise, quel dépôt et quelle fonctionnalité sont actifs.
- Scan de codes-barres par caméra ou lecteur USB/Bluetooth.
- PWA installable avec ventes, encaissements et brouillons disponibles hors connexion.
- Application rapide, avec états de synchronisation visibles.

## Identité visuelle validée

- Nom affiché : **DIABA GESCOM**.
- Logo officiel : monogramme bleu nuit en forme de `D`, contenant un livre comptable ouvert, des colonnes de grand livre, une coche turquoise et un accent or ; mot-symbole `DIABA GESCOM` plus discret à droite.
- Le symbole comptable exprime les écritures, la tenue du grand livre et la validation ; il est prioritaire visuellement sur le texte.
- Voir `15-identite-visuelle.md` pour les déclinaisons, couleurs et règles d’intégration.

## Mise en œuvre des abonnements (EPIC-02, 2026-10-11)

- Essai de 10 jours démarré à la création de l'entreprise sur la formule choisie, puis 7 jours de lecture seule, puis suspension. Les échéances sont calculées depuis la date de fin précédente : un retard du planificateur ne prolonge jamais l'accès.
- Lecture seule : consultation et changement d'entreprise/dépôt possibles, toute modification refusée côté serveur. Suspension : seule la page Abonnement reste accessible. Aucune donnée n'est supprimée.
- Rappels e-mail aux administrateurs à J-3, J-1 et J0 (une seule fois chacun).
- Limites appliquées côté serveur (formule + options `extra_user`, `extra_warehouse`) ; un déclassement ne supprime rien mais bloque les créations au-delà de la limite.
- Console plateforme (super-administrateur) : changement de formule, prolongation d'essai, activation manuelle (virement validé), suspension ; motif obligatoire et audit dans le journal de l'entreprise.

### Valeurs provisoires à valider par le propriétaire

| Formule | Dépôts | Utilisateurs inclus | Prix |
|---|---:|---:|---|
| Essentiel | 1 | 2 | à définir |
| Essentiel Compta | 1 | 3 | à définir |
| Business | 5 | 5 | à définir |
| Business Compta | 5 | 8 | à définir |

Ces valeurs se modifient dans la console plateforme (Formules) sans déploiement. Tant qu'un prix est vide, il est affiché « Tarif communiqué prochainement ».
