# Fiche de recette — avant la mise en production

**Où :** https://dev.diaba.cloud (le site d'essai, pas le vrai site).
**Durée :** environ 30 minutes, 15 si tu ne fais que les cases ★.
**Comment :** coche chaque case ; en cas de doute, note la page, ce que tu as fait, ce que tu as vu, et envoie une capture d'écran.
**Avant de commencer :** connecte-toi avec un compte administrateur (avec la double authentification). Si le site est vide, dis-le-moi : je peux y mettre des données de démonstration.

> Fais les cases ★ une fois sur ordinateur **et** une fois sur téléphone (ou en réduisant la fenêtre du navigateur).

---

## 0. Avant de commencer : modules et formules
- [ ] ★ Paramètres › Fonctionnalités : active la **comptabilité** et la **boutique en ligne** (désactivées par défaut, incluses dans toutes les formules). Sans cela, les sections Boutique et Comptabilité n'apparaissent pas.
- [ ] La désactivation d'un module contenant déjà des données (écritures, commandes) est refusée avec un message clair.
- [ ] ★ Abonnement / tarifs : trois formules seulement, Essentiel (5 000 / 51 000), Business (12 500 / 120 000) et Premium (15 000 / 135 000).

## 1. Accueil et menu
- [ ] ★ La page d'accueil s'affiche sans erreur et la section « À traiter » est lisible.
- [ ] ★ Le menu **Achats** contient « Demandes d'achat ».
- [ ] Paramètres : les rubriques **Entreprise** et **Notifications** sont présentes.

## 2. Paramètres › Entreprise
- [ ] ★ Ajoute un **logo** (PNG ou JPEG) : l'aperçu apparaît. Idem pour le **cachet / signature** et l'image de **devanture**.
- [ ] ★ Renseigne raison sociale, nom commercial, téléphone, e-mail, adresse, ville, **NINEA**, **RCCM**, mention de bas de page, puis **Enregistrer** : un message de confirmation s'affiche.
- [ ] Choisis un **régime de TVA**, enregistre, recharge la page : le choix est conservé.
- [ ] Colle un lien **Google Maps** valide (Partager › Copier le lien) : accepté. Colle un code « intégrer une carte » ou un autre site : **refusé** avec un message clair.
- [ ] ★ Ouvre une facture (Ventes), télécharge le **PDF** : logo en haut, adresse, téléphone, NINEA/RCCM, régime fiscal, cachet en bas et mention de bas de page.
- [ ] Le **ticket de caisse** s'imprime toujours correctement.

## 3. Boutique en ligne (si ta formule l'inclut)
- [ ] ★ La page publique de la boutique affiche le logo, la devanture (si ajoutée) et le bouton **Itinéraire** qui ouvre Google Maps.
- [ ] Passer une commande de test fonctionne jusqu'à la confirmation.

## 4. Catalogue : import et étiquettes
- [ ] ★ **Importer** : télécharge le modèle CSV, remplis 3 articles, analyse le fichier : l'aperçu indique « à créer ». Applique l'import : les articles apparaissent.
- [ ] Réimporte le même fichier : « 0 créé », rien n'est dupliqué.
- [ ] Mets volontairement une erreur (prix avec décimales) : la ligne fautive est signalée et l'import est bloqué.
- [ ] ★ **Étiquettes** : clique « Étiquettes », coche 2 articles ayant un code-barres, choisis le format, clique « Imprimer » : la boîte d'impression s'ouvre dans un nouvel onglet, les étiquettes sont lisibles.
- [ ] Dans cet onglet, annule l'impression puis clique le bouton **Imprimer** de la page : la boîte d'impression se rouvre. Le bouton **Fermer** ferme l'onglet.
- [ ] Scanne une étiquette imprimée avec un téléphone ou un lecteur : le bon code est lu.

