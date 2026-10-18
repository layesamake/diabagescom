<?php

namespace App\Domain\Audio;

/**
 * Catalogue des phrases du mode simple (docs/mode-simple/02-messages-audio.md).
 *
 * Chaque phrase est découpée en segments autour de ses variables : une
 * phrase sans variable a 1 segment (« seul ») ; une phrase à une variable a
 * 2 segments (avant/après) ; une phrase à deux variables a 3 segments.
 * Certains segments entre deux variables consécutives sont vides (ex. STK-04
 * « Il reste {nombre} {article}. » n'a qu'une espace entre les deux) : ils
 * sont listés dans BLANK_SEGMENTS et ne nécessitent aucun enregistrement.
 *
 * Les textes français restent affichés à l'écran ; seule la voix change de
 * langue. {montant}/{nombre}/{heure} sont lus avec les mots de la section
 * Chiffres (NumberWords) ; {article}/{client} sont le nom dit au micro à la
 * création de l'article ou du client (Product::$name_audio_path,
 * Customer::$name_audio_path).
 */
final class PhraseCatalog
{
    public const string TOKEN_MONTANT = 'montant';

    public const string TOKEN_NOMBRE = 'nombre';

    public const string TOKEN_HEURE = 'heure';

    public const string TOKEN_ARTICLE = 'article';

    public const string TOKEN_CLIENT = 'client';

    /**
     * Groupes dans l'ordre d'enregistrement conseillé (cahier des charges).
     *
     * @var list<string>
     */
    public const array GROUPS = ['Communs', 'Chiffres', 'Vendre', 'Caisse', 'Accueil', 'Clients', 'Stock', 'Premiers pas', 'Mes ventes', 'Ajouter un article', 'Achats', 'Pilotage'];

    /** Codes des 4 premiers groupes (71 enregistrements) : cochent l'étape 2 du guide. */
    public const array CORE_GROUPS = ['Communs', 'Chiffres', 'Vendre', 'Caisse'];

