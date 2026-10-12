<?php

/*
 * Paramètres produit DIABA GESCOM (docs/01-decisions-produit.md).
 */
return [

    'subscriptions' => [
        // Essai gratuit, puis lecture seule, puis suspension (décisions validées).
        'trial_days' => 10,
        'read_only_days' => 7,

        // Rappels envoyés avant la fin de l'essai (jours restants).
        'trial_notice_days' => [3, 1, 0],

        // Formule proposée par défaut dans l'assistant de création.
        'default_plan' => 'business_accounting',
    ],

    'sales' => [
        // Remise maximale accordée avec la permission sale.discount_standard, en points
        // de base du montant de la ligne (1000 = 10 %). Au-delà : sale.discount_exception.
        // Valeur provisoire, à valider par le propriétaire.
        'standard_discount_max_bp' => 1000,

        // Durée de validité par défaut d'un devis.
        'quote_validity_days' => 30,
    ],

    /*
     * Options à la carte (docs/01). Prix en XOF entiers par mois / par an.
     * À FIXER PAR LE PROPRIÉTAIRE : tant qu'un prix est null, l'option n'est pas proposée.
     */
    'addons' => [
        'extra_user' => ['label' => 'Utilisateur supplémentaire', 'price_monthly' => null, 'price_yearly' => null],
        'extra_warehouse' => ['label' => 'Dépôt supplémentaire', 'price_monthly' => null, 'price_yearly' => null],
    ],

    /*
     * Formules créées si absentes (diaba:sync-plans). Une fois en base, elles
     * sont gérées par le super-administrateur et jamais écrasées ici.
     *
     * À VALIDER PAR LE PROPRIÉTAIRE avant commercialisation : nombre
     * d'utilisateurs inclus, nombre de dépôts « Business » et prix. Les prix
     * restent null (non affichés, non facturables) tant qu'ils ne sont pas fixés.
     */
    'default_plans' => [
        'essential' => [
            'name' => 'Essentiel',
            'warehouse_limit' => 1,
            'included_users' => 2,
            'accounting_enabled' => false,
            'sort_order' => 10,
        ],
        'essential_accounting' => [
            'name' => 'Essentiel Compta',
            'warehouse_limit' => 1,
            'included_users' => 3,
            'accounting_enabled' => true,
            'sort_order' => 20,
        ],
        'business' => [
            'name' => 'Business',
            'warehouse_limit' => 5,
            'included_users' => 5,
            'accounting_enabled' => false,
            'sort_order' => 30,
        ],
        'business_accounting' => [
            'name' => 'Business Compta',
            'warehouse_limit' => 5,
            'included_users' => 8,
            'accounting_enabled' => true,
            'sort_order' => 40,
        ],
    ],

    /*
     * Page d'accueil publique. Coordonnées commerciales à fournir par le
     * propriétaire : tant qu'elles sont vides, le bouton correspondant est masqué.
     */
    'marketing' => [
        // Numéro WhatsApp au format international sans « + » (ex. 221771234567).
        'whatsapp' => env('DIABA_SALES_WHATSAPP'),
        'email' => env('DIABA_CONTACT_EMAIL', 'etiennesamake@gmail.com'),

        // Indexation par les moteurs de recherche : à activer en production
        // seulement, pour que dev.diaba.cloud n'apparaisse jamais dans Google.
        'indexable' => (bool) env('DIABA_SEARCH_INDEXING', false),

        // Témoignages réels fournis par le propriétaire (aucun témoignage
        // inventé) : ['quote' => '…', 'name' => '…', 'business' => '…'].
        'testimonials' => [],
    ],

];
