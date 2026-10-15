<?php

namespace App\Domain\Sync;

use App\Domain\Audit\AuditLog;
use App\Domain\BusinessRuleViolation;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Sales\PointOfSale;
use App\Domain\Subscriptions\SubscriptionRestriction;
use App\Models\OfflineDevice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SyncCommand;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Synchronisation des commandes préparées hors connexion (docs/08).
 *
 * Le serveur revalide tout (appareil, droits, abonnement, prix, caisse, stock)
 * et rend un statut définitif par commande : accepted, rejected, conflict ou
 * requires_review. Le résultat est journalisé : rejouer la même commande renvoie
 * exactement la même réponse, sans second traitement.
 */
final class OfflineSync
{
    public const array TYPES = ['sale'];

    public function __construct(
        private readonly PointOfSale $pos,
        private readonly AuditLog $audit,
    ) {}

    public function register(User $user, string $deviceKey, string $label, ?string $userAgent): OfflineDevice
    {
        /** @var OfflineDevice|null $device */
        $device = OfflineDevice::query()->where('device_key', $deviceKey)->first();

        if ($device !== null) {
            if ($device->user_id !== $user->id || $device->isRevoked()) {
                throw new AuthorizationException(__('sync.errors.device_revoked'));
            }

            $device->last_seen_at = now()->toImmutable();
            $device->save();

            return $device;
        }

        $device = new OfflineDevice;
        $device->forceFill([
            'user_id' => $user->id,
            'device_key' => $deviceKey,
            'label' => mb_substr(trim($label) !== '' ? trim($label) : __('sync.default_label'), 0, 120),
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'last_seen_at' => now(),
        ])->save();

        $this->audit->record('sync.device_registered', $device, after: ['label' => $device->label], actor: $user);

        return $device;
    }

    /**
     * Appareil actif de l'utilisateur, ou refus (inconnu / révoqué).
     */
    public function device(User $user, ?string $deviceKey): OfflineDevice
    {
        /** @var OfflineDevice|null $device */
        $device = $deviceKey !== null ? OfflineDevice::query()->where('device_key', $deviceKey)->first() : null;

        if ($device === null || $device->user_id !== $user->id || $device->isRevoked()) {
            throw new AuthorizationException(__('sync.errors.device_revoked'));
        }

        $device->last_seen_at = now()->toImmutable();
        $device->save();

        return $device;
    }

