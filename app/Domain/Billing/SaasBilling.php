<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLog;
use App\Domain\Subscriptions\SubscriptionLifecycle;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantScope;
use App\Models\PaymentProviderEvent;
use App\Models\Plan;
use App\Models\SaasCreditNote;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        if (in_array($provider, SaasPayment::MANUAL_PROVIDERS, true)) {
            // Virement ou envoi Wave : référence unique à indiquer, validation manuelle du super-administrateur.
            $prefix = $provider === 'wave_manual' ? 'WAV-' : 'VIR-';
            $payment->external_reference = $prefix.$invoice->number.'-'.Str::upper(Str::random(4));
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

        return $this->recordAndApply($provider, $event);
    }

    /**
     * Interroge le prestataire sur un paiement en attente (webhook perdu ou en
     * retard). Même chemin que le webhook : événement dédupliqué, effet
     * identique, rien n'est refait si l'état est déjà connu.
     *
     * @return string issue : processed, duplicate, ignored… ou « skipped » (prestataire inactif), « unknown » (rien de nouveau)
     */
    public function reconcile(SaasPayment $payment): string
    {
        $gateway = app(ProviderRegistry::class)->find($payment->provider);

        if ($gateway === null) {
            return 'skipped';
        }

        $event = $gateway->status($payment);

        return $event === null ? 'unknown' : $this->recordAndApply($payment->provider, $event)[1];
    }

    /**
     * Paiement resté en attente au-delà du délai sans que le prestataire en ait
     * confirmé l'issue : annulé, le client peut relancer. Un paiement qui
     * aboutirait malgré tout est encore honoré par webhook ou rapprochement.
     */
    public function expire(SaasPayment $payment): bool
    {
        return DB::transaction(function () use ($payment): bool {
            /** @var SaasPayment $locked */
            $locked = SaasPayment::query()->withoutGlobalScope(TenantScope::class)->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status !== SaasPayment::PENDING || in_array($locked->provider, SaasPayment::MANUAL_PROVIDERS, true)) {
                return false;
            }

            $locked->status = SaasPayment::CANCELLED;
            $locked->failure_reason = 'expired';
            $locked->save();
            $this->audit->record('billing.payment_expired', $locked, metadata: ['provider' => $locked->provider], tenant: Tenant::query()->findOrFail($locked->tenant_id));

            return true;
        });
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function recordAndApply(string $provider, WebhookEvent $event): array
    {
        if (PaymentProviderEvent::query()->where('provider', $provider)->where('external_event_id', $event->eventId)->exists()) {
            return [200, 'duplicate']; // rejeu : déjà traité, rien n'est refait
        }

        // L'événement est noté dans la même transaction que son effet : si le traitement
        // échoue, rien n'est conservé et le renvoi du prestataire sera traité (pas
        // écarté comme doublon d'un événement jamais appliqué).
        $outcome = DB::transaction(function () use ($provider, $event): ?array {
            try {
                // Point de sauvegarde : un doublon concurrent n'annule pas la transaction englobante.
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
                return null;
            }

            [$status, $error, $paymentId] = $this->applyEvent($provider, $event);
            $record->update(['status' => $status, 'error' => $error, 'payment_id' => $paymentId, 'processed_at' => now()]);

            return [200, $status];
        });

        return $outcome ?? [200, 'duplicate'];
    }

    /**
     * Effet d'un événement authentifié (dans la transaction de handleWebhook).
     *
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function applyEvent(string $provider, WebhookEvent $event): array
    {
        return DB::transaction(function () use ($provider, $event): array {
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
    }

    /**
     * Le client indique avoir payé (référence de transaction, capture ou reçu
     * facultatif). Rien n'est activé : le super-administrateur vérifie la
     * réception des fonds puis valide.
     */
    public function declarePayment(SaasPayment $payment, string $reference, ?UploadedFile $proof, User $actor): SaasPayment
    {
        return DB::transaction(function () use ($payment, $reference, $proof, $actor): SaasPayment {
            /** @var SaasPayment $locked */
            $locked = SaasPayment::query()->withoutGlobalScope(TenantScope::class)->lockForUpdate()->findOrFail($payment->id);

            if (! in_array($locked->provider, SaasPayment::MANUAL_PROVIDERS, true) || $locked->status !== SaasPayment::MANUAL_REVIEW) {
                throw new InvalidBillingOperation(__('billing.errors.not_transfer'));
            }

            if ($proof !== null) {
                $previous = $locked->proof_path;
                $locked->proof_path = $proof->storeAs(
                    'saas-proofs/'.$locked->tenant_id,
                    $locked->id.'-'.Str::lower(Str::random(6)).'.'.$proof->extension(),
                    'local',
                ) ?: null;

                if ($previous !== null && $previous !== $locked->proof_path) {
                    Storage::disk('local')->delete($previous);
                }
            }

            $locked->declared_reference = trim($reference);
            $locked->declared_at = now()->toImmutable();
            $locked->declared_by = $actor->id;
            $locked->save();

            $this->audit->record('billing.payment_declared', $locked,
                after: ['reference' => $locked->declared_reference, 'proof' => $locked->proof_path !== null],
                actor: $actor, tenant: Tenant::query()->findOrFail($locked->tenant_id));

            return $locked;
        });
    }

    /**
     * Paiement manuel non reçu (référence introuvable, montant différent) : refusé
     * avec motif ; le client peut relancer un paiement.
     */
    public function rejectManualPayment(SaasPayment $payment, string $reason, User $actor): SaasPayment
    {
        return DB::transaction(function () use ($payment, $reason, $actor): SaasPayment {
            /** @var SaasPayment $locked */
            $locked = SaasPayment::query()->withoutGlobalScope(TenantScope::class)->lockForUpdate()->findOrFail($payment->id);

            if (! in_array($locked->provider, SaasPayment::MANUAL_PROVIDERS, true) || $locked->status !== SaasPayment::MANUAL_REVIEW) {
                throw new InvalidBillingOperation(__('billing.errors.not_transfer'));
            }

            $locked->status = SaasPayment::CANCELLED;
            $locked->failure_reason = trim($reason);
            $locked->save();

            $this->audit->record('billing.payment_rejected', $locked, metadata: ['reason' => trim($reason)],
                actor: $actor, tenant: Tenant::query()->findOrFail($locked->tenant_id));

            return $locked;
        });
    }

    /**
     * Avoir sur une facture payée, plafonné au montant restant de la facture.
     * L'abonnement n'est pas modifié : une suspension éventuelle reste une action distincte.
     */
    public function issueCreditNote(SaasInvoice $invoice, int $amount, string $reason, User $actor): SaasCreditNote
    {
        return DB::transaction(function () use ($invoice, $amount, $reason, $actor): SaasCreditNote {
            $invoice = $this->lockInvoice($invoice->id);

            if ($invoice->status !== SaasInvoice::PAID) {
                throw new InvalidBillingOperation(__('billing.errors.credit_not_paid'));
            }

            $credited = (int) SaasCreditNote::query()->withoutGlobalScope(TenantScope::class)->where('invoice_id', $invoice->id)->sum('amount');

            if ($amount <= 0 || $amount > $invoice->total - $credited) {
                throw new InvalidBillingOperation(__('billing.errors.credit_amount', ['max' => $invoice->total - $credited]));
            }

            $sequence = (int) DB::selectOne('SELECT nextval(\'saas_credit_note_number_seq\') AS n')->n;
            $tenant = $this->tenantOf($invoice);

            $note = new SaasCreditNote;
            $note->forceFill([
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'number' => sprintf('AV-%d-%06d', now()->year, $sequence),
                'amount' => $amount,
                'reason' => trim($reason),
                'created_by' => $actor->id,
            ]);
            $this->context->runAs($tenant, fn () => $note->save());

            $this->audit->record('billing.credit_note_issued', $note,
                after: ['number' => $note->number, 'invoice' => $invoice->number, 'amount' => $amount],
                metadata: ['reason' => trim($reason)], actor: $actor, tenant: $tenant);

            return $note;
        });
    }

    /**
     * Paiement manuel reçu (virement, Wave) : validation par le super-administrateur avec justificatif.
     */
    public function confirmTransfer(SaasPayment $payment, string $proofReference, User $actor): SaasPayment
    {
        if (mb_strlen(trim($proofReference)) < 3) {
            throw new InvalidBillingOperation(__('billing.errors.proof_required'));
        }

        return DB::transaction(function () use ($payment, $proofReference, $actor): SaasPayment {
            /** @var SaasPayment $locked */
            $locked = SaasPayment::query()->withoutGlobalScope(TenantScope::class)->lockForUpdate()->findOrFail($payment->id);

            if (! in_array($locked->provider, SaasPayment::MANUAL_PROVIDERS, true) || ! in_array($locked->status, [SaasPayment::MANUAL_REVIEW, SaasPayment::SUCCEEDED], true)) {
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
