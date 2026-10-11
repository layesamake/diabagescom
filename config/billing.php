<?php

/*
 * Facturation SaaS DIABA GESCOM (EPIC-08).
 *
 * AUCUN PAIEMENT RÉEL n'est activé : seuls le bac à sable (simulation signée)
 * et le virement validé manuellement par le super-administrateur sont
 * disponibles. Wave, Orange Money et la carte nécessitent un contrat et des
 * clés officielles du prestataire, et une validation explicite du propriétaire
 * avant activation (jamais de clé de production dans le dépôt).
 */
return [

    // Délai de paiement d'une facture d'abonnement.
    'due_days' => 7,

    // Facture de renouvellement émise ce nombre de jours avant la fin de période.
    'renewal_days_before' => 7,

    // Relances : jours avant (négatif) ou après l'échéance.
    'reminders' => [-3, 0, 3],

    // Tolérance d'horodatage des webhooks (secondes) : un vieux message rejoué est refusé.
    'webhook_tolerance' => 300,

    // Comptes de virement affichés au client (à renseigner par le propriétaire).
    'transfer_instructions' => env('BILLING_TRANSFER_INSTRUCTIONS'),

    'providers' => [
        // Simulation complète du parcours (paiement, webhook signé, rejeu) sans argent réel.
        'sandbox' => [
            'enabled' => (bool) env('BILLING_SANDBOX_ENABLED', env('APP_ENV') !== 'production'),
            'secret' => env('BILLING_SANDBOX_SECRET'),
        ],
        // Prestataires réels : désactivés tant que le propriétaire n'a pas validé
        // le contrat et fourni des clés (variables d'environnement uniquement).
        'wave' => ['enabled' => false],
        'orange_money' => ['enabled' => false],
        'card' => ['enabled' => false],
    ],
];
