<?php

namespace App\Domain\Catalog;

use App\Models\TaxRate;
use App\Models\Unit;

/**
 * Valeurs de départ d'une nouvelle entreprise (docs/01 : TVA 18 % par défaut).
 * Idempotent ; à exécuter dans le contexte de l'entreprise.
 *
 * Les taux réglementaires définitifs restent à valider par un expert-comptable.
 */
final class SeedTenantCatalogDefaults
{
    private const array TAXES = [
        ['name' => 'TVA 18 %', 'rate_basis_points' => 1800, 'is_default' => true],
        ['name' => 'Exonéré (0 %)', 'rate_basis_points' => 0, 'is_default' => false],
    ];

    private const array UNITS = [
        ['code' => 'U', 'name' => 'Pièce', 'decimals_allowed' => false],
        ['code' => 'KG', 'name' => 'Kilogramme', 'decimals_allowed' => true],
        ['code' => 'L', 'name' => 'Litre', 'decimals_allowed' => true],
        ['code' => 'M', 'name' => 'Mètre', 'decimals_allowed' => true],
        ['code' => 'CTN', 'name' => 'Carton', 'decimals_allowed' => false],
        ['code' => 'SAC', 'name' => 'Sac', 'decimals_allowed' => false],
        ['code' => 'PQT', 'name' => 'Paquet', 'decimals_allowed' => false],
    ];

    public function __invoke(): void
    {
        foreach (self::TAXES as $tax) {
            TaxRate::query()->firstOrCreate(['name' => $tax['name']], $tax);
        }

        foreach (self::UNITS as $unit) {
            Unit::query()->firstOrCreate(['code' => $unit['code']], $unit);
        }
    }
}
