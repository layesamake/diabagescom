# Comptabilité SYSCOHADA et automatisation

## Portée et garde-fous

Le module comptable est activé uniquement pour les formules Compta. Il doit partir d’un modèle de plan comptable SYSCOHADA révisé, puis permettre une personnalisation contrôlée par entreprise. Avant toute mise en production, le modèle, les règles de TVA, les états et les écritures de clôture doivent être revus par un expert-comptable qualifié dans le pays cible.

La comptabilité est une source de vérité réglementée : les écritures validées sont immuables. Toute correction crée une nouvelle écriture liée, jamais une suppression ou une modification silencieuse.

## Principes de traitement

1. Toute opération métier validée produit zéro ou plusieurs écritures comptables équilibrées dans la même transaction logique.
2. Une opération métier et son écriture comportent une référence croisée unique (`source_type`, `source_id`).
3. La génération est idempotente : rejouer un job ou un webhook ne doit jamais créer une seconde écriture.
4. Les périodes verrouillées ou clôturées interdisent toute nouvelle écriture ; la correction passe par la période ouverte selon la règle choisie et une référence à l’origine.
5. Les comptes, taux et libellés appliqués sont figés à la comptabilisation.
6. Les comptes systémiques requis ne peuvent pas être archivés s’ils sont utilisés par un paramétrage actif.

## Paramètres comptables d’une entreprise

- Exercice fiscal, périodes mensuelles, devise et règles d’arrondi.
- Plan de comptes avec comptes auxiliaires clients/fournisseurs si retenus.
- Journaux : ventes, achats, caisse, banque, opérations diverses, inventaire.
- Comptes par défaut : caisse, banque, mobile money, clients, fournisseurs, stock, coût des ventes, ventes, achats, TVA collectée, TVA déductible, pertes/gains d’inventaire.
- Mappage par taxe et éventuellement par catégorie d’article.
- Politique de valorisation de stock : moyenne pondérée au MVP, appliquée de manière uniforme dans l’entreprise.

## Écritures automatiques minimales

Les numéros de comptes ci-dessous sont des exemples de mappage, pas une liste définitive. Chaque ligne doit être configurée avec le plan SYSCOHADA de l’entreprise.

| Événement validé | Débit | Crédit | Remarques |
|---|---|---|---|
| Facture de vente à crédit | Client | Ventes HT ; TVA collectée | Une ligne ou sous-écriture par taux de TVA si nécessaire |
| Vente payée immédiatement | Caisse / banque / mobile money | Ventes HT ; TVA collectée | Le compte dépend du moyen de paiement |
| Sortie de stock liée à une vente | Coût des ventes | Stock | Seulement pour articles stockables ; coût moyen figé |
| Encaissement d’une facture | Caisse / banque / mobile money | Client | Affectations partielles autorisées |
| Avoir client | Ventes HT ; TVA collectée | Client / caisse / banque | Et stock inverse si produit retourné et remis en stock |
| Réception ou facture achat à crédit | Stock ou charges ; TVA déductible | Fournisseur | Politique à figer : réception ou facture selon processus retenu |
| Paiement fournisseur | Fournisseur | Caisse / banque / mobile money | Affectations partielles autorisées |
| Retour fournisseur | Fournisseur | Stock ou charges ; TVA déductible | Référence au document origine |
| Dépense de caisse | Compte de charge ; TVA déductible éventuelle | Caisse | Justificatif/motif obligatoire |
| Ajustement de stock positif | Stock | Produit d’inventaire | Raison obligatoire |
| Ajustement de stock négatif | Charge d’inventaire | Stock | Raison obligatoire |
| Écart de caisse | Charge ou produit d’écart de caisse | Caisse, ou inverse | Clôture de caisse et validation requises |

## États et contrôle

- Journal, grand livre, balance générale et balance auxiliaire.
- Situation clients : solde, échéance, lettrage/affectations de paiement.
- Situation fournisseurs : solde, échéance, affectations de règlement.
- Livre de caisse, banque et rapprochement bancaire.
- Balance de TVA, contrôle `total débit = total crédit`, comptes non soldés inhabituels et opérations non comptabilisées.
- État de résultat et bilan selon le modèle validé par l’expert-comptable.

## Flux de correction

1. L’utilisateur autorisé ouvre le document validé concerné.
2. Il choisit « Créer une correction » ou « Créer un avoir », indique le motif et joint une pièce si nécessaire.
3. Le système calcule les mouvements inverses/complémentaires et montre un aperçu.
4. Une validation, éventuellement par double approbation, crée le document et l’écriture de correction.
5. Le lien vers le document d’origine est permanent dans les deux sens et apparaît dans l’audit.

## Tests comptables obligatoires

- Toute écriture générée est équilibrée et réconciliable à son événement source.
- Un paiement, webhook ou job rejoué ne peut créer aucun doublon.
- Annulation/avoir inverse correctement les effets de vente, TVA, créance et stock lorsque pertinent.
- Une période clôturée refuse une nouvelle écriture.
- Aucune modification ou suppression d’une écriture postée n’est possible via API, interface ou tâche administrative ordinaire.
- Les rapports utilisent les écritures postées, jamais des documents brouillons.