    /**
     * @var array<string, array{group: string, text: string, tokens: list<string>}>
     */
    private const array PHRASES = [
        // Messages communs (12)
        'COM-01' => ['group' => 'Communs', 'text' => 'Touchez le haut-parleur pour écouter.', 'tokens' => []],
        'COM-02' => ['group' => 'Communs', 'text' => 'Revenir en arrière.', 'tokens' => []],
        'COM-03' => ['group' => 'Communs', 'text' => "C'est enregistré.", 'tokens' => []],
        'COM-04' => ['group' => 'Communs', 'text' => 'Vous êtes sûr ? Touchez le bouton vert pour dire oui.', 'tokens' => []],
        'COM-05' => ['group' => 'Communs', 'text' => "C'est annulé.", 'tokens' => []],
        'COM-06' => ['group' => 'Communs', 'text' => "Pas d'internet. Vous pouvez continuer, tout sera envoyé plus tard.", 'tokens' => []],
        'COM-07' => ['group' => 'Communs', 'text' => 'Internet est revenu. Tout est envoyé.', 'tokens' => []],
        'COM-08' => ['group' => 'Communs', 'text' => "Ça n'a pas marché. Essayez encore.", 'tokens' => []],
        'COM-09' => ['group' => 'Communs', 'text' => 'Seul le chef peut faire ça.', 'tokens' => []],
        'COM-10' => ['group' => 'Communs', 'text' => 'Il manque une information. Regardez la case en rouge.', 'tokens' => []],
        'COM-11' => ['group' => 'Communs', 'text' => "Dites le nom de l'article.", 'tokens' => []],
        'COM-12' => ['group' => 'Communs', 'text' => "Je n'ai rien trouvé.", 'tokens' => []],

        // Accueil (14)
        'ACC-01' => ['group' => 'Accueil', 'text' => 'Bonjour, bienvenue.', 'tokens' => []],
        'ACC-02' => ['group' => 'Accueil', 'text' => 'Bonsoir, bienvenue.', 'tokens' => []],
        'ACC-03' => ['group' => 'Accueil', 'text' => "Aujourd'hui, vous avez vendu pour {montant}.", 'tokens' => [self::TOKEN_MONTANT]],
        'ACC-04' => ['group' => 'Accueil', 'text' => "{nombre} ventes aujourd'hui.", 'tokens' => [self::TOKEN_NOMBRE]],
        'ACC-05' => ['group' => 'Accueil', 'text' => "C'est mieux qu'hier.", 'tokens' => []],
        'ACC-06' => ['group' => 'Accueil', 'text' => "C'est moins qu'hier.", 'tokens' => []],
        'ACC-07' => ['group' => 'Accueil', 'text' => 'Vendre : pour encaisser un client.', 'tokens' => []],
        'ACC-08' => ['group' => 'Accueil', 'text' => 'Caisse : pour ouvrir, compter ou fermer la caisse.', 'tokens' => []],
        'ACC-09' => ['group' => 'Accueil', 'text' => 'Stock : pour voir ce qui reste dans le magasin.', 'tokens' => []],
        'ACC-10' => ['group' => 'Accueil', 'text' => 'Clients : pour voir qui vous doit de l\'argent.', 'tokens' => []],
        'ACC-11' => ['group' => 'Accueil', 'text' => 'Attention : {nombre} articles sont presque finis.', 'tokens' => [self::TOKEN_NOMBRE]],
        'ACC-12' => ['group' => 'Accueil', 'text' => 'Des clients vous doivent {montant}.', 'tokens' => [self::TOKEN_MONTANT]],
        'ACC-13' => ['group' => 'Accueil', 'text' => 'Touchez le bouton vert pour continuer le guide.', 'tokens' => []],
        'ACC-14' => ['group' => 'Accueil', 'text' => 'Votre essai gratuit finit dans {nombre} jours.', 'tokens' => [self::TOKEN_NOMBRE]],

        // Vendre (16)
        'VEN-01' => ['group' => 'Vendre', 'text' => "La caisse est fermée. Ouvrez-la d'abord pour vendre.", 'tokens' => []],
        'VEN-02' => ['group' => 'Vendre', 'text' => 'Touchez un article pour l\'ajouter.', 'tokens' => []],
        'VEN-03' => ['group' => 'Vendre', 'text' => '{article}, ajouté.', 'tokens' => [self::TOKEN_ARTICLE]],
        'VEN-04' => ['group' => 'Vendre', 'text' => '{article}, enlevé.', 'tokens' => [self::TOKEN_ARTICLE]],
        'VEN-05' => ['group' => 'Vendre', 'text' => 'Total : {montant}.', 'tokens' => [self::TOKEN_MONTANT]],
        'VEN-06' => ['group' => 'Vendre', 'text' => 'Touchez Encaisser quand le client a fini.', 'tokens' => []],
        'VEN-07' => ['group' => 'Vendre', 'text' => 'Comment le client paie ?', 'tokens' => []],
        'VEN-08' => ['group' => 'Vendre', 'text' => 'Espèces.', 'tokens' => []],
        'VEN-09' => ['group' => 'Vendre', 'text' => 'Par téléphone : Wave ou Orange Money.', 'tokens' => []],
        'VEN-10' => ['group' => 'Vendre', 'text' => 'À crédit : le client paiera plus tard.', 'tokens' => []],
        'VEN-11' => ['group' => 'Vendre', 'text' => 'Pour vendre à crédit, choisissez le client.', 'tokens' => []],
        'VEN-12' => ['group' => 'Vendre', 'text' => 'Combien le client vous donne ?', 'tokens' => []],
        'VEN-13' => ['group' => 'Vendre', 'text' => 'Rendez {montant} au client.', 'tokens' => [self::TOKEN_MONTANT]],
        'VEN-14' => ['group' => 'Vendre', 'text' => "Attention : il n'en reste pas assez dans le stock.", 'tokens' => []],
        'VEN-15' => ['group' => 'Vendre', 'text' => 'Vente faite. Merci.', 'tokens' => []],
        'VEN-16' => ['group' => 'Vendre', 'text' => "Le ticket s'imprime.", 'tokens' => []],

        // Premiers pas (14) — PP-02 attendue étape du guide
        'PP-01' => ['group' => 'Premiers pas', 'text' => 'Bienvenue. On va préparer votre boutique, étape par étape.', 'tokens' => []],
        'PP-02' => ['group' => 'Premiers pas', 'text' => '{nombre} étapes faites sur 11.', 'tokens' => [self::TOKEN_NOMBRE]],
        'PP-03' => ['group' => 'Premiers pas', 'text' => 'Étape 1 : remplir la fiche de votre entreprise.', 'tokens' => []],
        'PP-04' => ['group' => 'Premiers pas', 'text' => 'Étape 3 : ajouter vos articles.', 'tokens' => []],
        'PP-05' => ['group' => 'Premiers pas', 'text' => 'Étape 4 : compter votre stock de départ.', 'tokens' => []],
        'PP-06' => ['group' => 'Premiers pas', 'text' => 'Étape 5 : ouvrir la caisse.', 'tokens' => []],
        'PP-07' => ['group' => 'Premiers pas', 'text' => 'Étape 6 : faire une première vente.', 'tokens' => []],
        'PP-08' => ['group' => 'Premiers pas', 'text' => 'Étape 7 : ajouter un client.', 'tokens' => []],
        'PP-09' => ['group' => 'Premiers pas', 'text' => 'Étape 8 : inviter un vendeur ou un collaborateur.', 'tokens' => []],
        'PP-10' => ['group' => 'Premiers pas', 'text' => 'Étape 9 : sauvegarder vos données.', 'tokens' => []],
        'PP-11' => ['group' => 'Premiers pas', 'text' => 'Étape 10 : ajouter un fournisseur.', 'tokens' => []],
        'PP-12' => ['group' => 'Premiers pas', 'text' => 'Étape 11 : vérifier les comptes avec votre comptable.', 'tokens' => []],
        'PP-13' => ['group' => 'Premiers pas', 'text' => "Bravo, c'est fait.", 'tokens' => []],
        'PP-14' => ['group' => 'Premiers pas', 'text' => 'Votre boutique est prête. Vous pouvez vendre.', 'tokens' => []],

        // Mes ventes (9)
        'VTE-01' => ['group' => 'Mes ventes', 'text' => "Vos ventes d'aujourd'hui : {montant}, en {nombre} ventes.", 'tokens' => [self::TOKEN_MONTANT, self::TOKEN_NOMBRE]],
        'VTE-02' => ['group' => 'Mes ventes', 'text' => 'En espèces : {montant}.', 'tokens' => [self::TOKEN_MONTANT]],
        'VTE-03' => ['group' => 'Mes ventes', 'text' => 'Par téléphone : {montant}.', 'tokens' => [self::TOKEN_MONTANT]],
        'VTE-04' => ['group' => 'Mes ventes', 'text' => 'À crédit : {montant}.', 'tokens' => [self::TOKEN_MONTANT]],
        'VTE-05' => ['group' => 'Mes ventes', 'text' => 'Vente de {heure}.', 'tokens' => [self::TOKEN_HEURE]],
        'VTE-06' => ['group' => 'Mes ventes', 'text' => "Touchez Ticket pour l'imprimer encore.", 'tokens' => []],
        'VTE-07' => ['group' => 'Mes ventes', 'text' => 'Le client rapporte un article ? Touchez Retour.', 'tokens' => []],
        'VTE-08' => ['group' => 'Mes ventes', 'text' => 'Annuler une vente : seul le chef peut le faire.', 'tokens' => []],
        'VTE-09' => ['group' => 'Mes ventes', 'text' => "Pas encore de vente aujourd'hui.", 'tokens' => []],

        // Caisse (13)
        'CAI-01' => ['group' => 'Caisse', 'text' => 'La caisse est fermée.', 'tokens' => []],
        'CAI-02' => ['group' => 'Caisse', 'text' => "Comptez l'argent du tiroir pour ouvrir.", 'tokens' => []],
        'CAI-03' => ['group' => 'Caisse', 'text' => 'Touchez plus pour chaque billet.', 'tokens' => []],
        'CAI-04' => ['group' => 'Caisse', 'text' => 'Dans le tiroir : {montant}.', 'tokens' => [self::TOKEN_MONTANT]],
        'CAI-05' => ['group' => 'Caisse', 'text' => 'La caisse est ouverte.', 'tokens' => []],
        'CAI-06' => ['group' => 'Caisse', 'text' => 'Il doit y avoir {montant} dans la caisse.', 'tokens' => [self::TOKEN_MONTANT]],
        'CAI-07' => ['group' => 'Caisse', 'text' => 'Sortir de l\'argent : pour payer une dépense.', 'tokens' => []],
        'CAI-08' => ['group' => 'Caisse', 'text' => 'Pour quoi ? Choisissez.', 'tokens' => []],
        'CAI-09' => ['group' => 'Caisse', 'text' => "Mettre de l'argent dans la caisse.", 'tokens' => []],
        'CAI-10' => ['group' => 'Caisse', 'text' => "Pour fermer, comptez encore l'argent du tiroir.", 'tokens' => []],
        'CAI-11' => ['group' => 'Caisse', 'text' => 'Le compte est bon. Merci.', 'tokens' => []],
        'CAI-12' => ['group' => 'Caisse', 'text' => 'Il manque {montant}.', 'tokens' => [self::TOKEN_MONTANT]],
        'CAI-13' => ['group' => 'Caisse', 'text' => 'Il y a {montant} en trop.', 'tokens' => [self::TOKEN_MONTANT]],

        // Stock (9) — STK-04 : segment entre les deux variables vide (BLANK_SEGMENTS)
        'STK-01' => ['group' => 'Stock', 'text' => 'Vert : il en reste assez.', 'tokens' => []],
        'STK-02' => ['group' => 'Stock', 'text' => 'Orange : bientôt fini.', 'tokens' => []],
        'STK-03' => ['group' => 'Stock', 'text' => 'Rouge : presque fini. Il faut racheter.', 'tokens' => []],
        'STK-04' => ['group' => 'Stock', 'text' => 'Il reste {nombre} {article}.', 'tokens' => [self::TOKEN_NOMBRE, self::TOKEN_ARTICLE]],
        'STK-05' => ['group' => 'Stock', 'text' => 'Touchez plus quand la marchandise arrive.', 'tokens' => []],
        'STK-06' => ['group' => 'Stock', 'text' => 'Touchez moins pour retirer du stock.', 'tokens' => []],
        'STK-07' => ['group' => 'Stock', 'text' => 'Pourquoi vous retirez ? Abîmé, mort ou perdu.', 'tokens' => []],
        'STK-08' => ['group' => 'Stock', 'text' => 'Le stock est à jour.', 'tokens' => []],
        'STK-09' => ['group' => 'Stock', 'text' => 'Comptez ce que vous avez dans le magasin, article par article.', 'tokens' => []],

        // Clients et crédit (11) — CLI-02 : segment entre les deux variables non vide
        'CLI-01' => ['group' => 'Clients', 'text' => 'Vos clients vous doivent {montant}.', 'tokens' => [self::TOKEN_MONTANT]],
        'CLI-02' => ['group' => 'Clients', 'text' => '{client} vous doit {montant}.', 'tokens' => [self::TOKEN_CLIENT, self::TOKEN_MONTANT]],
        'CLI-03' => ['group' => 'Clients', 'text' => '{client} ne vous doit rien.', 'tokens' => [self::TOKEN_CLIENT]],
        'CLI-04' => ['group' => 'Clients', 'text' => 'Il paie tout ? Touchez le bouton vert.', 'tokens' => []],
        'CLI-05' => ['group' => 'Clients', 'text' => 'Il paie une partie ? Touchez le montant.', 'tokens' => []],
        'CLI-06' => ['group' => 'Clients', 'text' => "C'est noté. Il doit encore {montant}.", 'tokens' => [self::TOKEN_MONTANT]],
        'CLI-07' => ['group' => 'Clients', 'text' => 'Tout est payé. Merci.', 'tokens' => []],
        'CLI-08' => ['group' => 'Clients', 'text' => 'Appeler le client.', 'tokens' => []],
        'CLI-09' => ['group' => 'Clients', 'text' => 'Nouveau client : son nom, son téléphone, et une photo si vous voulez.', 'tokens' => []],
        'CLI-10' => ['group' => 'Clients', 'text' => 'Dites le nom du client.', 'tokens' => []],
        'CLI-11' => ['group' => 'Clients', 'text' => 'Attention : ce client doit déjà beaucoup.', 'tokens' => []],

        // Ajouter un article (7)
        'ART-01' => ['group' => 'Ajouter un article', 'text' => "Prenez d'abord une photo de l'article.", 'tokens' => []],
        'ART-02' => ['group' => 'Ajouter un article', 'text' => "Dites le nom de l'article.", 'tokens' => []],
        'ART-03' => ['group' => 'Ajouter un article', 'text' => 'Écrivez le prix de vente.', 'tokens' => []],
        'ART-04' => ['group' => 'Ajouter un article', 'text' => 'Vous le vendez comment : à la pièce, au sac, au kilo, au litre, au plateau ou au carton ?', 'tokens' => []],
        'ART-05' => ['group' => 'Ajouter un article', 'text' => 'Choisissez la famille.', 'tokens' => []],
        'ART-06' => ['group' => 'Ajouter un article', 'text' => "Touchez Enregistrer pour garder l'article.", 'tokens' => []],
        'ART-07' => ['group' => 'Ajouter un article', 'text' => "L'article est ajouté.", 'tokens' => []],

        // Achats (9) — ACH-06 : segment entre les deux variables vide (BLANK_SEGMENTS)
        'ACH-01' => ['group' => 'Achats', 'text' => 'Vos commandes aux fournisseurs.', 'tokens' => []],
        'ACH-02' => ['group' => 'Achats', 'text' => 'Commande en route.', 'tokens' => []],
        'ACH-03' => ['group' => 'Achats', 'text' => 'Commande reçue.', 'tokens' => []],
        'ACH-04' => ['group' => 'Achats', 'text' => 'La marchandise est arrivée ? Touchez la commande.', 'tokens' => []],
        'ACH-05' => ['group' => 'Achats', 'text' => 'Comptez ce que vous avez reçu.', 'tokens' => []],
        'ACH-06' => ['group' => 'Achats', 'text' => 'Il manque {nombre} {article}.', 'tokens' => [self::TOKEN_NOMBRE, self::TOKEN_ARTICLE]],
        'ACH-07' => ['group' => 'Achats', 'text' => 'Touchez Ranger dans le stock.', 'tokens' => []],
        'ACH-08' => ['group' => 'Achats', 'text' => 'Stock mis à jour.', 'tokens' => []],
        'ACH-09' => ['group' => 'Achats', 'text' => 'Nouvelle commande : choisissez le fournisseur, puis les articles.', 'tokens' => []],

        // Pilotage (9)
        'PIL-01' => ['group' => 'Pilotage', 'text' => 'Ce mois-ci, vous avez vendu pour {montant}.', 'tokens' => [self::TOKEN_MONTANT]],
        'PIL-02' => ['group' => 'Pilotage', 'text' => 'Vous avez dépensé {montant}.', 'tokens' => [self::TOKEN_MONTANT]],
        'PIL-03' => ['group' => 'Pilotage', 'text' => 'Il vous reste {montant}.', 'tokens' => [self::TOKEN_MONTANT]],
        'PIL-04' => ['group' => 'Pilotage', 'text' => 'C\'est mieux que le mois dernier.', 'tokens' => []],
        'PIL-05' => ['group' => 'Pilotage', 'text' => "C'est moins bien que le mois dernier.", 'tokens' => []],
        'PIL-06' => ['group' => 'Pilotage', 'text' => 'Dans la caisse : {montant}.', 'tokens' => [self::TOKEN_MONTANT]],
        'PIL-07' => ['group' => 'Pilotage', 'text' => 'Sur le téléphone : {montant}.', 'tokens' => [self::TOKEN_MONTANT]],
        'PIL-08' => ['group' => 'Pilotage', 'text' => 'Chez les clients : {montant}.', 'tokens' => [self::TOKEN_MONTANT]],
        'PIL-09' => ['group' => 'Pilotage', 'text' => 'Ce qui se vend le mieux : {article}.', 'tokens' => [self::TOKEN_ARTICLE]],

        // Chiffres et montants (30) — mots simples, sans variable
        'CHF-01' => ['group' => 'Chiffres', 'text' => 'zéro', 'tokens' => []],
        'CHF-02' => ['group' => 'Chiffres', 'text' => 'un', 'tokens' => []],
        'CHF-03' => ['group' => 'Chiffres', 'text' => 'deux', 'tokens' => []],
        'CHF-04' => ['group' => 'Chiffres', 'text' => 'trois', 'tokens' => []],
        'CHF-05' => ['group' => 'Chiffres', 'text' => 'quatre', 'tokens' => []],
        'CHF-06' => ['group' => 'Chiffres', 'text' => 'cinq', 'tokens' => []],
        'CHF-07' => ['group' => 'Chiffres', 'text' => 'six', 'tokens' => []],
        'CHF-08' => ['group' => 'Chiffres', 'text' => 'sept', 'tokens' => []],
        'CHF-09' => ['group' => 'Chiffres', 'text' => 'huit', 'tokens' => []],
        'CHF-10' => ['group' => 'Chiffres', 'text' => 'neuf', 'tokens' => []],
        'CHF-11' => ['group' => 'Chiffres', 'text' => 'dix', 'tokens' => []],
        'CHF-12' => ['group' => 'Chiffres', 'text' => 'onze', 'tokens' => []],
        'CHF-13' => ['group' => 'Chiffres', 'text' => 'douze', 'tokens' => []],
        'CHF-14' => ['group' => 'Chiffres', 'text' => 'treize', 'tokens' => []],
        'CHF-15' => ['group' => 'Chiffres', 'text' => 'quatorze', 'tokens' => []],
        'CHF-16' => ['group' => 'Chiffres', 'text' => 'quinze', 'tokens' => []],
        'CHF-17' => ['group' => 'Chiffres', 'text' => 'seize', 'tokens' => []],
        'CHF-18' => ['group' => 'Chiffres', 'text' => 'vingt', 'tokens' => []],
        'CHF-19' => ['group' => 'Chiffres', 'text' => 'trente', 'tokens' => []],
        'CHF-20' => ['group' => 'Chiffres', 'text' => 'quarante', 'tokens' => []],
        'CHF-21' => ['group' => 'Chiffres', 'text' => 'cinquante', 'tokens' => []],
        'CHF-22' => ['group' => 'Chiffres', 'text' => 'soixante', 'tokens' => []],
        'CHF-23' => ['group' => 'Chiffres', 'text' => 'quatre-vingt', 'tokens' => []],
        'CHF-24' => ['group' => 'Chiffres', 'text' => 'et', 'tokens' => []],
        'CHF-25' => ['group' => 'Chiffres', 'text' => 'cent', 'tokens' => []],
        'CHF-26' => ['group' => 'Chiffres', 'text' => 'mille', 'tokens' => []],
        'CHF-27' => ['group' => 'Chiffres', 'text' => 'million', 'tokens' => []],
        'CHF-28' => ['group' => 'Chiffres', 'text' => 'francs', 'tokens' => []],
        'CHF-29' => ['group' => 'Chiffres', 'text' => 'heures', 'tokens' => []],
        'CHF-30' => ['group' => 'Chiffres', 'text' => 'minutes', 'tokens' => []],
    ];

