# Critères d’acceptation de haut niveau

## Multi-entreprise et droits

- [ ] Deux entreprises distinctes ne peuvent jamais voir, rechercher, exporter ni deviner les données l’une de l’autre.
- [ ] Un utilisateur non affecté à un dépôt ne peut pas voir ni modifier son stock ou ses ventes.
- [ ] Le menu, l’API et les jobs refusent une action non autorisée.
- [ ] Chaque action sensible laisse une trace d’audit avec acteur, contexte, date et motif lorsque requis.

## Abonnements

- [ ] Un essai se termine après dix jours et l’entreprise devient lecture seule pendant sept jours.
- [ ] Une formule mono-dépôt bloque la création d’un second dépôt sauf option/upgrade actif.
- [ ] Un module comptable n’est accessible que lorsque la formule le permet.
- [ ] Une baisse de formule ne supprime rien et alerte si les limites ne sont pas respectées.

## Ventes, achats et stock

- [ ] Un scan valide ajoute le bon article au panier en moins d’une interaction supplémentaire.
- [ ] Une vente validée crée un mouvement de stock unique et un reçu/facture avec numéro unique.
- [ ] Sans permission explicite, une vente ne peut pas produire un stock négatif.
- [ ] Une réception validée augmente le stock du bon dépôt.
- [ ] Un transfert ne sort définitivement du dépôt source qu’à l’expédition et n’entre au dépôt destination qu’à réception.
- [ ] Un document validé n’est ni modifiable ni supprimable ; un avoir/régularisation conserve le lien à l’origine.

## Caisse et comptabilité

- [ ] Une caisse fermée ne reçoit pas d’encaissement direct ; toute exception est auditée.
- [ ] Chaque écriture postée est équilibrée, liée à sa source et non modifiable.
- [ ] Rejouer une commande, un webhook ou un job ne double ni paiement, ni mouvement de stock, ni écriture.
- [ ] Une période clôturée bloque toute écriture supplémentaire.
- [ ] Le grand livre et la balance reposent uniquement sur les écritures postées.

## UX, mobile et PWA

- [ ] Chaque écran affiche entreprise, dépôt et rubrique active.
- [ ] Les actions fréquentes sont atteignables depuis l’accueil en deux interactions ou moins.
- [ ] Le flux de vente est utilisable sur smartphone tactile, sans tableau horizontal requis.
- [ ] Les contrôles tactiles atteignent 44 × 44 px et sont lisibles avec contraste suffisant.
- [ ] Hors ligne, une vente et un encaissement peuvent être préparés, persistés après redémarrage et marqués provisoires.
- [ ] À la synchronisation, aucune commande n’est doublée ; les conflits sont explicités et résolubles.

## Sécurité et exploitation

- [ ] HTTP redirige vers HTTPS ; les secrets ne sont ni dans le dépôt ni dans les logs.
- [ ] MFA est imposé aux administrateurs.
- [ ] La base, Redis et les interfaces d’administration ne sont pas exposés publiquement.
- [ ] Une restauration de sauvegarde est réalisée et documentée avant ouverture aux premiers clients.
- [ ] Les contrôles de santé, alertes de backup et erreurs de jobs sont surveillés.
