<?php

namespace App\Console\Commands;

use App\Domain\Accounting\AccountingPoster;
use App\Domain\Tenancy\TenantContext;
use App\Models\AccountingSetting;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Comptabilise les opérations validées de chaque entreprise ayant activé la
 * comptabilité. Idempotent : peut être relancé à tout moment.
 */
#[Signature('diaba:accounting:post')]
#[Description('Comptabilise les opérations métier validées (idempotent)')]
class PostAccountingEntriesCommand extends Command
{
    public function handle(TenantContext $context): int
    {
        $tenantIds = AccountingSetting::query()->withoutGlobalScopes()->pluck('tenant_id');

        foreach (Tenant::query()->whereIn('id', $tenantIds)->get() as $tenant) {
            $result = $context->runAs($tenant, fn () => app(AccountingPoster::class)->postPending());

            if ($result['posted'] > 0 || $result['pending'] > 0) {
                $this->line("{$tenant->display_name}: {$result['posted']} écriture(s), {$result['pending']} en attente");
            }
        }

        return self::SUCCESS;
    }
}
