<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLog;
use App\Domain\Subscriptions\SubscriptionLifecycle;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantScope;
use App\Models\PaymentProviderEvent;
use App\Models\Plan;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Facturation de l'abonnement DIABA GESCOM (docs/06 « Paiements SaaS »).
 *
 * - L'état de l'abonnement ne change qu'après un paiement VÉRIFIÉ : webhook
 *   signé du prestataire, ou virement validé (avec justificatif) par le
 *   super-administrateur. Le retour du navigateur ne prouve rien.
 * - Idempotence : événement de prestataire dédupliqué ; au plus un paiement
 *   réussi par facture (index unique) ; un second paiement d'une facture déjà
 *   réglée passe « à revoir » (remboursement) sans prolonger l'abonnement.
 */
final class SaasBilling
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Lignes et total d'une commande (formule + options), ou refus si un tarif manque.
     *
     * @param  array<string, int>  $addons  code => quantité
     * @return list<array{code: string, label: string, quantity: int, unit_price: int, amount: int}>
     */
    public function quote(Plan $plan, string $cycle, array $addons = []): array
    {
        $price = $cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly;

        if (! in_array($cycle, ['monthly', 'yearly'], true) || ! $plan->active || $price === null || $price <= 0) {
            throw new InvalidBillingOperation(__('billing.errors.no_price', ['plan' => $plan->name]));
        }

        $lines = [['code' => 'plan:'.$plan->code, 'label' => $plan->name.' — '.__("billing.cycles.{$cycle}"), 'quantity' => 1, 'unit_price' => $price, 'amount' => $price]];

        foreach ($addons as $code => $quantity) {
            if ($quantity <= 0) {
                continue;
            }

            $config = config("diaba.addons.{$code}");
            $unit = is_array($config) ? ($config[$cycle === 'yearly' ? 'price_yearly' : 'price_monthly'] ?? null) : null;

            if (! is_int($unit) || $unit <= 0 || $quantity > 100) {
                throw new InvalidBillingOperation(__('billing.errors.addon_unavailable'));
            }

            $lines[] = ['code' => $code, 'label' => (string) $config['label'], 'quantity' => $quantity, 'unit_price' => $unit, 'amount' => $unit * $quantity];
        }

        return $lines;
    }

    /**
     * @param  array<string, int>  $addons
     */
    public function createInvoice(Tenant $tenant, Plan $plan, string $cycle, array $addons, ?User $actor): SaasInvoice
    {
        $lines = $this->quote($plan, $cycle, $addons);

        try {
            return DB::transaction(function () use ($tenant, $plan, $cycle, $lines, $actor): SaasInvoice {
                $sequence = (int) DB::selectOne('SELECT nextval(\'saas_invoice_number_seq\') AS n')->n;

                $invoice = new SaasInvoice;
                $invoice->forceFill([
                    'tenant_id' => $tenant->id,
                    'number' => sprintf('DG-%d-%06d', now()->year, $sequence),
                    'plan_id' => $plan->id,
                    'billing_cycle' => $cycle,
                    'lines' => $lines,
                    'total' => array_sum(array_column($lines, 'amount')),
                    'due_on' => CarbonImmutable::today()->addDays((int) config('billing.due_days', 7))->toDateString(),
                    'created_by' => $actor?->id,
                ]);
                $this->context->runAs($tenant, fn () => $invoice->save());

                $this->audit->record('billing.invoice_created', $invoice, after: ['number' => $invoice->number, 'total' => $invoice->total, 'plan' => $plan->code, 'cycle' => $cycle], actor: $actor, tenant: $tenant);

                return $invoice;
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidBillingOperation(__('billing.errors.open_invoice'));
        }
    }

    public function voidInvoice(SaasInvoice $invoice, string $reason, User $actor): SaasInvoice
    {
        return DB::transaction(function () use ($invoice, $reason, $actor): SaasInvoice {
            $invoice = $this->lockInvoice($invoice->id);

            if ($invoice->status !== SaasInvoice::OPEN) {
                throw new InvalidBillingOperation(__('billing.errors.not_open'));
            }

            SaasPayment::query()->withoutGlobalScope(TenantScope::class)->where('invoice_id', $invoice->id)
                ->whereIn('status', [SaasPayment::PENDING, SaasPayment::MANUAL_REVIEW])->update(['status' => SaasPayment::CANCELLED, 'updated_at' => now()]);

            $invoice->status = SaasInvoice::VOID;
            $invoice->voided_by = $actor->id;
            $invoice->voided_at = now()->toImmutable();
            $invoice->void_reason = trim($reason);
            $invoice->save();

            $this->audit->record('billing.invoice_voided', $invoice, metadata: ['reason' => trim($reason)], actor: $actor, tenant: $this->tenantOf($invoice));

            return $invoice;
        });
    }

    /**
     * Démarre un paiement : redirection vers le prestataire, ou référence de virement.
     */
    public function startPayment(SaasInvoice $invoice, string $provider, ?User $actor, string $idempotencyKey): SaasPayment
    {
        /** @var SaasPayment|null $existing */
        $existing = SaasPayment::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $invoice->tenant_id)->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($invoice->status !== SaasInvoice::OPEN) {
            throw new InvalidBillingOperation(__('billing.errors.not_open'));
        }

        $payment = new SaasPayment;
        $payment->forceFill([
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'provider' => $provider,
            'amount' => $invoice->total,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $actor?->id,
        ]);

        if ($provider === 'transfer') {
            // Virement : référence unique à indiquer, validation manuelle du super-administrateur.
            $payment->external_reference = 'VIR-'.$invoice->number.'-'.Str::upper(Str::random(4));
            $payment->status = SaasPayment::MANUAL_REVIEW;
        } else {
            $gateway = app(ProviderRegistry::class)->get($provider);
            $checkout = $gateway->checkout($payment, $invoice);
            $payment->external_reference = $checkout['reference'];
            $payment->checkout_url = $checkout['checkout_url'];
        }

        $this->context->runAs($this->tenantOf($invoice), fn () => $payment->save());
        $this->audit->record('billing.payment_started', $payment, after: ['provider' => $provider, 'reference' => $payment->external_reference, 'amount' => $payment->amount],
            actor: $actor, tenant: $this->tenantOf($invoice));

        return $payment;
    }

    /**
     * Webhook d'un prestataire. Réponse : [code HTTP, statut].
     *
     * @return array{0: int, 1: string}
     */
    public function handleWebhook(string $provider, Request $request): array
    {
        $gateway = app(ProviderRegistry::class)->find($provider);
        $event = $gateway?->parseWebhook($request);

        if ($gateway === null || $event === null) {
            // Signature invalide : tracé (sans la charge utile brute) puis refusé.
            PaymentProviderEvent::query()->create([
                'provider' => mb_substr($provider, 0, 24),
                'external_event_id' => 'invalid-'.Str::uuid(),
                'signature_valid' => false,
                'payload' => ['ip' => $request->ip()],
                'status' => 'rejected',
                'error' => 'invalid_signature',
            ]);

            return [401, 'invalid_signature'];
        }

        if (PaymentProviderEvent::query()->where('provider', $provider)->where('external_event_id', $event->eventId)->exists()) {
            return [200, 'duplicate']; // rejeu : déjà traité, rien n'est refait
        }

        try {
            // Point de sauvegarde : un doublon concurrent n'annule pas une transaction englobante.
            /** @var PaymentProviderEvent $record */
            $record = DB::transaction(fn () => PaymentProviderEvent::query()->create([
                'provider' => $provider,
                'external_event_id' => $event->eventId,
                'event_type' => $event->type,
                'signature_valid' => true,
                'payload' => $event->payload,
                'status' => 'received',
            ]));
        } catch (UniqueConstraintViolationException) {
            return [200, 'duplicate'];
        }

        [$status, $error, $paymentId] = DB::transaction(function () use ($provider, $event): array {
            /** @var SaasPayment|null $payment */
            $payment = SaasPayment::query()->withoutGlobalScope(TenantScope::class)
                ->where('provider', $provider)->where('external_reference', $event->reference)->lockForUpdate()->first();

            if ($payment === null) {
                return ['ignored', 'unknown_reference', null];
            }

            if (! $event->succeeded) {
                if ($payment->status === SaasPayment::PENDING) {
                    $payment->status = SaasPayment::FAILED;
                    $payment->failure_reason = $event->type;
                    $payment->save();
                }

                return ['processed', null, $payment->id];
            }

            if ($event->amount !== $payment->amount) {
                $payment->status = SaasPayment::REQUIRES_REVIEW;
                $payment->failure_reason = __('billing.errors.amount_mismatch', ['expected' => $payment->amount, 'received' => $event->amount]);
                $payment->save();

                return ['processed', 'amount_mismatch', $payment->id];
            }

            $this->settle($payment, null);

            return ['processed', null, $payment->id];
        });

        $record->update(['status' => $status, 'error' => $error, 'payment_id' => $paymentId, 'processed_at' => now()]);

        return [200, $status];
    }

    /**
     * Virement reçu sur le compte : validation par le super-administrateur avec justificatif.
     */
    public function confirmTransfer(SaasPayment $payment, string $proofReference, User $actor): SaasPayment
    {
        if (mb_strlen(trim($proofReference)) < 3) {
            throw new InvalidBillingOperation(__('billing.errors.proof_required'));
        }

        return DB::transaction(function () use ($payment, $proofReference, $actor): SaasPayment {
            /** @var SaasPayment $locked */
            $locked = SaasPayment::query()->withoutGlobalScope(TenantScope::class)->lockForUpdate()->findOrFail($payment->id);

            if ($locked->provider !== 'transfer' || ! in_array($locked->status, [SaasPayment::MANUAL_REVIEW, SaasPayment::SUCCEEDED], true)) {
                throw new InvalidBillingOperation(__('billing.errors.not_transfer'));
            }

            $locked->proof_reference = trim($proofReference);

            return $this->settle($locked, $actor);
        });
    }

    /**
     * Applique un paiement vérifié (dans la transaction appelante, paiement verrouillé).
     */
    private function settle(SaasPayment $payment, ?User $verifier): SaasPayment
    {
        if ($payment->status === SaasPayment::SUCCEEDED) {
            return $payment;
        }

        $invoice = $this->lockInvoice($payment->invoice_id);

        if ($invoice->status !== SaasInvoice::OPEN) {
            // Facture déjà payée (autre moyen) ou annulée : aucun nouvel effet, remboursement à traiter.
            $payment->status = SaasPayment::REQUIRES_REVIEW;
            $payment->failure_reason = __('billing.errors.already_paid');
            $payment->save();
            $this->audit->record('billing.payment_requires_review', $payment, metadata: ['invoice' => $invoice->number], actor: $verifier, tenant: $this->tenantOf($invoice));

            return $payment;
        }

        $period = $this->lifecycle->applyPaidInvoice($invoice, $verifier);

        $payment->status = SaasPayment::SUCCEEDED;
        $payment->verified_by = $verifier?->id;
        $payment->verified_at = now()->toImmutable();
        $payment->save();

        $invoice->status = SaasInvoice::PAID;
        $invoice->paid_at = now()->toImmutable();
        $invoice->period_start = $period['start'];
        $invoice->period_end = $period['end'];
        $invoice->save();

        $this->audit->record('billing.invoice_paid', $invoice,
            after: ['number' => $invoice->number, 'provider' => $payment->provider, 'reference' => $payment->external_reference, 'period_end' => $period['end']->toIso8601String()],
            actor: $verifier, tenant: $this->tenantOf($invoice));

        return $payment;
    }

    private function lockInvoice(string $id): SaasInvoice
    {
        /** @var SaasInvoice */
        return SaasInvoice::query()->withoutGlobalScope(TenantScope::class)->lockForUpdate()->findOrFail($id);
    }

    private function tenantOf(SaasInvoice $invoice): Tenant
    {
        return Tenant::query()->findOrFail($invoice->tenant_id);
    }
}