    public function revoke(OfflineDevice $device, User $actor): void
    {
        if ($device->isRevoked()) {
            return;
        }

        $device->revoked_at = now()->toImmutable();
        $device->revoked_by = $actor->id;
        $device->save();

        $this->audit->record('sync.device_revoked', $device, after: ['label' => $device->label], actor: $actor);
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    public function process(OfflineDevice $device, User $user, array $command): array
    {
        $commandId = (string) ($command['command_id'] ?? '');
        $payload = is_array($command['payload'] ?? null) ? $command['payload'] : [];
        $hash = hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        /** @var SyncCommand|null $existing */
        $existing = SyncCommand::query()->where('command_id', $commandId)->first();

        if ($existing !== null) {
            return $this->replay($existing, $user, (string) ($command['type'] ?? ''), $hash);
        }

        $type = (string) ($command['type'] ?? '');

        if (! in_array($type, self::TYPES, true)) {
            return $this->store($device, $user, $command, $hash, SyncCommand::REJECTED, null, 'unknown_type', __('sync.errors.unknown_type'));
        }

        $validator = Validator::make($payload, [
            'warehouse_id' => ['required', 'uuid'],
            'cash_session_id' => ['required', 'uuid'],
            'customer_id' => ['nullable', 'uuid'],
            'negative_stock_reason' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'lines.*.discount' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'tenders' => ['present', 'array', 'max:6'],
            'tenders.*.method' => ['required', Rule::in(Payment::METHODS)],
            'tenders.*.amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'tenders.*.reference' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->store($device, $user, $command, $hash, SyncCommand::REJECTED, null, 'invalid', (string) $validator->errors()->first());
        }

        /** @var array{warehouse_id: string, cash_session_id: string, customer_id?: string|null, negative_stock_reason?: string|null, lines: array<int, array{product_id: string, quantity: string, unit_price: int, discount?: int|null}>, tenders: array<int, array{method: string, amount: int, reference?: string|null}>} $data */
        $data = $validator->validated();
        $gate = Gate::forUser($user);

        if (! $gate->allows('sale.create') || ! $gate->allows('sale.validate')) {
            return $this->store($device, $user, $command, $hash, SyncCommand::REJECTED, null, 'forbidden', __('sync.errors.forbidden'));
        }

        // Même règle que le POS en ligne : encaisser exige cash.receive.
        if (array_filter($data['tenders'], fn (array $t) => $t['amount'] > 0) !== [] && ! $gate->allows('cash.receive')) {
            return $this->store($device, $user, $command, $hash, SyncCommand::REJECTED, null, 'forbidden', __('sync.errors.forbidden'));
        }

        // Prix modifié ou article archivé depuis la synchronisation : revue humaine, rien n'est perdu.
        $review = $this->priceOrArchiveIssues(array_values($data['lines']), $gate->allows('catalog.price_override'));

        if ($review !== null) {
            return $this->store($device, $user, $command, $hash, SyncCommand::REQUIRES_REVIEW, ['issues' => $review['issues']], $review['code'], $review['message']);
        }

        try {
            $sale = $this->pos->checkout([
                'warehouse_id' => $data['warehouse_id'],
                'cash_session_id' => $data['cash_session_id'],
                'customer_id' => $data['customer_id'] ?? null,
                'negative_stock_reason' => $data['negative_stock_reason'] ?? null,
                'lines' => array_values(array_map(fn (array $l) => [
                    'product_id' => $l['product_id'], 'quantity' => $l['quantity'],
                    'unit_price' => $l['unit_price'], 'discount' => (int) ($l['discount'] ?? 0),
                ], $data['lines'])),
                'tenders' => array_values(array_map(fn (array $t) => [
                    'method' => $t['method'], 'amount' => $t['amount'], 'reference' => $t['reference'] ?? null,
                ], $data['tenders'])),
            ], $user, $commandId);
        } catch (SubscriptionRestriction $e) {
            // Situation temporaire : la commande reste en file sur l'appareil.
            return ['command_id' => $commandId, 'status' => 'deferred', 'result' => null, 'error' => ['code' => 'subscription', 'message' => $e->getMessage()]];
        } catch (InsufficientStock $e) {
            return $this->store($device, $user, $command, $hash, SyncCommand::CONFLICT, null, 'insufficient_stock', $e->getMessage());
        } catch (BusinessRuleViolation $e) {
            return $this->store($device, $user, $command, $hash, SyncCommand::REJECTED, null, 'business', $e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->store($device, $user, $command, $hash, SyncCommand::REJECTED, null, 'forbidden', $e->getMessage() ?: __('sync.errors.forbidden'));
        }

        return $this->store($device, $user, $command, $hash, SyncCommand::ACCEPTED,
            ['document_id' => $sale->id, 'number' => $sale->number, 'total' => $sale->total, 'payment_status' => $sale->payment_status], null, null);
    }

    /**
     * @param  list<array{product_id: string, quantity: string, unit_price: int, discount?: int|null}>  $lines
     * @return array{code: string, message: string, issues: list<array<string, mixed>>}|null
     */
    private function priceOrArchiveIssues(array $lines, bool $canOverride): ?array
    {
        $products = Product::query()->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');
        $issues = [];

        foreach ($lines as $line) {
            $product = $products[$line['product_id']] ?? null;

            if ($product === null || $product->archived_at !== null || ! $product->active) {
                $issues[] = ['product_id' => $line['product_id'], 'name' => $product?->name, 'kind' => 'archived'];
            } elseif ($line['unit_price'] !== $product->sale_price && ! $canOverride) {
                $issues[] = ['product_id' => $product->id, 'name' => $product->name, 'kind' => 'price', 'captured' => $line['unit_price'], 'current' => $product->sale_price];
            }
        }

        if ($issues === []) {
            return null;
        }

        $archived = array_filter($issues, fn (array $i) => $i['kind'] === 'archived');

        return [
            'code' => $archived !== [] ? 'product_archived' : 'price_changed',
            'message' => $archived !== [] ? __('sync.errors.product_archived') : __('sync.errors.price_changed'),
            'issues' => $issues,
        ];
    }

    /**
     * Rejeu d'une commande déjà journalisée : même contenu, même type et même
     * utilisateur → même réponse ; sinon refus, jamais le résultat d'une autre commande.
     *
     * @return array<string, mixed>
     */
    private function replay(SyncCommand $existing, User $user, string $type, string $hash): array
    {
        if ($existing->created_by !== $user->id) {
            return ['command_id' => $existing->command_id, 'status' => SyncCommand::REJECTED, 'result' => null,
                'error' => ['code' => 'command_owner_mismatch', 'message' => __('sync.errors.owner_mismatch')]];
        }

        if ($existing->payload_hash !== $hash || $existing->type !== mb_substr($type, 0, 32)) {
            return ['command_id' => $existing->command_id, 'status' => SyncCommand::REJECTED, 'result' => null,
                'error' => ['code' => 'hash_mismatch', 'message' => __('sync.errors.hash_mismatch')]];
        }

        return $existing->response();
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, mixed>|null  $result
     * @return array<string, mixed>
     */
    private function store(OfflineDevice $device, User $user, array $command, string $hash, string $status, ?array $result, ?string $code, ?string $message): array
    {
        $record = new SyncCommand;
        $record->forceFill([
            'device_id' => $device->id,
            'command_id' => (string) $command['command_id'],
            'type' => mb_substr((string) ($command['type'] ?? ''), 0, 32),
            'payload' => is_array($command['payload'] ?? null) ? $command['payload'] : [],
            'payload_hash' => $hash,
            'status' => $status,
            'result' => $result,
            'error_code' => $code,
            'error_message' => $message !== null ? mb_substr($message, 0, 1000) : null,
            'created_by' => $user->id,
            'client_created_at' => isset($command['created_at']) && is_string($command['created_at']) && strtotime($command['created_at']) !== false
                ? $command['created_at'] : null,
        ]);

        try {
            $record->save();
            // Relu depuis la base : la première réponse est identique octet pour octet aux rejeux.
            $record->refresh();
        } catch (UniqueConstraintViolationException) {
            // Envoi concurrent de la même commande : le résultat enregistré n'est renvoyé que
            // si la commande est identique (contenu, type, utilisateur) ; sinon refus explicite.
            /** @var SyncCommand $winner */
            $winner = SyncCommand::query()->where('command_id', $record->command_id)->firstOrFail();

            return $this->replay($winner, $user, (string) ($command['type'] ?? ''), $hash);
        }

        if ($status !== SyncCommand::ACCEPTED) {
            $this->audit->record("sync.command_{$status}", $record, after: ['code' => $code], metadata: ['device' => $device->label], actor: $user);
        }

        return $record->response();
    }
}
