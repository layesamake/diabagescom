<?php

return [
    'warehouse_created' => 'Le dépôt « :name » a été créé.',
    'backup' => [
        'requested' => 'Sauvegarde en préparation. Elle sera prête dans quelques instants.',
        'already_pending' => 'Une sauvegarde est déjà en préparation.',
    ],
    'restore' => [
        'suffix' => '(restaurée le :date)',
        'requested' => 'Restauration en cours. Cela peut prendre quelques minutes.',
        'done' => 'Restauration terminée : l’entreprise « :name » a été créée.',
        'errors' => [
            'not_a_backup' => 'Ce fichier n’est pas une sauvegarde DIABA GESCOM.',
            'signature' => 'Cette sauvegarde n’a pas été créée par ce service ou a été modifiée : elle ne peut pas être restaurée.',
            'version' => 'Cette sauvegarde provient d’une version incompatible de DIABA GESCOM.',
            'corrupted' => 'La sauvegarde est abîmée ou incomplète : elle ne peut pas être restaurée.',
            'not_a_member' => 'Vous ne faisiez pas partie de l’entreprise sauvegardée : vous ne pouvez pas la restaurer.',
            'incomplete' => 'La restauration n’a pas pu reprendre toutes les données (:table). Rien n’a été créé.',
            'failed' => 'La restauration a échoué. Rien n’a été créé. Réessayez ou contactez le support.',
            'pending' => 'Une restauration est déjà en cours. Patientez quelques minutes.',
        ],
    ],
];
