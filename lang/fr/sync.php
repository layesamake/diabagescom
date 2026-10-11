<?php

return [
    'default_label' => 'Appareil',
    'errors' => [
        'device_revoked' => 'Cet appareil n’est plus autorisé à synchroniser. Reconnectez-vous ou demandez à un administrateur.',
        'hash_mismatch' => 'Cette opération a déjà été envoyée avec un contenu différent : elle n’a pas été retraitée.',
        'unknown_type' => 'Type d’opération hors connexion inconnu.',
        'forbidden' => 'Vous n’avez plus les droits pour cette opération.',
        'product_archived' => 'Un article de cette vente a été archivé entre-temps : vérifiez la vente avant de la renvoyer.',
        'price_changed' => 'Le prix d’un article a changé depuis la dernière synchronisation : vérifiez la vente avant de la renvoyer.',
    ],
    'saved' => [
        'revoked' => 'Appareil « :device » révoqué.',
    ],
];
