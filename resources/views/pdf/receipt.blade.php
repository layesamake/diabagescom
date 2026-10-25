<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ __('sales.pdf.receipt') }} {{ $document->number }}</title>
    <style>
        @page { margin: 10px 8px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: {{ $width === 58 ? 7.5 : 9 }}px; color: #000; }
        .center { text-align: center; }
        .num { text-align: right; white-space: nowrap; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        hr { border: 0; border-top: 1px dashed #000; margin: 5px 0; }
        .big { font-size: {{ $width === 58 ? 11 : 13 }}px; font-weight: bold; }
        .small { font-size: 7px; }
    </style>
</head>
<body>
@include('partials.receipt-body')
@include('partials.trial-watermark')
</body>
</html>