    /**
     * Segments sans aucun mot (juste une espace entre deux variables
     * consécutives) : aucun enregistrement n'est demandé ni requis pour eux.
     *
     * @var array<string, list<int>>
     */
    private const array BLANK_SEGMENTS = [
        'STK-04' => [1],
        'ACH-06' => [1],
    ];

    /** @return array<string, array{group: string, text: string, tokens: list<string>}> */
    public static function all(): array
    {
        return self::PHRASES;
    }

    public static function exists(string $code): bool
    {
        return array_key_exists($code, self::PHRASES);
    }

    /** @return list<string> */
    public static function codesInGroup(string $group): array
    {
        return array_keys(array_filter(self::PHRASES, fn (array $p): bool => $p['group'] === $group));
    }

    /** Codes des 4 premiers groupes (71 au total), qui cochent l'étape 2 du guide. */
    public static function coreCodes(): array
    {
        return array_merge(...array_map(self::codesInGroup(...), self::CORE_GROUPS));
    }

    /** Nombre de segments attendus pour cette phrase (1 si pas de variable). */
    public static function segmentCount(string $code): int
    {
        return count(self::PHRASES[$code]['tokens']) + 1;
    }

    /** Index des segments qui demandent réellement un enregistrement. */
    public static function requiredSegments(string $code): array
    {
        $blank = self::BLANK_SEGMENTS[$code] ?? [];

        return array_values(array_diff(range(0, self::segmentCount($code) - 1), $blank));
    }

    /** @return list<string> */
    public static function tokens(string $code): array
    {
        return self::PHRASES[$code]['tokens'];
    }

    public static function text(string $code): string
    {
        return self::PHRASES[$code]['text'];
    }

    public static function group(string $code): string
    {
        return self::PHRASES[$code]['group'];
    }
}
