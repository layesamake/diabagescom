<?php

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\InvalidAccountingOperation;
use App\Domain\Audit\AuditLog;
use App\Domain\Subscriptions\FeatureAccess;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\ChartAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plan comptable et comptes de paramétrage (personnalisation contrôlée du modèle SYSCOHADA).
 */
class ChartController extends Controller
{
    public function index(TenantContext $context): Response
    {
        Gate::authorize('accounting.view');

        return Inertia::render('accounting/Accounts', [
            'accounts' => ChartAccount::query()->orderBy('code')->get(['id', 'code', 'name', 'class', 'party_type', 'reconcilable', 'active']),
            'mappings' => DB::table('account_mappings')->join('chart_accounts', 'chart_accounts.id', '=', 'account_mappings.account_id')
                ->where('account_mappings.tenant_id', $context->tenantId())
                ->orderBy('account_mappings.key')
                ->get(['account_mappings.key', 'chart_accounts.code'])
                ->map(fn ($m) => ['key' => (string) $m->key, 'code' => (string) $m->code]),
            'can' => ['settings' => Gate::allows('accounting.settings')],
        ]);
    }

    public function store(Request $request, TenantContext $context, FeatureAccess $access, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('accounting.settings');
        $access->assertCanWrite($context->tenant());

        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[1-9][0-9A-Z]{1,19}$/'],
            'name' => ['required', 'string', 'max:160'],
            'party_type' => ['nullable', Rule::in(['customer', 'supplier'])],
            'reconcilable' => ['boolean'],
        ], ['code.regex' => __('accounting.errors.account_class')], ['code' => 'numéro de compte', 'name' => 'intitulé']);

        if (ChartAccount::query()->where('code', $data['code'])->exists()) {
            throw new InvalidAccountingOperation(__('accounting.errors.account_exists'));
        }

        $account = ChartAccount::query()->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'class' => (int) substr((string) $data['code'], 0, 1),
            'party_type' => $data['party_type'] ?? null,
            'reconcilable' => (bool) ($data['reconcilable'] ?? false),
        ]);
        $audit->record('accounting.account_created', $account, after: $account->only(['code', 'name']));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.saved.account')]);

        return back(303);
    }

    public function update(Request $request, ChartAccount $account, TenantContext $context, FeatureAccess $access, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('accounting.settings');
        $access->assertCanWrite($context->tenant());
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'active' => ['boolean']], attributes: ['name' => 'intitulé']);

        // Un compte utilisé par le paramétrage reste actif (docs/05).
        if (! ($data['active'] ?? true) && DB::table('account_mappings')->where('account_id', $account->id)->exists()) {
            throw new InvalidAccountingOperation(__('accounting.errors.missing_mapping', ['key' => $account->code]));
        }

        $before = $account->only(['name', 'active']);
        $account->update(['name' => $data['name'], 'active' => (bool) ($data['active'] ?? true)]);
        $audit->record('accounting.account_updated', $account, before: $before, after: $account->only(['name', 'active']));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.saved.account')]);

        return back(303);
    }

    public function mapping(Request $request, TenantContext $context, FeatureAccess $access, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('accounting.settings');
        $access->assertCanWrite($context->tenant());
        $data = $request->validate(['key' => ['required', 'string', Rule::in(array_keys((array) config('syscohada.mappings')))], 'code' => ['required', 'string']]);

        /** @var ChartAccount|null $account */
        $account = ChartAccount::query()->where('code', $data['code'])->where('active', true)->first();

        if ($account === null) {
            throw new InvalidAccountingOperation(__('accounting.errors.unknown_account', ['code' => $data['code']]));
        }

        DB::table('account_mappings')->updateOrInsert(
            ['tenant_id' => $context->tenantId(), 'key' => $data['key']],
            ['account_id' => $account->id, 'updated_at' => now(), 'created_at' => now()],
        );
        $audit->record('accounting.mapping_changed', $account, after: ['key' => $data['key'], 'code' => $account->code]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('accounting.saved.mapping')]);

        return back(303);
    }
}
