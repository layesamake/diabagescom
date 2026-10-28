# Diaba Gescom — dossier de développement

À lire dans cet ordre :
1. `03-brief-orchestrateur.md` — quoi faire, dans quel ordre, avec quelles règles.
2. `01-cahier-des-charges.md` — la référence complète du mode simple.
3. `images/` — à quoi chaque écran doit ressembler (les images font foi).
4. `02-messages-audio.md` — le système audio et la liste des phrases.
5. `04-landing-page.md` — la nouvelle landing page.

## Contenu
| Dossier / fichier | Contenu |
| --- | --- |
| images/app-telephone | 24 écrans et états du mode simple sur téléphone (390 px, exportés en x2) |
| images/app-ordinateur | 8 écrans ordinateur (1440 px) : D1 Accueil, D2 Vendre, D3 Stock, D4 Caisse, D5 Clients, D6 Mes ventes, D7 Achats |
| images/landing | Landing page ordinateur et téléphone |
| maquettes-html | Les mêmes écrans en HTML, à ouvrir dans un navigateur (garder support.js et les polices dans le même dossier) |
| design | Variables de couleurs, police et tailles (CSS et JSON) |

## Correspondance images ↔ écrans
| Image | Écran (route proposée) | État |
| --- | --- | --- |
| 01-accueil | Accueil (/simple) | Vue administrateur, étape voix en cours |
| 02a → 02d | Vendre (/simple/vendre) | Panier vide, rempli, choix du paiement, vente faite |
| 03-premiers-pas | Premiers pas (/simple/guide) | 11 étapes, étape 2 en cours |
| 04a, 04b | Mes ventes (/simple/ventes) | Liste, détail d'une vente à crédit |
| 05a → 05c | Messages audio (/simple/voix) | Liste, enregistrement en cours, choix de la langue |
| 06a → 06d | Caisse (/simple/caisse) | Fermée, comptage, ouverte, fermeture avec manque |
| 07a, 07b | Stock (/simple/stock) | Liste, motif de retrait |
| 08a, 08b | Clients (/simple/clients) | Liste, fiche client |
| 09-ajouter-article | Ajouter un article | Formulaire |
| 10a → 10c | Achats (/simple/achats) | Commandes, réception, stock mis à jour |
| 11-mes-resultats | Mes résultats (/simple/resultats) | Mois en cours |
| D1 → D7 | Versions ordinateur | Menu fixe, modèle liste + fiche |

## Rappels
- Le haut-parleur n'apparaît que si la phrase est enregistrée. Sur les images, la voix est supposée enregistrée.
- Les cases « photo » seront les vraies photos des articles.
- Les données affichées (montants, noms) sont des exemples.
- Ajouter un article, Mes résultats, Premiers pas et Messages audio n'ont pas de version ordinateur dédiée : écran téléphone centré (720 px max) à côté du menu fixe.
