<?php

namespace App\Domain\Accounting;

use App\Domain\Audit\AuditLog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\AccountingJournal;
use App\Models\AccountingSetting;
use App\Models\ChartAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Activation de la comptabilité d'une entreprise : copie du modèle de plan
 * SYSCOHADA (config/syscohada.php), journaux, comptes de mappage et premier
 * exercice. Idempotent : relancer ne duplique rien et n'écrase aucune
 * personnalisation.
 *
 * Seules les opérations datées à partir de `posting_starts_on` sont
 * comptabilisées automatiquement ; l'historique antérieur relève des à-nouveaux
 * saisis par le comptable.
 */
final class AccountingSetup
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FeatureAccess $access,
        private readonly FiscalCalendar $calendar,
        private readonly AuditLog $audit,
    ) {}

    public function isInitialized(): bool
    {
        return AccountingSetting::query()->exists();
    }

    public function initialize(User $actor, CarbonImmutable $fiscalYearStart, ?CarbonImmutable $postingStartsOn = null): AccountingSetting
    {
        $tenant = $this->context->tenant();
        $this->access->assertCanWrite($tenant);

        if (! $this->access->hasFeature($tenant, FeatureAccess::FEATURE_ACCOUNTING)) {
            throw new InvalidAccountingOperation(__('accounting.errors.plan'));
        }

        return DB::transaction(function () use ($actor, $fiscalYearStart, $postingStartsOn): AccountingSetting {
            /** @var array{accounts: list<array<int|string, mixed>>, journals: list<array{0: string, 1: string, 2: string}>, mappings: array<string, string>} $template */
            $template = config('syscohada');

            foreach ($template['accounts'] as $row) {
                ChartAccount::query()->firstOrCreate(['code' => (string) $row[0]], [
                    'name' => (string) $row[1],
                    'class' => (int) substr((string) $row[0], 0, 1),
                    'party_type' => $row['party'] ?? null,
                    'reconcilable' => (bool) ($row['reconcilable'] ?? false),
                ]);
            }

            foreach ($template['journals'] as [$code, $name, $type]) {
                AccountingJournal::query()->firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type]);
            }

            $accounts = ChartAccount::query()->pluck('id', 'code');
            $tenantId = $this->context->tenantId();

            foreach ($template['mappings'] as $key => $code) {
                DB::table('account_mappings')->insertOrIgnore([
                    'tenant_id' => $tenantId, 'key' => $key, 'account_id' => $accounts[$code],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $this->calendar->ensureFiscalYear($fiscalYearStart);

            /** @var AccountingSetting $settings */
            $settings = AccountingSetting::query()->firstOrCreate([], [
                'posting_starts_on' => ($postingStartsOn ?? CarbonImmutable::today())->toDateString(),
                'initialized_by' => $actor->id,
            ]);

            $this->audit->record('accounting.initialized', $settings, after: [
                'fiscal_year_start' => $fiscalYearStart->toDateString(),
                'posting_starts_on' => $settings->posting_starts_on->toDateString(),
            ], actor: $actor);

            return $settings;
        });
    }

    /**
     * Trace la validation du paramétrage par l'expert-comptable (docs/05).
     */
    public function recordExpertValidation(string $expertName, User $actor): AccountingSetting
    {
        $this->access->assertCanWrite($this->context->tenant());

        /** @var AccountingSetting $settings */
        $settings = AccountingSetting::query()->firstOrFail();
        $settings->expert_name = trim($expertName);
        $settings->expert_validated_at = now()->toImmutable();
        $settings->expert_validated_by = $actor->id;
        $settings->save();

        $this->audit->record('accounting.expert_validated', $settings, after: ['expert' => $settings->expert_name], actor: $actor);

        return $settings;
    }
}
