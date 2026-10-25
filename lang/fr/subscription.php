<?php

return [
    'errors' => [
        'read_only' => 'Votre période d’essai ou d’abonnement est terminée : l’application est en lecture seule. Régularisez votre abonnement pour continuer à enregistrer des opérations.',
        'suspended' => 'L’accès à cette entreprise est suspendu. Consultez la page Abonnement pour le rétablir.',
        'feature_not_included' => 'La fonction « :feature » n’est pas incluse dans votre formule.',
        'limit_warehouses' => 'Votre formule permet :limit dépôt(s) actif(s). Passez à une formule supérieure ou ajoutez une option pour en créer davantage.',
        'limit_users' => 'Votre formule permet :limit utilisateur(s). Passez à une formule supérieure ou ajoutez une option pour en inviter davantage.',
        'feature_has_data' => [
            'accounting' => 'Impossible de désactiver la comptabilité : des écritures existent déjà. Elles restent consultables, mais le module ne peut pas être désactivé.',
            'online_store' => 'Impossible de désactiver la boutique en ligne : des commandes existent déjà. Elles restent consultables, mais le module ne peut pas être désactivé.',
        ],
    ],
    'features' => [
        'accounting' => 'Comptabilité',
        'online_store' => 'Boutique en ligne',
    ],
    'feature_activated' => 'Fonction activée.',
    'feature_deactivated' => 'Fonction désactivée.',
    'notices' => [
        'ending' => [
            'subject' => 'Votre essai DIABA GESCOM se termine dans :days jour(s)',
            'line' => 'L’essai gratuit de :company se termine le :date. Ensuite, l’application passera en lecture seule pendant :read_only_days jours, puis l’accès sera suspendu si l’abonnement n’est pas réglé. Vos données sont conservées.',
        ],
        'ended' => [
            'subject' => 'Votre essai DIABA GESCOM est terminé',
            'line' => 'L’essai gratuit de :company est terminé depuis le :date. L’application est en lecture seule pendant :read_only_days jours ; l’accès sera ensuite suspendu si l’abonnement n’est pas réglé. Vos données sont conservées.',
        ],
        'action' => 'Voir mon abonnement',
        'footer' => 'Pour toute question, répondez simplement à cet e-mail.',
    ],
];