## 5. Clients et fournisseurs : import
- [ ] ★ **Clients › Importer (CSV)** : télécharge le modèle, importe 3 clients : ils apparaissent.
- [ ] Réimporte le même fichier en modifiant une ville : « 1 mis à jour », pas de doublon, les autres valeurs ne sont pas effacées.
- [ ] Même test rapide côté **Fournisseurs**.

## 6. Achats : demande d'achat
- [ ] ★ Crée une **demande d'achat** (2 articles), enregistre : elle est en brouillon.
- [ ] **Envoie** la demande : elle reçoit un numéro `DA-…` et n'est plus modifiable.
- [ ] En tant qu'approbateur, **refuse** une demande en donnant un motif, puis **approuve** une autre.
- [ ] Sur la demande approuvée, **crée la commande** en choisissant un fournisseur : tu arrives sur une commande fournisseur en brouillon avec les bonnes quantités.
- [ ] Avec un compte « vendeur », vérifie qu'on ne voit **que ses propres** demandes.

## 7. Comptabilité (formule Compta)
- [ ] ★ **Pièce jointe** : sur une écriture, ajoute un PDF : il apparaît dans la liste, se télécharge, et se retire tant que l'écriture est en brouillon.
- [ ] ★ **États › Bilan** : le total de l'actif est égal au total du passif ; aucun message d'écart.
- [ ] **États › Compte de résultat** : les soldes (marge commerciale, résultat d'exploitation, résultat net) te paraissent cohérents avec tes écritures.
- [ ] Boutons **Excel** et **PDF** : le fichier Excel s'ouvre sans avertissement et les montants sont des nombres ; le PDF est lisible.

## 7 bis. Pilotage, hors ligne et aide
- [ ] ★ Pilotage : ventes, marge et créances s'affichent ; sans données, les textes d'état vide sont lisibles.
- [ ] Couverture de stock, échéancier des dettes fournisseurs, transferts suggérés, comparaison des dépôts : chaque bloc s'affiche, les chiffres sont plausibles.
- [ ] ★ Avec un compte vendeur : le Pilotage est refusé et aucun coût d'achat n'apparaît (fiche article, recherche).
- [ ] Délai fournisseur par dépôt (7 jours par défaut) modifiable ; exports Excel/PDF du Pilotage lisibles.
- [ ] ★ Cloner un article : la copie est créée, l'original reste inchangé.
- [ ] ★ Caisse hors connexion : une vente préparée se synchronise au retour du réseau, sans doublon.
- [ ] ★ Changement de compte sur le même appareil : aucune donnée du premier compte n'est visible.
- [ ] Centre d'aide et guide de démarrage accessibles.

## 8. Rapports de ventes
- [ ] Rapports : boutons **CSV**, **Excel**, **PDF** : les trois fichiers se téléchargent et sont lisibles.

## 9. Notifications
- [ ] Paramètres › Notifications : décoche un sujet « Sur l'accueil », enregistre : il disparaît de « À traiter » sur l'accueil.
- [ ] Coche « Résumé par e-mail » sur un sujet : le choix est conservé après rechargement.

## 10. Console plateforme (compte super-administrateur)
- [ ] Ouvre la fiche d'une entreprise : la section **Fiche de l'entreprise** s'affiche.
- [ ] Change le régime de TVA **sans motif** : refusé. Avec un motif : accepté.
- [ ] Le **cachet / signature** n'est jamais affiché, seulement « présent » ou « aucun ».

## 11. Page d'accueil publique (déconnecté)
- [ ] ★ Le badge « Nouveau : votre boutique en ligne » et la section **Boutique en ligne** s'affichent et sont lisibles sur téléphone.
- [ ] Les **tarifs** indiquent « Boutique en ligne » coché ou barré selon la formule.
- [ ] Rien ne dépasse à droite (pas de défilement horizontal) sur téléphone.

---

## Résultat
- [ ] Toutes les cases ★ sont cochées.
- [ ] Les anomalies relevées m'ont été transmises et sont corrigées.

**Quand tout est bon :** écris-moi « go production ». Je te rappelle alors ce qui va changer chez tes clients avant de lancer quoi que ce soit.
