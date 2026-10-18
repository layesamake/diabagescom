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
     * Restructuration validée le 17/10/2026 : 3 formules, comptabilité et
     * boutique en ligne incluses partout (activables par l'administrateur
     * de l'entreprise dans le backoffice, désactivées par défaut).
     */
    'default_plans' => [
        'essential' => [
            'name' => 'Essentiel',
            'warehouse_limit' => 1,
            'included_users' => 2,
            'accounting_enabled' => true,
            'online_store_enabled' => true,
            'price_monthly' => 5000,
            'price_yearly' => 51000,
            'sort_order' => 10,
        ],
        'business' => [
            'name' => 'Business',
            'warehouse_limit' => 5,
            'included_users' => 5,
            'accounting_enabled' => true,
            'online_store_enabled' => true,
            'price_monthly' => 12500,
            'price_yearly' => 120000,
            'sort_order' => 30,
        ],
        'business_accounting' => [
            'name' => 'Premium',
            'warehouse_limit' => 5,
            'included_users' => 8,
            'accounting_enabled' => true,
            'online_store_enabled' => true,
            'price_monthly' => 15000,
            'price_yearly' => 135000,
            'sort_order' => 40,
        ],
    ],

    /*
     * Page d'accueil publique. Coordonnées commerciales à fournir par le
     * propriétaire : tant qu'elles sont vides, le bouton correspondant est masqué.
     */
    'marketing' => [
        // Numéro WhatsApp au format international sans « + » (ex. 221771234567).
        'whatsapp' => env('DIABA_SALES_WHATSAPP', '221767642727'),
        'email' => env('DIABA_CONTACT_EMAIL', 'etiennesamake@gmail.com'),

        // Indexation par les moteurs de recherche : à activer en production
        // seulement, pour que dev.diaba.cloud n'apparaisse jamais dans Google.
        'indexable' => (bool) env('DIABA_SEARCH_INDEXING', false),

        // Témoignages réels fournis par le propriétaire (aucun témoignage
        // inventé) : ['quote' => '…', 'name' => '…', 'business' => '…'].
        'testimonials' => [],
    ],

    // Boutique en ligne publique : limite anti-spam par numéro de téléphone et par heure.
    'storefront' => [
        'orders_per_phone_per_hour' => 5,
    ],

    /*
     * Mode simple (interface en images pour les vendeurs qui lisent mal).
     *
     * Tant que ce drapeau est faux, les routes /simple et la bascule
     * n'existent pas : un lot livré sur dev ne peut pas apparaître chez un
     * client en production avant validation.
     */
    'simple_mode' => [
        'enabled' => (bool) env('DIABA_SIMPLE_MODE', false),
    ],

    /*
     * Filigrane DIABA GESCOM sur les documents imprimés et partagés.
     *
     * Activé sur les environnements d'essai pour protéger l'application :
     * le logo apparaît en filigrane diagonal sur les PDF A4, les tickets
     * thermiques et les tickets navigateur.
     */
    'trial_watermark' => [
        'enabled' => (bool) env('DIABA_TRIAL_WATERMARK', false),
    ],
];
