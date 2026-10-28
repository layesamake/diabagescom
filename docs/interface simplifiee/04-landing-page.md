# Landing page Diaba Gescom

**But** : faire démarrer l'essai gratuit de 10 jours. Un seul objectif par écran : le bouton « Essayer gratuitement ».
Images : `images/landing/landing-ordinateur.png` et `landing-telephone.png`. HTML : `maquettes-html/LandingOrdinateur.html` et `LandingTelephone.html`.

## Ordre des sections
1. **En-tête** : logo, liens (Comment ça marche, Prix, Questions), Se connecter, bouton « Essayer gratuitement ». Téléphone : logo, Connexion, menu.
2. **Héro** : « Vendez en 3 gestes. Même sans savoir bien lire. » + texte court + « Essayer 10 jours gratuits » + « Voir une vente en 30 secondes ». Garanties : sans engagement, aucune carte bancaire, aide sur WhatsApp. Visuel : téléphone avec l'écran Vendre.
3. **Fait pour** : types de commerces.
4. **Les 4 soucis** : cahier, argent qui manque en caisse, rupture de stock, crédit oublié.
5. **Une vente = 3 gestes** : photo, paiement, c'est fait.
6. **Pensé pour tout le monde** : couleurs, voix en langue locale, sans internet, Wave et Orange Money.
7. **Fonctions** : 8 fonctions principales.
8. **Témoignage** : vidéo 30 s + phrase d'un vrai commerçant (à fournir par Etienne, ne pas inventer).
9. **Prix** : Essentiel 5 000 F, Business 12 500 F (mis en avant), Premium 15 000 F par mois. Téléphone : Business en premier.
10. **Questions fréquentes** (accordéon).
11. **Appel final** : un seul champ « numéro WhatsApp » + « Commencer gratuitement », lien WhatsApp.
12. **Pied de page**.
Téléphone uniquement : **barre fixe en bas** avec « Essayer gratuitement » + bouton WhatsApp.

## Décisions d'Etienne (18 octobre 2026)
- Domaine : **diaba.cloud** (la page de vente et l'application).
- Toutes les formules incluent la boutique en ligne. Seule la formule Essentiel n'a pas la comptabilité SYSCOHADA (Business et Premium l'ont).
- Témoignage : pas encore de vrai client. **Ne pas inventer.** Tant qu'il n'existe pas, retirer le bloc 8 ou le remplacer par « Ils nous testent » sans citation.
- Code et test : l'application tourne dans Docker Desktop chez Etienne ; la recette se fait sur le site local, pas en production.

- WhatsApp : **+221 76 864 58 36** (lien `https://wa.me/221768645836`, boutons de l'appel final et de la barre du bas sur téléphone).
- Vidéo de démonstration : pas encore disponible. Le bouton « Voir une vente en 30 secondes » et le bloc témoignage (vidéo + phrase) sont **retirés** de la maquette. Les remettre quand la vidéo et un vrai témoignage existent (bouton après « Essayer », bloc avant « Prix »).

## Reste à confirmer par Etienne avant mise en ligne
- Le témoignage réel (texte, prénom, commerce, ville, accord écrit).

## Mesure
Suivre : clics sur chaque bouton « Essayer », inscriptions terminées, clics WhatsApp. Chargement < 3 s en 3G : images compressées (WebP), pas de vidéo lancée automatiquement.
