<?php

/*
 * Modèle de plan comptable SYSCOHADA révisé (sous-ensemble adapté au commerce
 * général), copié dans chaque entreprise à l'activation de la comptabilité,
 * puis personnalisable.
 *
 * À FAIRE VALIDER PAR UN EXPERT-COMPTABLE avant toute mise en production
 * (docs/05) : comptes, mappages, règles de TVA et d'arrondi.
 *
 * party : comptes de tiers suivis en auxiliaire (client / fournisseur).
 * reconcilable : comptes de trésorerie pouvant être rapprochés (banque, caisse).
 */
return [
    'accounts' => [
        // Classe 1 — Ressources durables
        ['101', 'Capital social'],
        ['1301', 'Résultat net : bénéfice'],
        ['1309', 'Résultat net : perte'],
        ['121', 'Report à nouveau créditeur'],
        ['129', 'Report à nouveau débiteur'],
        ['162', 'Emprunts et dettes auprès des établissements de crédit'],

        // Classe 2 — Actif immobilisé
        ['244', 'Matériel et mobilier'],
        ['245', 'Matériel de transport'],
        ['284', 'Amortissements du matériel'],

        // Classe 3 — Stocks
        ['311', 'Marchandises'],

        // Classe 4 — Tiers
        ['401', 'Fournisseurs', 'party' => 'supplier'],
        ['4081', 'Fournisseurs, factures non parvenues'],
        ['409', 'Fournisseurs débiteurs, avances versées', 'party' => 'supplier'],
        ['411', 'Clients', 'party' => 'customer'],
        ['419', 'Clients créditeurs, avances reçues', 'party' => 'customer'],
        ['421', 'Personnel, rémunérations dues'],
        ['431', 'Sécurité sociale'],
        ['4431', 'État, TVA facturée sur ventes'],
        ['4452', 'État, TVA récupérable sur achats'],
        ['4441', 'État, TVA due'],
        ['4449', 'État, crédit de TVA à reporter'],
        ['4711', 'Débiteurs et créditeurs divers, compte d’attente'],

        // Classe 5 — Trésorerie
        ['513', 'Chèques à encaisser', 'reconcilable' => true],
        ['521', 'Banques', 'reconcilable' => true],
        ['5213', 'Monnaie électronique — Wave', 'reconcilable' => true],
        ['5214', 'Monnaie électronique — Orange Money', 'reconcilable' => true],
        ['5215', 'Cartes bancaires à encaisser', 'reconcilable' => true],
        ['571', 'Caisse', 'reconcilable' => true],
        ['585', 'Virements de fonds'],

        // Classe 6 — Charges
        ['601', 'Achats de marchandises'],
        ['6031', 'Variation des stocks de marchandises'],
        ['605', 'Autres achats'],
        ['618', 'Autres frais de transport'],
        ['622', 'Locations et charges locatives'],
        ['624', 'Entretien, réparations et maintenance'],
        ['626', 'Frais postaux et de télécommunications'],
        ['631', 'Frais bancaires'],
        ['638', 'Autres charges externes'],
        ['641', 'Impôts et taxes directs'],
        ['658', 'Charges diverses'],
        ['661', 'Rémunérations directes versées au personnel'],

        // Classe 7 — Produits
        ['701', 'Ventes de marchandises'],
        ['706', 'Services vendus'],
        ['707', 'Produits accessoires'],
        ['758', 'Produits divers'],
    ],

    'journals' => [
        ['VT', 'Ventes', 'sales'],
        ['AC', 'Achats', 'purchases'],
        ['CA', 'Caisse', 'cash'],
        ['BQ', 'Banque et monnaie électronique', 'bank'],
        ['IN', 'Inventaire et stock', 'inventory'],
        ['OD', 'Opérations diverses', 'misc'],
        ['AN', 'À-nouveaux', 'opening'],
    ],

    // Comptes utilisés par la comptabilisation automatique (modifiables par entreprise).
    'mappings' => [
        'customers' => '411',
        'suppliers' => '401',
        'goods_received_not_invoiced' => '4081',
        'sales_goods' => '701',
        'sales_services' => '706',
        'vat_collected' => '4431',
        'vat_deductible' => '4452',
        'inventory' => '311',
        'cost_of_sales' => '6031',
        'inventory_gain' => '6031',
        'inventory_loss' => '6031',
        'purchases_other' => '605',
        'opening_balance' => '4711',
        'cash_expense' => '638',
        'cash_income' => '758',
        'cash_shortage' => '658',
        'cash_overage' => '758',
        'treasury_cash' => '571',
        'treasury_wave' => '5213',
        'treasury_orange_money' => '5214',
        'treasury_card' => '5215',
        'treasury_transfer' => '521',
        'treasury_cheque' => '513',
        'result_profit' => '1301',
        'result_loss' => '1309',
    ],
];
