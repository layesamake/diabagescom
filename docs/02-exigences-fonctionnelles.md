# Exigences fonctionnelles

## 1. Acteurs

| Acteur | Périmètre |
|---|---|
| Super-administrateur Diaba | Plateforme, entreprises, formules, abonnements, support et audit global |
| Administrateur d’entreprise | Tous les droits dans son entreprise |
| Vendeur / caissier | Ventes, devis, encaissements et caisse autorisée |
| Gestionnaire de dépôt | Stock, réceptions, transferts, inventaires et étiquettes de ses dépôts |
| Responsable achats | Fournisseurs, commandes d’achat et réceptions à soumettre |
| Comptable | Journaux, écritures diverses, banques, clôtures et états comptables |
| Direction | Consultation des indicateurs et rapports autorisés |

Un utilisateur est rattaché à une entreprise et peut être affecté à un ou plusieurs dépôts. Les autorisations sont appliquées sur le serveur à chaque requête ; masquer un menu ne constitue jamais une mesure de sécurité.

## 2. Gestion de la plateforme SaaS

- Inscription ou création accompagnée d’une entreprise cliente.
- Création d’un premier administrateur et premier dépôt dans l’assistant de démarrage.
- Choix d’une formule ; essai de dix jours ; cycle mensuel ou annuel.
- Gestion des factures SaaS, tentatives de paiement, paiements, remboursements, échéances et changements de formule.
- Ajout et retrait d’options à la carte.
- Console du super-administrateur : entreprises, abonnements, paiements, paramètres globaux, santé du système et assistance tracée.

## 3. Paramétrage d’entreprise

- Profil : raison sociale, contacts, adresse, NINEA/RCCM si renseignés, logo, fuseau horaire, langue et préférences de documents.
- Taxes, unités, catégories, méthodes de paiement, caisses, banques, séquences de documents et seuils d’alerte.
- Dépôts : identité, adresse, utilisateurs habilités, caisse par défaut et statut actif/archivé.
- Rôles intégrés et permissions fines : consulter, créer, modifier un brouillon, valider, annuler/régulariser, exporter.

## 4. Référentiels commerciaux

### Articles et services

- Type `stockable`, `service` ou `consommable`.
- Référence interne unique par entreprise, nom, description, catégorie, unité, image, taux de taxe, coût standard et prix de vente.
- Plusieurs codes-barres possibles, dont un code principal ; formats à accepter : EAN-13, UPC-A, Code 128 et QR code.
- Seuil de stock minimum par dépôt, activation/désactivation, prix modifiable uniquement par permission.
- Étiquette à code-barres interne générable pour un article sans code fournisseur.

### Tiers

- Client : identité, contacts, adresse, plafond de crédit, solde, conditions de paiement et statut.
- Fournisseur : identité, contacts, adresse, conditions de paiement, solde, statut et catalogue/prix éventuels.

## 5. Ventes et encaissements

- Devis → commande → facture/vente ; chaque conversion conserve le lien au document d’origine.
- Vente comptoir tactile : scan/recherche, panier, quantités, remise encadrée, client facultatif, paiement et reçu.
- Paiements partiels, multi-modes, crédit client et remboursement contrôlé.
- Avoir/retour à partir d’une facture validée, jamais par modification directe de la facture.
- Documents avec séquence unique par entreprise et type : devis, commande, facture, reçu, avoir.
- PDF imprimable/téléchargeable avec logo et mentions de l’entreprise.

### Règles de validation des ventes

- Une vente validée est immuable ; son annulation génère un avoir ou une contre-passation traçable.
- Le stock insuffisant bloque la validation par défaut. Une permission explicite `sell_negative_stock` permet une exception journalisée.
- Le prix catalogue ne peut être modifié que par permission. Les remises au-delà du seuil du rôle exigent une validation ou sont refusées.
- Une facture est rattachée à un dépôt, une entreprise et, si applicable, un client.

## 6. Achats et fournisseurs

- Demande/commande d’achat, réception partielle ou complète, facture fournisseur, paiement et retour fournisseur.
- La réception crée le mouvement de stock ; la facture fournisseur crée ou complète la dette selon le paramétrage retenu.
- Réceptions, paiements et retours doivent rester liés à leur document source.

## 7. Stock et dépôts

- Quantité disponible calculée depuis les mouvements validés, jamais modifiée directement.
- Types de mouvements : stock initial, réception, vente, retour client, retour fournisseur, transfert sortant, transfert entrant, ajustement positif/négatif et réserve éventuelle.
- Transfert en deux temps : expédition depuis le dépôt source, puis réception par le dépôt destination. Le statut intermédiaire est `in_transit`.
- Inventaire : comptage par scan ou recherche, calcul d’écart, soumission et validation autorisée avec motif obligatoire.
- Alertes de seuil bas, rupture et stock négatif.

## 8. Caisse, banques et trésorerie

- Ouverture/fermeture de caisse avec solde théorique, compté et écart justifié.
- Encaissements clients, paiements fournisseurs, dépenses et recettes diverses.
- Modes : espèces, Wave, Orange Money, carte, virement, chèque si activé.
- Les mouvements de trésorerie portent une source, un justificatif et un état de validation.

## 9. Comptabilité — conditionnée à la formule

- Plan SYSCOHADA configurable par entreprise à partir d’un modèle géré par la plateforme.
- Journaux ventes, achats, caisse, banque, opérations diverses et inventaire.
- Écritures automatiques équilibrées à partir des opérations métier ; écritures diverses réservées au comptable.
- Grand livre, balance générale, comptes clients/fournisseurs, rapprochement bancaire, périodes, clôture et états financiers.
- Après validation, aucune suppression ni édition d’écriture ; correction uniquement par écriture inverse ou de régularisation liée.

## 10. Rapports

- Chiffre d’affaires et nombre de ventes par période, dépôt, utilisateur, client et moyen de paiement.
- Stock disponible, valorisation, rotation, ruptures et mouvements.
- Créances clients, dettes fournisseurs, caisse et banque.
- Rapports comptables des formules Compta.
- Filtres par période, dépôt et utilisateur selon permission ; export PDF/Excel journalisé.

## 11. Notifications

- Dans l’application : stock faible, factures échues, caisse à fermer, conflits de synchronisation, fin d’essai, abonnement impayé et échec d’intégration.
- Prévoir une architecture de canaux e-mail/push/WhatsApp, mais n’activer que les canaux validés. Les notifications ne doivent jamais divulguer de données sensibles sur un écran verrouillé.
