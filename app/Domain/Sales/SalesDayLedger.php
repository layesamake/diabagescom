<?php

namespace App\Domain\Sales;

use App\Domain\Inventory\StockAccess;
use App\Domain\Tenancy\TenantContext;
use App\Models\Payment;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLine;
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
        $documents = $this->documentsForDay($day, $user, $all)->load(['customer:id,name', 'author:id,name', 'lines']);
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
            'sales' => array_values($documents->map(function (SalesDocument $document) use ($payments) {
                $documentPayments = $payments[$document->id] ?? collect();
                $balance = $document->balanceDue();

                return [
                    'id' => $document->id,
                    'number' => $document->number,
                    'time' => $document->validated_at?->format('H:i'),
                    'customer' => $document->customer?->name,
                    'total' => $document->total,
                    'balance' => $balance,
                    'seller' => $document->author->name,
                    'method' => $this->primaryMethod($documentPayments, $balance),
                    'products' => $this->productBadges($document),
                    'ticket_url' => route('sales.ticket', ['document' => $document]),
                ];
            })->all()),
        ];
    }

    /**
     * Mode principal d'une vente : « credit » s'il reste à payer, sinon le
     * premier paiement (espèces ou téléphone selon la méthode du paiement).
     *
     * @param  \Illuminate\Support\Collection<int, Payment>  $documentPayments
     */
    private function primaryMethod(\Illuminate\Support\Collection $documentPayments, int $balance): string
    {
        if ($balance > 0) {
            return 'credit';
        }
        foreach ($documentPayments as $payment) {
            if (in_array($payment->method, ['wave', 'orange_money'], true)) {
                return 'mobile';
            }
            if ($payment->method === 'cash') {
                return 'cash';
            }
        }

        return 'cash';
    }

    /**
     * Deux premiers articles d'une vente, badge « initiales » + quantité
     * formatée (1,500 ou 1,5), ordre de saisie conservé.
     *
     * @return list<array{id: string, initials: string, quantity: string, label: string}>
     */
    private function productBadges(SalesDocument $document): array
    {
        $lines = $document->lines
            ->sortBy('position')
            ->take(2)
            ->values();

        return array_values(array_map(fn (SalesDocumentLine $line) => [
            'id' => $line->product_id,
            'initials' => $this->initials($line->label),
            'quantity' => $line->quantity,
            'label' => $line->label,
        ], $lines->all()));
    }

    private function initials(string $label): string
    {
        $words = preg_split('/\s+/u', trim($label)) ?: [];
        $initials = '';
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $initials .= mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8');
            if (mb_strlen($initials, 'UTF-8') >= 3) {
                break;
            }
        }
        $initials = mb_substr($initials, 0, 3, 'UTF-8');

        return $initials !== '' ? $initials : '·';
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
