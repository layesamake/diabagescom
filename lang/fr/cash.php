<?php

return [
    'default_register' => 'Caisse principale',
    'errors' => [
        'register_exists' => 'Une caisse porte déjà ce nom dans ce dépôt.',
        'register_inactive' => 'Cette caisse est désactivée.',
        'negative_amount' => 'Le montant ne peut pas être négatif.',
        'positive_amount' => 'Le montant doit être positif.',
        'already_open' => 'La caisse « :register » est déjà ouverte.',
        'session_closed' => 'Cette caisse est fermée : ouvrez-la avant d’encaisser.',
        'idempotency_conflict' => 'Cet identifiant d’opération a déjà été utilisé pour une autre opération de caisse. Rechargez la page et recommencez.',
        'not_your_session' => 'Cette session de caisse a été ouverte par un autre utilisateur.',
        'session_required' => 'Ouvrez une caisse avant de vendre.',
        'other_warehouse' => 'Cette caisse appartient à un autre dépôt.',
        'insufficient_cash' => 'Espèces insuffisantes dans la caisse (:available XOF disponibles).',
        'invalid_type' => 'Type d’opération de caisse inconnu.',
        'reason_required' => 'Indiquez le libellé et le motif (au moins 3 caractères).',
        'difference_reason' => 'Écart de :difference XOF : expliquez-le avant de fermer la caisse.',
    ],
    'saved' => [
        'register' => 'Caisse créée.',
        'opened' => 'Caisse ouverte.',
        'closed' => 'Caisse fermée.',
        'expense' => 'Dépense enregistrée.',
        'income' => 'Recette enregistrée.',
    ],
];
