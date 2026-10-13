<?php

return [
    'saved' => 'Préférences de notification enregistrées.',
    'digest' => [
        'subject' => ':company — points à traiter',
        'intro' => 'Voici ce qui demande votre attention dans :company :',
        'action' => 'Ouvrir l’accueil',
        'footer' => 'Vous recevez ce résumé car vous l’avez activé dans Paramètres › Notifications. Il ne contient aucun montant ni nom.',
        'lines' => [
            'low_stock' => ':count article(s) sous le seuil d’alerte',
            'transfers_to_receive' => ':count transfert(s) à réceptionner',
            'overdue_invoices' => ':count facture(s) client échue(s) non soldée(s)',
            'cash_to_close' => ':count caisse(s) ouverte(s) depuis hier à fermer',
            'sync_issues' => ':count vente(s) hors connexion à revoir',
            'saas_invoice' => 'Une facture d’abonnement est à régler',
        ],
    ],
];
