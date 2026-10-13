@php
    use App\Domain\Inventory\Quantity;
    $xof = fn (int $amount): string => number_format($amount, 0, ',', ' ').' XOF';
    $title = __('sales.types.'.$document->type);
    $customer = $document->customer_snapshot ?? [];
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $document->number }}</title>
    <style>
        @page { margin: 28px 32px 60px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
        h1 { font-size: 20px; color: #002456; margin: 0 0 4px; }
        .muted { color: #6b7280; }
        .header td { vertical-align: top; }
        table { width: 100%; border-collapse: collapse; }
        .lines th { background: #002456; color: #fff; padding: 6px; text-align: left; font-size: 9px; }
        .lines td { padding: 6px; border-bottom: 1px solid #e5e7eb; }
        .num { text-align: right; white-space: nowrap; }
        .totals { width: 45%; margin-left: 55%; margin-top: 12px; }
        .totals td { padding: 4px 6px; }
        .totals .grand td { font-size: 13px; font-weight: bold; color: #002456; border-top: 2px solid #002456; }
        .box { border: 1px solid #e5e7eb; border-radius: 4px; padding: 8px; }
        .footer { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 8px; color: #6b7280; }
        .footer img { height: 14px; vertical-align: middle; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 3px; background: #e6f4f1; color: #018270; font-weight: bold; }
    </style>
</head>
<body>
<table class="header">
    <tr>
        <td style="width: 55%">
            @if (! empty($companyLogo))
                <img src="{{ $companyLogo }}" alt="" style="max-height: 52px; max-width: 170px; margin-bottom: 6px;">
            @endif
            <h1>{{ $tenant->display_name }}</h1>
            <div>{{ $tenant->legal_name }}</div>
            <div class="muted">{{ $document->warehouse->name }}@if ($document->warehouse->address) — {{ $document->warehouse->address }}@endif</div>
        </td>
        <td style="width: 45%" class="num">
            <div style="font-size: 16px; font-weight: bold; color: #018270;">{{ mb_strtoupper($title) }}</div>
            <div style="font-size: 12px; font-weight: bold;">{{ $document->number }}</div>
            <div>{{ __('sales.pdf.date') }} : {{ $document->issue_date?->format('d/m/Y') }}</div>
            @if ($document->type === 'invoice' && $document->due_date)
                <div>{{ __('sales.pdf.due_date') }} : {{ $document->due_date->format('d/m/Y') }}</div>
            @endif
            @if ($document->type === 'quote' && $document->valid_until)
                <div>{{ __('sales.pdf.valid_until') }} : {{ $document->valid_until->format('d/m/Y') }}</div>
            @endif
            @if ($document->source)
                <div class="muted">{{ __('sales.pdf.source', ['number' => $document->source->number]) }}</div>
            @endif
        </td>
    </tr>
</table>

<table style="margin: 16px 0;">
    <tr>
        <td style="width: 55%"></td>
        <td style="width: 45%" class="box">
            <div class="muted">{{ __('sales.pdf.customer') }}</div>
            @if ($customer)
                <div style="font-weight: bold; font-size: 11px;">{{ $customer['name'] ?? '' }}</div>
                @if (! empty($customer['address_line']))<div>{{ $customer['address_line'] }}@if (! empty($customer['city'])), {{ $customer['city'] }}@endif</div>@endif
                @if (! empty($customer['phone']))<div>{{ $customer['phone'] }}</div>@endif
                @if (! empty($customer['tax_number']))<div>NINEA : {{ $customer['tax_number'] }}</div>@endif
            @else
                <div>Client comptoir</div>
            @endif
        </td>
    </tr>
</table>

@if ($document->type === 'credit_note' && $document->reason)
    <p><strong>{{ __('sales.pdf.reason') }} :</strong> {{ $document->reason }}</p>
@endif

<table class="lines">
    <thead>
    <tr>
        <th>{{ __('sales.pdf.product') }}</th>
        <th class="num">{{ __('sales.pdf.quantity') }}</th>
        <th class="num">{{ __('sales.pdf.unit_price') }}</th>
        <th class="num">{{ __('sales.pdf.discount') }}</th>
        <th class="num">TVA</th>
        <th class="num">{{ __('sales.pdf.total') }}</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($document->lines as $line)
        <tr>
            <td>{{ $line->label }}<br><span class="muted">{{ $line->sku }}</span></td>
            <td class="num">{{ Quantity::of($line->quantity)->format() }} {{ $line->unit_code }}</td>
            <td class="num">{{ $xof($line->unit_price) }}</td>
            <td class="num">{{ $line->discount_amount > 0 ? $xof($line->discount_amount) : '—' }}</td>
            <td class="num">{{ rtrim(rtrim(number_format($line->tax_rate_basis_points / 100, 2, ',', ''), '0'), ',') }} %</td>
            <td class="num">{{ $xof($line->line_total) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>{{ __('sales.pdf.total_excl_tax') }}</td><td class="num">{{ $xof($document->total_excl_tax) }}</td></tr>
    @foreach ($taxes as $tax)
        <tr><td>{{ __('sales.pdf.tax', ['rate' => rtrim(rtrim(number_format($tax['rate'] / 100, 2, ',', ''), '0'), ',')]) }} <span class="muted">(base {{ $xof($tax['base']) }})</span></td><td class="num">{{ $xof($tax['tax']) }}</td></tr>
    @endforeach
    @if ($document->total_discount > 0)
        <tr><td>{{ __('sales.pdf.discount') }}</td><td class="num">− {{ $xof($document->total_discount) }}</td></tr>
    @endif
    <tr class="grand"><td>{{ __('sales.pdf.total') }}</td><td class="num">{{ $xof($document->total) }}</td></tr>
    @if ($document->type === 'invoice')
        @if ($document->amount_credited > 0)
            <tr><td>{{ __('sales.pdf.credited') }}</td><td class="num">− {{ $xof($document->amount_credited) }}</td></tr>
        @endif
        <tr><td>{{ __('sales.pdf.paid') }}</td><td class="num">{{ $xof($document->amount_paid) }}</td></tr>
        <tr><td><strong>{{ __('sales.pdf.balance') }}</strong></td><td class="num"><strong>{{ $xof(max(0, $document->balanceDue())) }}</strong></td></tr>
    @endif
</table>

@if ($document->type === 'invoice' && $document->payments->isNotEmpty())
    <p style="margin-top: 16px;"><strong>{{ __('sales.pdf.payments') }}</strong></p>
    <table class="lines">
        @foreach ($document->payments as $payment)
            <tr>
                <td>{{ $payment->number }}</td>
                <td>{{ __('sales.methods.'.$payment->method) }}@if ($payment->reference) ({{ $payment->reference }})@endif</td>
                <td>{{ $payment->paid_at->format('d/m/Y H:i') }}</td>
                <td class="num">{{ $payment->direction === 'out' ? '− ' : '' }}{{ $xof($payment->amount) }}</td>
            </tr>
        @endforeach
    </table>
@endif

@if ($document->notes)
    <p class="muted" style="margin-top: 16px;">{{ $document->notes }}</p>
@endif

<table style="margin-top: 18px;">
    <tr>
        <td style="width: 55%; vertical-align: bottom;" class="muted">{{ __('company.pdf.vat_regime') }} : {{ $vatRegime }}</td>
        <td style="width: 45%; text-align: center;">
            <div class="muted">{{ __('company.pdf.stamp') }}</div>
            @if (! empty($companyStamp))
                <img src="{{ $companyStamp }}" alt="" style="max-height: 90px; max-width: 200px;">
            @else
                <div style="height: 60px;"></div>
            @endif
        </td>
    </tr>
</table>

<div class="footer">
    <img src="{{ $logo }}" alt="DIABA GESCOM"> {{ __('sales.pdf.provisional_rules') }} — {{ $document->number }}
</div>
</body>
</html>
