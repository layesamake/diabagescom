<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Le tenant est imposé par le contexte (BelongsToTenant) : créer les dépôts
 * dans TenantContext::runAs().
 *
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('DEP-###')),
            'name' => 'Dépôt '.fake()->city(),
        ];
    }
}
