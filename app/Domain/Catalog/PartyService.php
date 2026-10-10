<?php

namespace App\Domain\Catalog;

use App\Domain\Audit\AuditLog;
use App\Domain\Organization\Sequences;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Clients et fournisseurs : création (code automatique si non fourni),
 * modification et archivage, audités. Aucune suppression (docs/04).
 */
final class PartyService
{
    private const array FIELDS = ['code', 'name', 'phone', 'email', 'address_line', 'city', 'tax_number', 'payment_term_days', 'notes', 'credit_limit'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly AuditLog $audit,
        private readonly Sequences $sequences,
    ) {}

    /**
     * @template T of Customer|Supplier
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $data
     * @return T
     */
    public function create(string $class, array $data): Customer|Supplier
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($class, $data): Customer|Supplier {
            $party = new $class;
            $this->fill($party, $data);

            if (blank($party->code)) {
                $party->code = $class === Customer::class
                    ? $this->sequences->formatted('customer', 'C')
                    : $this->sequences->formatted('supplier', 'F');
            }

            $this->saveUnique($party);
            $this->audit->record($this->action($party, 'created'), $party, after: $party->only(self::FIELDS));

            return $party;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Customer|Supplier $party, array $data): Customer|Supplier
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($party, $data): Customer|Supplier {
            $before = $party->only(self::FIELDS);
            $this->fill($party, $data);

            if (! $party->isDirty()) {
                return $party;
            }

            $this->saveUnique($party);
            $after = $party->only(self::FIELDS);
            $this->audit->record($this->action($party, 'updated'), $party,
                before: array_intersect_key($before, array_diff_assoc($after, $before)),
                after: array_diff_assoc($after, $before));

            return $party;
        });
    }

    public function setArchived(Customer|Supplier $party, bool $archived): Customer|Supplier
    {
        $this->access->assertCanWrite($this->context->tenant());

        return DB::transaction(function () use ($party, $archived): Customer|Supplier {
            $party->archived_at = $archived ? now() : null;
            $party->active = ! $archived;
            $party->save();
            $this->audit->record($this->action($party, $archived ? 'archived' : 'restored'), $party);

            return $party;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(Customer|Supplier $party, array $data): void
    {
        foreach (self::FIELDS as $field) {
            if ($field === 'credit_limit' && ! $party instanceof Customer) {
                continue;
            }

            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                $party->setAttribute($field, is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value);
            }
        }

        $party->payment_term_days = (int) $party->getAttribute('payment_term_days');

        $code = $party->getAttribute('code');
        if (is_string($code)) {
            $party->code = mb_strtoupper($code);
        }
    }

    private function saveUnique(Customer|Supplier $party): void
    {
        try {
            $party->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => __('catalog.party.code_taken')]);
        }
    }

    private function action(Customer|Supplier $party, string $verb): string
    {
        return ($party instanceof Customer ? 'customer.' : 'supplier.').$verb;
    }
}
