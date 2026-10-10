<?php

namespace App\Domain\Organization;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Compteurs par entreprise (codes clients, fournisseurs, codes-barres internes,
 * plus tard numéros de documents).
 *
 * La ligne du compteur est verrouillée (SELECT … FOR UPDATE) : deux créations
 * simultanées n'obtiennent jamais le même numéro. Doit être appelé dans la
 * transaction qui utilise le numéro, pour qu'une annulation libère le verrou
 * sans consommer de numéro.
 */
final class Sequences
{
    public function __construct(private readonly TenantContext $context) {}

    public function next(string $key): int
    {
        $tenantId = $this->context->tenantId();

        return DB::transaction(function () use ($tenantId, $key): int {
            DB::table('tenant_sequences')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'key' => $key,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $value = (int) DB::table('tenant_sequences')
                ->where('tenant_id', $tenantId)
                ->where('key', $key)
                ->lockForUpdate()
                ->value('next_value');

            DB::table('tenant_sequences')
                ->where('tenant_id', $tenantId)
                ->where('key', $key)
                ->update(['next_value' => $value + 1, 'updated_at' => now()]);

            return $value;
        });
    }

    /**
     * Code formaté, ex. formatted('customer', 'C') → « C-00042 ».
     */
    public function formatted(string $key, string $prefix, int $padding = 5): string
    {
        return sprintf('%s-%s', $prefix, str_pad((string) $this->next($key), $padding, '0', STR_PAD_LEFT));
    }

    /**
     * Numéro de document annuel, ex. document('sales:invoice', 'FA') → « FA-2026-00042 ».
     * Attribué seulement à la validation, dans la transaction qui valide.
     */
    public function document(string $key, string $prefix): string
    {
        $year = now()->year;

        return $this->formatted("{$key}:{$year}", "{$prefix}-{$year}");
    }
}
