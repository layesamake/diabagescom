@php
    $xof = fn (int $amount): string => number_format($amount, 0, ',', ' ').' XOF';
    $title = __('billing.pdf.'.$kind);
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $number }}</title>
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
            <img src="{{ $logo }}" alt="DIABA GESCOM" style="height: 28px;">
            <div style="margin-top: 6px; font-weight: bold;">{{ $issuer['name'] ?? 'DIABA GESCOM' }}</div>
            @if (! empty($issuer['details']))
                <div class="muted" style="white-space: pre-line;">{{ $issuer['details'] }}</div>
            @endif
        </td>
        <td style="width: 45%" class="num">
            <div style="font-size: 16px; font-weight: bold; color: #018270;">{{ mb_strtoupper($title) }}</div>
            <div style="font-size: 12px; font-weight: bold;">{{ $number }}</div>
            <div>{{ __('billing.pdf.date') }} : {{ $date?->format('d/m/Y') }}</div>
            @if ($kind === 'credit_note')
                <div class="muted">{{ __('billing.pdf.on_invoice', ['number' => $invoice->number]) }}</div>
            @endif
        </td>
    </tr>
</table>

<table style="margin: 16px 0;">
    <tr>
        <td style="width: 55%"></td>
        <td style="width: 45%" class="box">
            <div class="muted">{{ __('billing.pdf.customer') }}</div>
            <div style="font-weight: bold; font-size: 11px;">{{ $tenant->legal_name }}</div>
            @if ($tenant->legal_name !== $tenant->display_name)
                <div>{{ $tenant->display_name }}</div>
            @endif
        </td>
    </tr>
</table>

@if ($reason)
    <p><strong>{{ __('billing.pdf.reason') }} :</strong> {{ $reason }}</p>
@endif

<table class="lines">
    <thead>
    <tr>
        <th>{{ __('billing.pdf.description') }}</th>
        <th class="num">{{ __('billing.pdf.quantity') }}</th>
        <th class="num">{{ __('billing.pdf.unit_price') }}</th>
        <th class="num">{{ __('billing.pdf.amount') }}</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($lines as $line)
        <tr>
            <td>{{ $line['label'] }}</td>
            <td class="num">{{ $line['quantity'] }}</td>
            <td class="num">{{ $xof((int) $line['unit_price']) }}</td>
            <td class="num">{{ $xof((int) $line['amount']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr class="grand"><td>{{ $kind === 'credit_note' ? __('billing.pdf.credited') : __('billing.pdf.paid') }}</td><td class="num">{{ $xof($total) }}</td></tr>
</table>

@if ($kind === 'receipt')
    <p style="margin-top: 16px;">
        <span class="badge">{{ __('billing.pdf.paid_badge') }}</span>
        @if ($invoice->period_start && $invoice->period_end)
            {{ __('billing.pdf.period', ['from' => $invoice->period_start->format('d/m/Y'), 'to' => $invoice->period_end->format('d/m/Y')]) }}
        @endif
    </p>
    @if ($payment)
        <p class="muted">
            {{ __('billing.pdf.method') }} : {{ __('billing.methods.'.$payment->provider) }}
            — {{ __('billing.pdf.reference') }} : {{ $payment->proof_reference ?? $payment->external_reference }}
        </p>
    @endif
@endif

<div class="footer">
    <img src="{{ $logo }}" alt="DIABA GESCOM"> {{ $issuer['name'] ?? 'DIABA GESCOM' }} — {{ $number }}
</div>
@include('partials.trial-watermark')
</body>
</html>
