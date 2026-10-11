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

## Mise en œuvre (EPIC-07, 2026-10-16)

> Tout ce qui suit est un **paramétrage provisoire à faire valider par un expert-comptable** avant usage officiel. L'écran Comptabilité l'affiche tant que la validation (nom de l'expert, date) n'est pas enregistrée.

- **Activation** (Comptabilité › Activer, permission `accounting.settings`, formules Compta uniquement) : copie du modèle `config/syscohada.php` (sous-ensemble SYSCOHADA révisé adapté au commerce), journaux `VT` ventes, `AC` achats, `CA` caisse, `BQ` banque et monnaie électronique, `IN` inventaire, `OD` opérations diverses, `AN` à-nouveaux, comptes de paramétrage et premier exercice de 12 périodes mensuelles. Seules les opérations datées à partir de la date de début choisie sont comptabilisées automatiquement.
- **Base de données** : écriture validée équilibrée (`SUM(débit) = SUM(crédit)`, au moins deux lignes, contrainte différée vérifiée au COMMIT), numérotée par journal et exercice (`VT-2026-000001`), immuable (triggers) ; aucune validation dans une période verrouillée ou clôturée ; exercices et périodes sans chevauchement (contraintes d'exclusion) ; chaque ligne au débit OU au crédit.
- **Comptabilisation automatique** (`diaba:accounting:post`, chaque minute, et bouton « Comptabiliser maintenant ») : idempotente grâce à `accounting_postings` (une seule écriture par opération et par nature, même relancée ou concurrente). Une écriture automatique dont la période est verrouillée est reportée au premier jour de la période ouverte suivante ; sans exercice couvrant la date, l'opération reste en attente.

| Événement | Écriture (comptes par défaut) |
|---|---|
| Facture / vente comptoir | D 411 (TTC, auxiliaire client) / C 701 ventes de marchandises ou 706 services (HT) / C 4431 TVA |
| Coût des ventes (articles stockés) | D 6031 / C 311, au coût moyen figé sur la ligne |
| Avoir client | Inverse de la vente ; articles remis en stock : D 311 / C 6031 |
| Encaissement / remboursement client | D 571 caisse, 5213 Wave, 5214 Orange Money, 5215 carte, 521 virement, 513 chèque / C 411 (ou inverse) |
| Réception fournisseur | D 311 / C 4081 factures non parvenues (HT) |
| Facture fournisseur | D 4081 (marchandises reçues) ou 605 (autres), D 4452 TVA / C 401 (TTC, auxiliaire fournisseur) |
| Règlement fournisseur | D 401 / C trésorerie |
| Retour fournisseur | Avec facture : D 401 / C 311, C 4452 ; sans facture : D 4081 / C 311 |
| Dépense / recette de caisse | D 638 / C 571 ; D 571 / C 758 |
| Écart de caisse à la fermeture | Manque : D 658 / C 571 ; excédent : D 571 / C 758 |
| Stock d'ouverture | D 311 / C 4711 (à régulariser par le comptable) |
| Ajustement, écart d'inventaire | D 311 / C 6031 (gain) ; D 6031 / C 311 (perte) |

- Les ventes comptoir passent par 411 puis par l'encaissement (au lieu de D caisse / C ventes directement) : même résultat, lettrage client plus simple.
- **Saisie manuelle** (`accounting.manual_entry`) en brouillon, validation par `accounting.post` ; correction uniquement par **extourne** liée (une seule par écriture).
- **Périodes** : ouverte → verrouillée (réversible) → clôturée (définitif, dans l'ordre) ; **clôture d'exercice** quand toutes les périodes sont clôturées et sans brouillon : à-nouveaux (journal AN) des comptes de classes 1 à 5 et du résultat net en 1301 (bénéfice) / 1309 (perte) au premier jour de l'exercice suivant.
- **États** (écritures validées uniquement, bornés à l'exercice : les soldes antérieurs arrivent par les à-nouveaux) : balance générale, grand livre, balances clients et fournisseurs ; export CSV journalisé (`accounting.export`).
- **Rapprochement bancaire** (`bank.reconcile`) sur les comptes rapprochables : lignes cochées une seule fois, rapprochement terminé seulement si le solde rapproché égale le solde du relevé.
- Reste à faire avec l'expert-comptable : modèle complet SYSCOHADA, déclarations de TVA, états financiers (bilan, compte de résultat), règles d'arrondi.
