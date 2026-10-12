<?php

return [
    'subscription_updated' => 'Abonnement mis à jour. L’action est enregistrée dans le journal d’audit de l’entreprise.',
    'plan_updated' => 'Formule « :name » mise à jour.',
    'tenant_created' => 'Entreprise créée. Une invitation a été préparée pour :email.',
    'create' => [
        'invited_role' => 'Administrateur de l’entreprise',
        'fields' => [
            'owner_name' => 'nom de l’administrateur',
            'owner_email' => 'adresse e-mail de l’administrateur',
        ],
        'errors' => [
            'platform_account' => 'Cette adresse appartient déjà à un compte propriétaire DIABA.',
        ],
    ],
    'fields' => [
        'reason' => 'motif',
        'plan' => 'formule',
        'days' => 'nombre de jours',
        'billing_cycle' => 'périodicité',
    ],
    'plan_fields' => [
        'name' => 'nom',
        'warehouse_limit' => 'nombre de dépôts',
        'included_users' => 'utilisateurs inclus',
        'price_monthly' => 'prix mensuel',
        'price_yearly' => 'prix annuel',
    ],
];
