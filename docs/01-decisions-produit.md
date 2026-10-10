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
