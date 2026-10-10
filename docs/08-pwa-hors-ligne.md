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
