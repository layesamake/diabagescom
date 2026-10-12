<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Formule d'abonnement, gérée par la plateforme (pas de tenant).
 * Montants en XOF entiers ; null = tarif non encore validé.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property int|null $warehouse_limit
 * @property int $included_users
 * @property bool $accounting_enabled
 * @property bool $online_store_enabled
 * @property int|null $price_monthly
 * @property int|null $price_yearly
 * @property bool $active
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Plan extends Model
{
    use HasUuids;

    protected $fillable = [
        'code', 'name', 'warehouse_limit', 'included_users', 'accounting_enabled', 'online_store_enabled',
        'price_monthly', 'price_yearly', 'active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'warehouse_limit' => 'integer',
            'included_users' => 'integer',
            'accounting_enabled' => 'boolean',
            'online_store_enabled' => 'boolean',
            'price_monthly' => 'integer',
            'price_yearly' => 'integer',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
