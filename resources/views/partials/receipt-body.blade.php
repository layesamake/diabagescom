@php
    use App\Domain\Inventory\Quantity;
    $xof = fn (int $amount): string => number_format($amount, 0, ',', ' ');
    $customer = $document->customer_snapshot ?? [];
@endphp

{{-- Contenu du ticket, commun au PDF et à l'impression thermique (58/80 mm). --}}
<div class="center">
    <div class="big">{{ $tenant->display_name }}</div>
    <div>{{ $document->warehouse->name }}</div>
    @if ($document->warehouse->address)<div>{{ $document->warehouse->address }}</div>@endif
</div>
<hr>
<div>{{ $document->type === 'credit_note' ? __('sales.types.credit_note') : __('sales.pdf.receipt') }} {{ $document->number }}</div>
<div>{{ $document->validated_at?->timezone($tenant->timezone)->format('d/m/Y H:i') }} — {{ __('sales.pdf.seller') }} : {{ $document->author->name }}</div>
@if ($customer)<div>{{ __('sales.pdf.customer') }} : {{ $customer['name'] ?? '' }}</div>@endif
<hr>
<table>
    @foreach ($document->lines as $line)
        <tr><td colspan="2">{{ $line->label }}</td></tr>
        <tr>
            <td>{{ Quantity::of($line->quantity)->format() }} × {{ $xof($line->unit_price) }}@if ($line->discount_amount > 0) (−{{ $xof($line->discount_amount) }})@endif</td>
            <td class="num">{{ $xof($line->line_total) }}</td>
        </tr>
    @endforeach
</table>
<hr>
<table>
    <tr><td class="big">TOTAL</td><td class="num big">{{ $xof($document->total) }} XOF</td></tr>
    @foreach ($taxes as $tax)
        <tr><td>dont TVA {{ rtrim(rtrim(number_format($tax['rate'] / 100, 2, ',', ''), '0'), ',') }} %</td><td class="num">{{ $xof($tax['tax']) }}</td></tr>
    @endforeach
    @foreach ($document->payments as $payment)
        <tr><td>{{ __('sales.methods.'.$payment->method) }}</td><td class="num">{{ $payment->direction === 'out' ? '−' : '' }}{{ $xof($payment->amount) }}</td></tr>
    @endforeach
    @if ($document->type === 'invoice' && $document->balanceDue() > 0)
        <tr><td><strong>{{ __('sales.pdf.balance') }}</strong></td><td class="num"><strong>{{ $xof($document->balanceDue()) }}</strong></td></tr>
    @endif
</table>
<hr>
<div class="center">{{ __('sales.pdf.change_note') }}</div>
<div class="center small">{{ __('sales.pdf.provisional_rules') }}</div>
