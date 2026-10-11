<?php

return [
    'cycles' => [
        'monthly' => 'mensuel',
        'yearly' => 'annuel',
    ],
    'providers' => [
        'sandbox' => 'Paiement de démonstration (bac à sable)',
        'transfer' => 'Virement bancaire',
        'wave' => 'Wave',
        'orange_money' => 'Orange Money',
        'card' => 'Carte bancaire',
    ],
    'errors' => [
        'no_price' => 'Le tarif de la formule « :plan » n’est pas encore fixé : contactez DIABA GESCOM.',
        'addon_unavailable' => 'Cette option n’est pas encore disponible à l’achat.',
        'open_invoice' => 'Une facture est déjà en attente de paiement : réglez-la ou annulez-la avant d’en créer une autre.',
        'not_open' => 'Cette facture n’est plus en attente de paiement.',
        'provider_unavailable' => 'Ce moyen de paiement n’est pas disponible.',
        'proof_required' => 'Indiquez la référence du justificatif de virement.',
        'not_transfer' => 'Ce paiement n’est pas un virement en attente de validation.',
        'already_paid' => 'Facture déjà réglée ou annulée : paiement à rembourser.',
        'amount_mismatch' => 'Montant reçu (:received XOF) différent du montant attendu (:expected XOF).',
        'reason_required' => 'Indiquez le motif (au moins 5 caractères).',
        'support_duration' => 'Durée d’accès support invalide (15 minutes à 4 heures).',
        'support_inactive' => 'Aucun accès support actif pour cette entreprise.',
    ],
    'saved' => [
        'invoice' => 'Facture :number créée.',
        'voided' => 'Facture annulée.',
        'transfer' => 'Effectuez le virement avec la référence :reference ; l’abonnement sera activé à réception, après vérification.',
        'pending' => 'Paiement en cours de confirmation par le prestataire. L’abonnement sera activé dès la confirmation.',
        'confirmed' => 'Virement validé : abonnement activé.',
        'support_granted' => 'Accès support ouvert jusqu’à :time.',
        'support_revoked' => 'Accès support fermé.',
        'sandbox_sent' => 'Simulation envoyée au webhook (:status).',
    ],
    'notices' => [
        'issued' => [
            'subject' => 'Facture :number — :company',
            'line' => 'Votre facture d’abonnement :number de :total XOF est disponible. Échéance : :date.',
        ],
        'due_soon' => [
            'subject' => 'Rappel : facture :number à régler avant le :date',
            'line' => 'La facture :number de :total XOF arrive à échéance le :date.',
        ],
        'due' => [
            'subject' => 'Facture :number à régler aujourd’hui',
            'line' => 'La facture :number de :total XOF arrive à échéance aujourd’hui.',
        ],
        'overdue' => [
            'subject' => 'Facture :number en retard',
            'line' => 'La facture :number de :total XOF n’est pas réglée (échéance du :date). Sans règlement, l’accès passera en lecture seule à la fin de la période.',
        ],
        'action' => 'Voir mon abonnement',
        'footer' => 'Aucune donnée sensible n’est contenue dans ce message.',
    ],
];
