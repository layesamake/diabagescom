# Brief pour le Delivery Orchestrator — mode simple de Diaba Gescom

**Projet** : ajouter à Diaba Gescom (diaba.cloud) un **mode simple en images** pour des vendeurs qui lisent mal, et refaire la landing page.

## Ce que contient ce dossier
- `01-cahier-des-charges.md` : règles, composants, écrans, rôles, audio, données, lots, critères d'acceptation. **C'est la référence.**
- `02-messages-audio.md` : les 123 phrases + 30 mots de chiffres, avec leurs codes.
- `04-landing-page.md` : contenu et règles de la landing page.
- `design/tokens.css` et `tokens.json` : couleurs, police, tailles. À utiliser tels quels.
- `images/` : 24 écrans téléphone, 8 écrans ordinateur, 2 landing. **Les images font foi pour la mise en page.**
- `maquettes-html/` : les mêmes écrans en HTML. Ouvrez-les dans un navigateur pour lire les tailles, couleurs et espacements exacts. Les écrans marqués « cliquable » dans les images fonctionnent.

## Règles non négociables
1. Ne pas modifier le mode expert. Le mode simple est une nouvelle interface sur les mêmes données et la même logique métier.
2. Chaque lot va d'abord sur un site de test. Production seulement après accord écrit d'Etienne.
3. Ne rien inventer qui n'est pas dans le cahier des charges : poser la question.
4. Les montants, clients, fournisseurs et articles des maquettes sont des exemples, pas des données à coder.

## Lot 1 — Fondations (à lancer maintenant)
- Variables de design (`design/tokens.css`), police Atkinson Hyperlegible Next.
- Les 14 composants du cahier des charges.
- Bascule mode simple / mode expert par utilisateur.
- Menu fixe à gauche (ordinateur) qui devient la barre du bas sous 900 px.
- Livrable : une page de démonstration qui montre chaque composant à côté de l'image correspondante.

## Réponses d'Etienne
- Domaine : diaba.cloud.
- Hébergement de développement : l'application tourne dans **Docker Desktop** chez Etienne. Le site de test est le site local (pas de mise en production avant son accord écrit).
- Toutes les formules incluent la boutique en ligne ; seule Essentiel n'a pas la comptabilité SYSCOHADA.
- Pas encore de témoignage client : ne rien inventer.

## À rendre avant de coder
1. La pile technique et l'hébergement actuels de diaba.cloud.
2. Comment créer le site de test.
3. Vos réponses ou questions sur la section « Questions ouvertes » du cahier des charges.
4. Le découpage du lot 1 en tâches, par bot (Product Architect, UX/UI Designer, Software Engineer, QA & Security).

## Contrôle qualité (bot QA)
À chaque lot, vérifier les « Critères d'acceptation » du cahier des charges, comparer chaque écran à son image (capture côte à côte), et tester à 360 px, 1280 px et 1920 px de large.
