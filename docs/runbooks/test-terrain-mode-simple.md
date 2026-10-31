# Protocole de test terrain — mode simple

## But

Valider auprès de 3 ou 4 commerçants que le mode simple permet de réaliser une journée de vente sans assistance constante, avant toute activation en production.

Ce test mesure la compréhension, la rapidité et les erreurs observées. Les tests automatisés confirment les contrats techniques ; ils ne remplacent pas l'observation d'un vendeur réel.

## Périmètre et sécurité

- Utiliser uniquement `https://dev.diaba.cloud`, jamais `https://diaba.cloud`.
- Utiliser une entreprise de test et des comptes de test. Ne pas saisir de vrais numéros clients, prix sensibles, mots de passe, documents ou paiements.
- Ne pas réaliser de transaction réelle Wave, Orange Money ou bancaire.
- Ne pas enregistrer de voix, photos ou vidéos d'un participant sans son accord explicite.
- Le mode simple doit être activé sur dev (`DIABA_SIMPLE_MODE=true`) avant la séance.

## Préparation par l'animateur

Préparer une entreprise de test avec :

- un administrateur et un vendeur ;
- une caisse disponible ;
- au moins six articles représentatifs avec photo, prix et stock positif ;
- un client de test avec crédit autorisé ;
- un fournisseur et une commande fournisseur prête à recevoir ;
- des données de ventes sur plusieurs jours, pour rendre Mes résultats lisible ;
- une fiche d'observation par participant.

Ne pas utiliser `php artisan diaba:demo` sur dev : cette commande est volontairement limitée à l'environnement local.

Avant chaque séance, vérifier :

- `/simple` répond sans page introuvable ;
- le vendeur arrive sur l'Accueil simple ;
- l'administrateur peut basculer entre mode expert et mode simple ;
- le téléphone est chargé, connecté au réseau de test et sa largeur est proche de 360 px ;
- l'animateur sait restaurer la séance avec des données de test si nécessaire.

## Déroulé d'une séance

Durée cible : 45 à 60 minutes par participant. L'animateur ne lit pas les boutons à la place du participant ; il note les hésitations et n'aide qu'après une tentative raisonnable.

### 1. Prise en main — 5 minutes

Demander au participant : « Montre-moi où tu commencerais ta journée. »

Observer :

- comprend-il les tuiles sans lire les petits textes ?
- identifie-t-il Vendre, Caisse, Stock et Clients ?
- comprend-il le montant et le nombre de ventes du jour ?

### 2. Ouvrir la caisse et vendre — 15 minutes

Demander : « Ouvre la caisse avec 20 000 F, puis vends deux articles et encaisse en espèces. »

Mesurer le temps entre le premier appui sur Vendre et la confirmation de la vente, sans aide. La cible du cahier des charges est une vente simple en moins d'une minute après démonstration.

Observer :

- l'ouverture de caisse est comprise ;
- l'article est trouvé par image ou recherche ;
- les boutons plus et moins sont reconnus ;
- le total et le montant reçu sont compris ;
- une double pression ne crée pas deux ventes ;
- le participant sait revenir à l'Accueil et retrouver la vente dans Mes ventes.

### 3. Client et crédit — 10 minutes

Demander : « Trouve ce client, fais une vente à crédit partiel, puis enregistre un paiement. »

Observer :

- le solde client est compris ;
- le participant distingue montant dû et montant payé ;
- les confirmations et erreurs sont compréhensibles.

### 4. Stock et réception — 10 minutes

Demander : « Vérifie le stock de cet article, corrige une quantité, puis reçois cette commande fournisseur. »

Observer :

- les couleurs et quantités de stock sont comprises ;
- les ajustements ne sont pas faits par erreur ;
- les étapes de réception sont identifiées ;
- l'impact sur le stock est visible et compris.

### 5. Résultats et fermeture — 10 minutes

Demander : « Montre-moi les ventes du jour, puis ferme la caisse en comptant les billets. »

Observer :

- les indicateurs du jour et la comparaison de périodes sont compris ;
- le comptage est utilisable avec les billets ;
- l'écart éventuel est expliqué sans ambiguïté ;
- le participant sait quoi faire après la fermeture.

### 6. Hors connexion et droits — 10 minutes

Si le navigateur et le contexte de test le permettent, couper le réseau après le chargement du catalogue. Demander au participant de préparer une vente, reconnecter, puis vérifier qu'elle apparaît une seule fois.

Vérifier également avec le compte vendeur : aucune tuile ou donnée réservée à l'administrateur ne doit apparaître.

## Questions à poser après chaque scénario

- Qu'est-ce que tu pensais qu'il allait se passer ?
- Qu'est-ce qui t'a fait hésiter ?
- Quel bouton ou dessin changerais-tu ?
- Est-ce qu'un mot, un montant ou une couleur t'a posé problème ?
- Pourrais-tu refaire cette action seul demain ?

Noter les mots exacts du participant. Ne pas reformuler comme une solution technique pendant l'entretien.

## Fiche d'observation

Pour chaque anomalie, consigner :

| Champ | Valeur à noter |
| --- | --- |
| Participant | P1 à P4, sans donnée personnelle |
| Écran | Accueil, Vendre, Caisse, Stock, Clients, Achats, Résultats ou autre |
| Scénario | Numéro et étape du protocole |
| Attendu | Ce que le participant devait réussir |
| Observé | Action, message ou blocage exact |
| Fréquence | Une fois, plusieurs fois, tous les participants |
| Gravité | Bloquant, majeur, mineur ou idée |
| Preuve | Capture anonymisée si utile |

Classer les retours ainsi :

- Bloquant : empêche de vendre, encaisser, synchroniser ou fermer correctement ; correction avant production.
- Majeur : provoque une erreur fréquente, une incompréhension persistante ou une perte de confiance ; correction avant production sauf décision explicite contraire.
- Mineur : gêne sans empêcher le parcours ; planifier après les bloquants.
- Idée : amélioration sans défaut observé ; conserver pour le backlog.

## Critères de sortie

Le test terrain est prêt à être clôturé quand :

- au moins 3 participants ont exécuté le scénario de vente ;
- chaque participant réalise une vente en espèces seul après démonstration ;
- aucune anomalie bloquante non corrigée ne subsiste ;
- les retours majeurs ont une décision explicite : corriger, reporter ou abandonner ;
- les droits vendeur et la synchronisation hors connexion ont été vérifiés ;
- une recette finale sur dev confirme les corrections avant toute décision de production.

## Restitution

Créer une issue GitHub par anomalie bloquante ou majeure, avec l'écran, le scénario, le comportement attendu, le comportement observé et une preuve anonymisée. Grouper les idées mineures dans une seule issue de backlog.

Après la séance, transmettre un résumé court : participants testés, scénarios réussis, temps de vente observé, anomalies par gravité et décision demandée.
