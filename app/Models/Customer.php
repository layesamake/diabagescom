<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Client. credit_limit en XOF entier ; null = aucune vente à crédit autorisée.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address_line
 * @property string|null $city
 * @property string|null $tax_number
 * @property int|null $credit_limit
 * @property int $payment_term_days
 * @property string|null $notes
 * @property bool $active
 * @property CarbonImmutable|null $archived_at
 */
class Customer extends Model
{
    use BelongsToTenant, HasUuids;

    protected $attributes = ['active' => true, 'payment_term_days' => 0];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'integer',
            'payment_term_days' => 'integer',
            'active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }
}
