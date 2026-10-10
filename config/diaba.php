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

];
