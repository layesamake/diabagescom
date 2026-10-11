# PWA et fonctionnement hors connexion

## Périmètre MVP hors ligne

Disponibles hors connexion pour un appareil déjà authentifié et synchronisé :

- catalogue autorisé et données essentielles d’articles ;
- recherche et scan de code-barres ;
- création de vente ;
- encaissement lié à une vente ;
- brouillons de devis/factures ;
- consultation du stock précédemment synchronisé, clairement signalé comme potentiellement périmé.

Restent en ligne au MVP : clôture de caisse, comptabilisation finale, clôture de période, rapport officiel, gestion d’utilisateurs, changement de formule et validation finale d’un transfert/inventaire.

## Installation et cache

- PWA installable avec manifest, icônes et écran de lancement.
- Service worker versionné ; ne pas mettre en cache des réponses privées sans chiffrement/contrôle d’accès approprié.
- App shell et ressources statiques précachés après installation.
- IndexedDB stocke le contexte de l’appareil, catalogue minimal, données nécessaires au dépôt autorisé, commandes en attente et curseur de synchronisation.
- À la déconnexion, l’application montre la date de dernière synchronisation et les limitations.

## Modèle de commande

Une opération hors ligne est une commande immuable avec : `command_id` UUID généré sur l’appareil, `device_id`, `tenant_id`, `actor_id`, `type`, horodatage local, version de contexte, charge utile, hash et état local.

États locaux : `draft`, `queued`, `sending`, `accepted`, `rejected`, `conflict`, `requires_review`.

La synchronisation envoie les commandes dans l’ordre de dépendance. Le serveur répond de manière idempotente et attribue les identifiants finaux. Une commande déjà acceptée retourne son résultat original.

## Validation serveur à la reconnexion

Le serveur revalide systématiquement :

1. identité de l’appareil et session ;
2. tenant, utilisateur, rôle, dépôt et état de souscription ;
3. forme et signature/hash de la commande ;
4. article, taxe, prix, remise et moyen de paiement ;
5. caisse ouverte le cas échéant ;
6. disponibilité de stock au moment de l’acceptation ;
7. unicité de la commande et contraintes métier ;
8. écriture de stock/comptable et audit dans une transaction.

Le client ne peut pas déterminer seul qu’une vente est définitivement validée. Avant réponse serveur, il s’agit d’un reçu provisoire clairement marqué.

## Politique de conflit

- Deux ventes hors ligne du dernier article : la première commande serveur acceptée est validée ; la suivante est refusée ou passe en revue selon la permission de vente négative.
- Prix modifié entre-temps : le serveur applique la politique définie (prix capturé autorisé dans une limite ou demande de révision). Le résultat doit afficher l’écart clairement.
- Produit archivé : la commande devient `requires_review`, sans perte de données.
- Droits retirés, abonnement suspendu ou dépôt fermé : commande refusée avec motif actionnable.
- Aucun conflit n’écrase silencieusement une donnée.

## Sécurité des appareils

- Enregistrer l’appareil après authentification ; permettre à l’utilisateur/admin de le révoquer.
- Ne jamais conserver le mot de passe dans IndexedDB ni cache applicatif.
- Utiliser des jetons courts, renouvelables et protégés autant que le navigateur le permet.
- Effacer les caches et files locales à la déconnexion explicite ; expirer les sessions inactives.
- Chiffrer au niveau applicatif toute donnée hors ligne sensible lorsque le modèle de menace et les APIs navigateur le permettent ; minimiser les données stockées avant tout.

## Indicateurs UX

- Badge permanent : en ligne / hors ligne / synchronisation / erreurs à traiter.
- Compteur des commandes en attente.
- Liste « À synchroniser » avec résultat, raison de rejet, action de réessai ou de brouillon.
- Dernière synchronisation affichée et compréhensible.
- Aucune fausse promesse : stock et totaux hors ligne doivent signaler leur fraîcheur.

## Tests de résilience

- Créer plusieurs ventes hors ligne, fermer/reprendre l’application et vérifier la persistance.
- Couper le réseau pendant l’envoi, rejouer la commande et prouver l’absence de doublon.
- Utiliser deux appareils sur un stock faible et vérifier le conflit attendu.
- Révoquer un appareil puis confirmer qu’il ne peut plus synchroniser.
- Mettre à jour le service worker sans perdre les commandes locales.

## Mise en œuvre (EPIC-06, 2026-10-15)

- **Service worker** `public/sw.js` (build de production uniquement, servi en `no-cache`) : ressources hachées `/build/assets/*` en cache d'abord ; pages `/sales/pos`, `/sync` et `/dashboard` en réseau d'abord avec dernière version en cache ; page `/offline.html` sinon. Aucune requête d'API, Inertia ou autre que GET n'est mise en cache. Une nouvelle version n'est activée qu'après accord de l'utilisateur (« Mettre à jour »).
- **IndexedDB** (`resources/js/offline`) : une base par utilisateur et entreprise (`diaba:{user}:{tenant}`) contenant la clé d'appareil, le catalogue du dépôt (articles, codes-barres, prix, taxe), les clients, le stock au moment de la synchronisation et la file de commandes. Jamais de mot de passe ni de jeton. Effacée à la déconnexion explicite, après avertissement s'il reste des ventes non envoyées.
- **Appareils** (`offline_devices`) : enregistrés automatiquement au premier usage, liés à un utilisateur, révocables (Paramètres › Appareils, par l'utilisateur ou un administrateur). Un appareil révoqué ne peut plus ni télécharger le catalogue ni envoyer de commandes, et sa clé ne peut pas être réenregistrée.
- **Commandes** (`sync_commands`, append-only, `(tenant_id, command_id)` unique) : le serveur revalide tout et enregistre un statut définitif — `accepted` (facture et paiements créés), `conflict` (stock insuffisant), `requires_review` (prix modifié sans droit de modification, article archivé), `rejected` (caisse fermée, droits retirés, données invalides). Rejouer une commande renvoie exactement la même réponse ; un même identifiant avec un contenu différent est refusé (`hash_mismatch`). Un abonnement en lecture seule laisse la commande en file sur l'appareil (`deferred`).
- **Vente comptoir** : hors connexion, le scan et la recherche utilisent le catalogue local ; la vente est mise en file avec un **reçu provisoire** clairement marqué. Si le réseau coupe pendant un envoi en ligne, la vente est mise en file avec la même clé : si le serveur l'avait reçue, la synchronisation renvoie simplement son résultat.
- **Catalogue incrémental** : `GET /sync/catalog?warehouse=&since=` renvoie les articles et clients modifiés depuis le curseur (y compris archivés, pour suppression locale), avec un recouvrement de 5 secondes ; le stock du dépôt est renvoyé en entier. Un ajout ou retrait de code-barres marque l'article modifié.
- **Interface** : badge permanent (En ligne / Hors connexion / Synchronisation / Attention requise, avec compteur), page « À synchroniser » (résultat, motif, renvoi aux prix actuels, renvoi avec motif de stock négatif, abandon), synchronisation toutes les 60 s et au retour du réseau.
- **Restent en ligne** (conformément au périmètre) : clôture de caisse, devis/factures en back-office, achats, transferts et inventaires.
