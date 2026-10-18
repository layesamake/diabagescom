<?php

namespace App\Domain\Sales;

use App\Domain\Inventory\StockAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\Payment;
use App\Models\SalesDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Chiffres du jour d'un vendeur : ce n'est pas un rapport de gestion
 * (report.view_own / report.view_all), c'est le compte du vendeur sur ses
 * propres ventes — accessible à tout rôle qui vend. Un administrateur voit
 * le total de tous les vendeurs du dépôt.
 *
 * Utilisé par l'accueil (aujourd'hui, hier) et par Mes ventes (un jour choisi).
 */
final class SalesDayLedger
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly StockAccess $access,
    ) {}

    /**
     * @return array{total: int, count: int}
     */
    public function totalsForDay(CarbonImmutable $day, User $user, bool $all): array
    {
        $documents = $this->documentsForDay($day, $user, $all);

        return ['total' => (int) $documents->sum('total'), 'count' => $documents->count()];
    }

    /**
     * @return array{day: string, total: int, count: int, by_method: array{cash: int, mobile: int, credit: int}, sales: list<array<string, mixed>>}
     */
    public function detailForDay(CarbonImmutable $day, User $user, bool $all): array
    {
        $documents = $this->documentsForDay($day, $user, $all)->load(['customer:id,name', 'author:id,name']);
        $payments = Payment::query()
            ->where('tenant_id', $this->context->tenantId())
            ->whereIn('sales_document_id', $documents->pluck('id'))
            ->where('direction', 'in')
            ->get()
            ->groupBy('sales_document_id');

        $byMethod = ['cash' => 0, 'mobile' => 0, 'credit' => 0];

        foreach ($documents as $document) {
            foreach ($payments[$document->id] ?? [] as $payment) {
                $bucket = $payment->method === 'cash' ? 'cash' : (in_array($payment->method, ['wave', 'orange_money'], true) ? 'mobile' : 'cash');
                $byMethod[$bucket] += $payment->amount;
            }
            if ($document->balanceDue() > 0) {
                $byMethod['credit'] += $document->balanceDue();
            }
        }

        return [
            'day' => $day->toDateString(),
            'total' => (int) $documents->sum('total'),
            'count' => $documents->count(),
            'by_method' => $byMethod,
            'sales' => $documents->map(fn (SalesDocument $document) => [
                'id' => $document->id,
                'number' => $document->number,
                'time' => $document->validated_at?->format('H:i'),
                'customer' => $document->customer?->name,
                'total' => $document->total,
                'balance' => $document->balanceDue(),
                'seller' => $document->author->name,
                'ticket_url' => route('sales.ticket', ['document' => $document]),
            ])->values()->all(),
        ];
    }

    /**
     * @return Collection<int, SalesDocument>
     */
    private function documentsForDay(CarbonImmutable $day, User $user, bool $all): Collection
    {
        return SalesDocument::query()
            ->where('type', SalesDocument::INVOICE)
            ->where('status', SalesDocument::VALIDATED)
            ->whereIn('warehouse_id', $this->access->accessibleIds())
            ->whereDate('validated_at', $day)
            ->when(! $all, fn ($query) => $query->where('created_by', $user->id))
            ->orderByDesc('validated_at')
            ->get();
    }
}
