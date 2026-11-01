# Diaba Gescom — Cahier des charges du mode simple

Oct 18, 2026 · @Etienne Samaké

## Objectif et périmètre

Ajouter à Diaba un **mode simple en images** pour les vendeurs qui lisent mal, sans toucher au mode expert actuel. Références : la [maquette (11 écrans)](https://claude.ai/artifact/HyWq1zDsKqWrqeVcgXBtsL) et la [liste des messages audio](https://claude.ai/code/artifact/98fc4510-3a57-4467-a7c0-9874abe56774).

- **Dans le périmètre** : Accueil, Vendre, Mes ventes, Caisse, Stock, Clients et crédit, Ajouter un article, Achats (réception), Résultats, Premiers pas, Messages audio.
- **Hors périmètre (mode expert inchangé)** : fournisseurs, factures fournisseurs, demandes d'achat, comptabilité, paramètres.
- **Mêmes données, mêmes règles métier.** Le mode simple est une nouvelle interface : une vente faite en mode simple apparaît dans les Ventes du mode expert.
- **Bascule par utilisateur** : un vendeur arrive en mode simple ; l'administrateur passe d'un mode à l'autre depuis le menu.

## Règles de design

Ces règles s'appliquent à chaque écran du mode simple ; un écran qui en viole une n'est pas terminé.

1. **Un écran = une tâche.** Au plus 4 gros boutons d'action par écran.
2. **Une action = une couleur + un dessin + un mot** (deux mots au plus). Les couleurs ne changent jamais de sens : Vendre vert, Caisse orange, Stock bleu, Clients violet, alerte rouge.
3. **Tailles minimales** : zone tactile 48 px, bouton principal 64 px de haut, texte 15 px, montant 32 px.
4. **Montants** écrits « 45 000 F » : espace pour les milliers, pas de décimales, jamais « XOF ».
5. **Après chaque action**, un retour visible : écran plein vert (réussi) ou rouge (problème), gros pictogramme, vibration.
6. **Chaque article a une photo, chaque client une photo ou ses initiales en couleur.**
7. **Thème clair par défaut** (lisible en plein soleil), contraste du texte ≥ 4,5:1.
8. **Haut-parleur seulement si l'enregistrement existe** (voir Système audio).
9. **Vendre et la caisse marchent sans internet** et se synchronisent ensuite, comme aujourd'hui.
10. **Mots simples.** Remplacements obligatoires :

| Mode expert | Mode simple |
| --- | --- |
| XOF | F |
| Fond de caisse | Argent du tiroir |
| Factures non soldées | À récupérer |
| Avoir | Retour |
| Réceptionner | Recevoir |
| Seuil de stock | Couleur vert / orange / rouge |
| Chiffre d'affaires | Vendu |
| Résultat, marge | Il me reste |
| Pilotage | Mes résultats |

## Couleurs, police et tailles

Valeurs exactes de la maquette, à déclarer comme variables CSS (ou dans la config Tailwind) et à ne jamais écrire en dur dans les écrans.

| Variable | Valeur | Usage | Texte dessus |
| --- | --- | --- | --- |
| --fond | #F1F4F3 | Fond des écrans | #10201C |
| --surface | #FFFFFF | Cartes, listes, panneaux | #10201C |
| --texte | #10201C | Texte principal | — |
| --texte-doux | #4A5A56 | Texte secondaire | — |
| --bordure | #D5DEDB | Bordures, cases vides | — |
| --marque | #0A3F37 | En-têtes, carte « Aujourd'hui » | #FFFFFF |
| --marque-2 | #0F5247 | Boutons dans l'en-tête | #FFFFFF |
| --vert | #34B89C | Vendre, boutons Valider | #04201A |
| --vert-fonce | #1E9C80 | Étapes faites, jauges « assez » | #FFFFFF |
| --vert-clair | #E3F6F0 | Bouton plus, fonds d'icônes | #0A3F37 |
| --orange | #F2A93B | Caisse, crédit, « bientôt fini » | #2A1A00 |
| --orange-clair | #FFF1D6 | Fonds crédit | #6B4300 |
| --bleu | #2563C9 | Stock, téléphone, Commander | #FFFFFF |
| --bleu-clair | #DCE8FB | Fonds téléphone | #173E80 |
| --violet | #6D3FB8 | Clients | #FFFFFF |
| --violet-clair | #EDE4FA | Étiquette « Chef » | #3F2270 |
| --rouge | #C8372D | Alertes, retirer, enregistrer | #FFFFFF |
| --rouge-clair | #FCE3DE | Bouton moins, fonds d'alerte | #A0261E |

- **Police** : Atkinson Hyperlegible Next (Google Fonts), graisses 400, 600, 700, 800. Diaba l'utilise déjà.
- **Tailles de texte** : 14 px étiquettes, 17 px texte, 20–22 px boutons, 26 px titre d'écran, 32–46 px montants (graisse 800).
- **Arrondis** : 16 px champs, 20–24 px cartes et boutons, pastilles entièrement rondes.
- **Espacements** : 8, 12, 16 px ; marge d'écran 16 px.
- **Icônes** : trait de 2 px, style Lucide (lucide-react convient), 26 px en liste, 40 px dans les grosses tuiles. Jamais d'emoji.

## Composants réutilisables

14 composants suffisent pour construire les 11 écrans. À coder une fois, avec les variables ci-dessus, avant le premier écran.

| Composant | Ce qu'il fait | Utilisé dans |
| --- | --- | --- |
| EnTeteEcran | Barre vert foncé : retour (48 px), icône + titre, haut-parleur | Tous les écrans sauf Accueil |
| BoutonHautParleur | Rond de 44–56 px ; reçoit un code de phrase ; ne s'affiche que si l'audio existe ; joue la phrase | Partout |
| TuileAction | Bouton de couleur 140 px de haut, rond clair + icône 40 px, un mot | Accueil |
| CarteMontant | Carte vert foncé : libellé, montant 46 px, pastilles (nombre de ventes, tendance) | Accueil, Caisse, Résultats |
| Compteur | Boutons − (rouge clair) et + (vert clair) de 48 px, nombre 26–30 px au milieu | Vendre, Caisse, Stock, Achats |
| CarteArticle | Photo, nom, prix, pastille de quantité ; un appui ajoute 1 | Vendre |
| LigneArticle | Photo 56 px, nom, jauge de couleur, quantité | Stock, Achats |
| LigneAlerte | Rond de couleur + icône, gros nombre, texte court, flèche | Accueil |
| CompteursFiltres | 3 cases de couleur avec un nombre ; un appui filtre la liste | Stock, Mes ventes |
| FeuilleBas | Voile sombre + panneau arrondi en bas, choix en gros boutons, bouton Retour | Paiement, motif de retrait, fiche client, détail vente, langue |
| EcranResultat | Plein écran vert ou rouge, pictogramme 80 px, montant, 1–2 boutons | Vente faite, caisse fermée, stock reçu |
| BarreEtapes | Barre en segments (fait vert, en cours orange, à faire gris) | Accueil, Premiers pas, Messages audio |
| Avatar | Photo ronde, sinon initiales sur fond de couleur | Clients, Achats |
| BarreNavBas | 5 entrées (Accueil, Ventes, Vendre au centre surélevé, Stock, Plus), 88 px de haut | Tous les écrans du mode simple |

## Écrans du mode simple

Les routes sont proposées sous /simple ; chaque écran réutilise les données et la logique de l'écran expert indiqué dans la dernière colonne. La maquette fait foi pour la mise en page.

| Écran | Route | Contenu | Actions | Reprend |
| --- | --- | --- | --- | --- |
| Accueil | /simple | Bande Premiers pas, carte « Aujourd'hui » (montant, nombre de ventes, tendance vs hier), 4 tuiles, alertes stock et crédit, 7 derniers jours | Ouvrir un écran | /dashboard |
| Vendre | /simple/vendre | Recherche, familles, grille d'articles avec photo, panier en bas | Ajouter / retirer, Encaisser, choisir le paiement, ticket. Caisse fermée : proposer de l'ouvrir sur place | /sales/pos |
| Paiement (feuille) | — | Total, Espèces, Téléphone (puis Wave ou Orange Money), À crédit (client obligatoire) | Espèces : montant reçu et monnaie à rendre | /sales/pos |
| Mes ventes | /simple/ventes | Jour choisi, total, 3 cases par paiement, liste (heure, vignettes, montant, icône de paiement) | Filtrer, détail, ticket, retour, annuler (chef) | /sales |
| Caisse | /simple/caisse | État fermée / ouverte, comptage par billet, « Il doit y avoir », entrées, sorties | Ouvrir, sortir ou mettre de l'argent, fermer avec résultat juste / manque / trop | /cash |
| Stock | /simple/stock | 3 compteurs (assez, bientôt fini, presque fini), liste avec jauge | + arrivage, − avec motif (abîmé, mort, perdu), filtrer | /stock |
| Clients et crédit | /simple/clients | Total dû, liste triée par dette, appel direct | Fiche : il paie tout, une partie (1 000 / 2 000 / 5 000 ou montant), nouveau client | /customers |
| Ajouter un article | /simple/articles/nouveau | Photo, nom (dit au micro), prix, « vendu par » en pictogrammes, famille, « Plus d'options » fermé | Enregistrer | /catalog/products/create |
| Achats | /simple/achats | Commandes (en route, reçue), réception article par article | Recevoir, ranger dans le stock, commander | /purchases/orders |
| Mes résultats | /simple/resultats | Semaine / mois / année : vendu, dépensé, il me reste, où est l'argent, meilleures ventes, par semaine | Changer de période | /reports |
| Premiers pas | /simple/guide | 11 étapes, barre de progression, étape en cours en grand | Commencer l'étape | /guide |
| Messages audio | /simple/voix | Langue de la voix, progression, phrases par écran | Enregistrer, réécouter, refaire, changer de langue | Nouveau |

États à prévoir sur chaque liste : vide (pictogramme + une phrase), chargement, hors ligne (icône nuage barré dans l'en-tête).

## Rôles et accès

Les rôles reprennent ceux du guide actuel (vendeur, gestionnaire de stock, comptable, administrateur) ; la répartition ci-dessous est à valider.

| Rôle | Mode par défaut | Écrans du mode simple |
| --- | --- | --- |
| Vendeur | Simple | Accueil, Vendre, Mes ventes (les siennes), Caisse, Clients (encaisser une dette), Stock (voir seulement) |
| Gestionnaire de stock | Simple | Accueil, Stock (+ et −), Achats (recevoir), Ajouter un article |
| Administrateur | Simple, bascule possible | Tous, plus Messages audio, annuler une vente, étapes 2 et 11 du guide |
| Comptable | Expert | Aucun (inchangé) |

## Système audio

Règle centrale : **un haut-parleur ne s'affiche que si tous les morceaux audio de sa phrase existent** dans la langue de l'entreprise. Pas d'enregistrement, pas d'icône, pas de voix de synthèse.

- **Langue** : une seule par entreprise, code ISO 639 (wo, ff, bm, srr, dyo, snk, mnk) ou « autre » avec un nom libre. Choisie par l'administrateur à l'étape 2 des Premiers pas.
- **Phrases** : les 123 codes de la liste audio (COM-01, VEN-05…) sont une constante du code. Chaque code a un texte français de référence et ses variables.
- **Phrases avec variable** (ex. « Total : {montant} ») : enregistrées en deux morceaux, avant et après la variable, car l'ordre des mots change selon la langue. Un des deux peut être vide.
- **Montants et nombres** : un module par langue transforme 45 750 en suite de morceaux (quarante, cinq, mille, sept, cent, cinquante, francs). La règle française sert de modèle ; celle de la langue locale est à fixer avec l'administrateur.
- **Noms d'articles et de clients** : enregistrés au micro à leur création, lus à la place de {article} et {client}.
- **Enregistrement** : API MediaRecorder du navigateur, autorisation du micro, écoute et « Refaire ». Format webm/opus (m4a sur iPhone), 5 s au plus, environ 30 Ko par morceau.
- **Lecture** : les morceaux sont joués à la suite, sans blanc audible. Un nouvel appui coupe la lecture en cours.
- **Hors ligne** : tous les fichiers de l'entreprise (environ 5 Mo) sont gardés en cache sur le téléphone après le premier chargement.
- **Étape 2 cochée** quand toutes les phrases des groupes Communs, Chiffres, Vendre et Caisse (71 dans la liste) sont enregistrées.

## Données à ajouter

Noms indicatifs, à adapter au schéma existant de Diaba ; ne rien ajouter qui existe déjà sous un autre nom.

| Table / champ | Type | Rôle |
| --- | --- | --- |
| entreprise.voice\_language | texte | Code de la langue de la voix |
| audio\_clips | table : entreprise, code, langue, morceau (seul, avant, après), fichier, durée, auteur, date | Un enregistrement ; unique par entreprise + code + langue + morceau |
| article.name\_audio | fichier | Nom de l'article dit au micro |
| client.name\_audio, client.photo | fichier | Nom dit au micro, photo |
| utilisateur.ui\_mode | simple / expert | Mode affiché à la connexion |
| session\_caisse.comptage | JSON : billet → nombre | Détail du comptage à l'ouverture et à la fermeture |
| mouvement\_stock.motif | valeurs ajoutées : abimé, mort, perdu | Motif des retraits en mode simple |
| guide.etapes | + étape « voix » en position 2 | Premiers pas à 11 étapes |

## Ordre de développement

Chaque lot est mis en ligne d'abord sur un site de test, validé, puis en production. Une journée de vente complète est utilisable dès la fin du lot 2.

1. **Fondations** : variables de couleur, police, 14 composants, bascule simple / expert, barre du bas.
2. **Journée de vente** : Vendre (avec paiement et ouverture de caisse sur place), Caisse, écrans Résultat.
3. **Accueil et Mes ventes.**
4. **Audio** : langue de la voix, écran Messages audio, lecture des phrases, module des nombres (français puis langue choisie), étape 2 du guide.
5. **Clients, Stock, Ajouter un article** (avec nom au micro et photo).
6. **Achats, Mes résultats, Premiers pas à 11 étapes.**
7. **Test terrain** avec 3 ou 4 commerçants de Thiès, puis corrections.

## Critères d'acceptation

- [ ] Après une démonstration, un vendeur qui ne lit pas fait une vente en espèces seul, en moins d'une minute.
- [ ] Aucun texte sous 15 px, aucune zone tactile sous 48 px (contrôle automatique).
- [ ] Contraste du texte ≥ 4,5:1 sur tous les écrans, en plein soleil.
- [ ] Aucun haut-parleur visible pour une phrase non enregistrée.
- [ ] Une vente faite en mode simple apparaît dans les Ventes du mode expert, avec les mêmes montants.
- [ ] Vendre et la caisse marchent sans internet et se synchronisent au retour du réseau.
- [ ] Tous les montants s'affichent « 45 000 F ».
- [ ] Tous les écrans tiennent sur 360 px de large sans défilement horizontal.
- [ ] Le mode expert fonctionne exactement comme avant.

## Questions ouvertes

- [ ] Valider la répartition des rôles (vendeur, gestionnaire de stock).
- [ ] Fixer avec l'administrateur la règle des nombres dans la langue choisie, et francs ou dërëm.
- [ ] Recherche au micro : la reconnaissance vocale des navigateurs ne comprend pas bien le wolof ni le pulaar. La garder en français seulement, ou la retirer au profit des photos ?
- [ ] Logos Wave et Orange Money : vérifier les conditions d'utilisation des logos officiels.
- [ ] Mode simple sur téléphone seulement, ou aussi sur ordinateur et tablette ?
- [ ] Pile technique et hébergement actuels de diaba.cloud, et existence d'un site de test.
