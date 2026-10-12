<?php

return [
    'warehouse_created' => 'Le dépôt « :name » a été créé.',
    'backup' => [
        'requested' => 'Sauvegarde en préparation. Elle sera prête dans quelques instants.',
        'already_pending' => 'Une sauvegarde est déjà en préparation.',
    ],
    'team' => [
        'invited' => 'Invitation envoyée à :email.',
        'updated' => 'Accès de :name mis à jour.',
        'suspended' => ':name ne peut plus se connecter à l’entreprise.',
        'reactivated' => ':name peut de nouveau se connecter.',
        'resent' => 'Invitation renvoyée à :email.',
        'errors' => [
            'already_member' => 'Cette personne fait déjà partie de l’entreprise.',
            'warehouse_required' => 'Choisissez au moins un dépôt auquel cette personne aura accès.',
            'role' => 'Choisissez un rôle.',
            'self' => 'Vous ne pouvez pas modifier votre propre accès.',
            'last_admin' => 'L’entreprise doit garder au moins un administrateur actif.',
        ],
        'mail' => [
            'subject' => 'Invitation à rejoindre :company sur DIABA GESCOM',
            'greeting' => 'Bonjour,',
            'intro' => ':name vous invite à rejoindre :company sur DIABA GESCOM, en tant que « :role ».',
            'new_account' => 'Pour commencer, choisissez votre mot de passe :',
            'set_password' => 'Choisir mon mot de passe',
            'expires' => 'Ce lien est valable :minutes minutes. Passé ce délai, demandez un nouvel envoi à votre administrateur ou utilisez « Mot de passe oublié ».',
            'existing_account' => 'Vous avez déjà un compte : connectez-vous, puis choisissez l’entreprise en haut de l’écran.',
            'login' => 'Me connecter',
        ],
    ],
    'cloud' => [
        'connected' => 'Google Drive est relié (:email). Vos sauvegardes y seront envoyées automatiquement.',
        'disconnected' => 'Google Drive est déconnecté. L’accès a été retiré chez Google.',
        'saved' => 'Fréquence des sauvegardes enregistrée.',
        'sending' => 'Sauvegarde en préparation, puis envoi vers Google Drive.',
        'errors' => [
            'not_configured' => 'La connexion à Google Drive n’est pas encore disponible sur ce service.',
            'denied' => 'La connexion à Google Drive a été annulée.',
            'failed' => 'La connexion à Google Drive a échoué. Réessayez et acceptez l’accès aux fichiers.',
            'not_connected' => 'Aucun Google Drive n’est relié.',
            'revoked' => 'L’accès à Google Drive a été retiré depuis votre compte Google : reconnectez-le.',
            'upload' => 'L’envoi vers Google Drive a échoué ; la sauvegarde reste téléchargeable ici.',
        ],
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
