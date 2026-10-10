@php
    use App\Domain\Inventory\Quantity;
    $xof = fn (int $amount): string => number_format($amount, 0, ',', ' ');
    $customer = $document->customer_snapshot ?? [];
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ __('sales.pdf.receipt') }} {{ $document->number }}</title>
    <style>
        @page { margin: 10px 8px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #000; }
        .center { text-align: center; }
        .num { text-align: right; white-space: nowrap; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        hr { border: 0; border-top: 1px dashed #000; margin: 5px 0; }
        .big { font-size: 13px; font-weight: bold; }
    </style>
</head>
<body>
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
<div class="center" style="font-size: 7px;">{{ __('sales.pdf.provisional_rules') }}</div>
</body>
</html>
