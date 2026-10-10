# UX/UI — règles de conception

## Objectif

Diaba doit être joli, rassurant et utilisable sans compétence informatique. L’interface privilégie la clarté, l’espace, une hiérarchie visuelle constante et une action principale évidente. Le design doit être testé sur ordinateur, tablette et smartphone dès les premiers écrans.

Le logo officiel, défini dans `15-identite-visuelle.md`, est utilisé dans l’écran de connexion, le menu principal, les documents PDF et les icônes PWA. Il ne remplace jamais le texte alternatif ni l’intitulé textuel de l’application.

## Repères permanents

Chaque page affiche :

```text
[Logo Diaba]  Entreprise : Ma Boutique  |  Dépôt : Dakar Centre
Accueil > Ventes > Nouvelle vente                         [En ligne]
```

- Rubrique active mise en évidence.
- Entreprise et dépôt actifs toujours lisibles et modifiables seulement aux personnes autorisées.
- Titre unique et action principale explicite.
- Fil d’Ariane sur les détails ; retour cohérent vers la liste précédente.
- État réseau : `En ligne`, `Hors connexion`, `Synchronisation`, `Attention requise`.

## Navigation

Desktop/tablette : navigation latérale avec Accueil, Ventes, Achats, Stock, Clients, Fournisseurs, Caisse, Comptabilité, Rapports, Paramètres. Le menu ne montre que les fonctions autorisées.

Smartphone : barre basse avec `Accueil`, `Vendre`, `Scanner`, `Stock`, `Plus`. Les libellés restent visibles : ne pas s’appuyer uniquement sur les icônes.

## Design system minimal

- Couleur primaire accessible pour l’action principale, couleurs sémantiques distinctes pour succès, attention, erreur et information.
- Contraste conforme WCAG AA au minimum ; ne jamais transmettre une information uniquement par couleur.
- Police lisible, taille de texte de base au moins 16 px sur mobile.
- Cibles tactiles d’au moins 44 × 44 px, espacées ; boutons principaux en pleine largeur sur smartphone quand nécessaire.
- Grille à 8 px, composants réutilisables : boutons, champ texte, montant, statut, tableau, carte, dialogue, toast, scanner, sélecteur de dépôt.
- États obligatoires : chargement, vide, erreur récupérable, succès, désactivé, hors connexion.

## Règles de saisie

- Préremplir les valeurs connues ; afficher les détails avancés sur demande.
- Utiliser clavier numérique pour quantités, prix et montants.
- Privilégier les boutons, listes, sélecteurs et calendriers à la saisie libre.
- Messages de validation proches du champ, en français simple et indiquant la correction attendue.
- Sauvegarder les brouillons automatiquement et annoncer l’état de sauvegarde.
- Toujours présenter un récapitulatif avant une action irréversible ; éviter les confirmations inutiles.

## Écran de vente tactile

```text
Dakar Centre · Ventes · Nouvelle vente
[ Scanner ]  [ Rechercher un article ]

Panier
Riz 25 kg                   [ − ]  2  [ + ]     25 000 XOF
Huile 5 L                   [ − ]  1  [ + ]      6 500 XOF

Total                                           31 500 XOF
[Espèces] [Wave] [Orange Money] [Carte] [À crédit]
                                      [ Encaisser 31 500 XOF ]
```

Le flux cible est : scanner/rechercher → ajuster → client si nécessaire → paiement → validation → reçu. Le bouton de validation reste visible, sans masquer le total.

## Écrans prioritaires MVP

1. Connexion, récupération de mot de passe et MFA administrateur.
2. Assistant de création d’entreprise et première configuration.
3. Accueil adapté au rôle avec raccourcis et alertes.
4. Caisse/vente tactile et scan caméra.
5. Catalogue, fiche article et stock par dépôt.
6. Clients, fournisseurs, achats et réceptions.
7. Transferts, inventaires et mouvements de stock.
8. Caisse, encaissements, dépenses et crédits.
9. Comptabilité selon formule.
10. Utilisateurs, rôles, dépôts, abonnement et paramétrage.

## Accessibilité et qualité

- Navigation complète au clavier sur desktop ; focus visible ; libellés associés aux champs.
- Lecteur d’écran : éléments et erreurs correctement annoncés.
- Ne pas bloquer le zoom mobile ; ne pas faire dépendre une tâche du glisser-déposer.
- Les données importantes restent compréhensibles sans graphique ; graphiques avec tableau/valeur alternative.
- Tester les parcours avec réseau lent, écran petit et lecteur code-barres physique.
