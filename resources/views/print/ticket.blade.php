<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('sales.pdf.receipt') }} {{ $document->number }}</title>
    <style>
        /* Ticket thermique : largeur papier exacte, marges nulles, noir pur. */
        @page { size: {{ $width }}mm auto; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; background: #fff; color: #000; }
        body {
            width: {{ $width }}mm;
            padding: 2mm {{ $width === 58 ? 2 : 3 }}mm 6mm;
            font-family: 'Segoe UI', Roboto, Arial, sans-serif;
            font-size: {{ $width === 58 ? 11 : 12.5 }}px;
            line-height: 1.3;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .center { text-align: center; }
        .num { text-align: right; white-space: nowrap; padding-left: 4px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
        .big { font-size: {{ $width === 58 ? 15 : 17 }}px; font-weight: 700; }
        .small { font-size: 9px; }
        /* Barre d'outils visible à l'écran uniquement. */
        .toolbar { display: flex; gap: 8px; justify-content: center; padding: 12px; font-family: sans-serif; }
        .toolbar button { min-height: 44px; padding: 0 16px; font-size: 15px; border-radius: 8px; border: 1px solid #018270; background: #018270; color: #fff; cursor: pointer; }
        .toolbar button.secondary { background: #fff; color: #018270; }
        @media screen {
            html { background: #e9eef5; }
            body { margin: 0 auto; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); }
        }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" id="print-button">{{ __('sales.ticket.print') }}</button>
    <button type="button" class="secondary" id="close-button">{{ __('sales.ticket.close') }}</button>
</div>
@include('partials.receipt-body')
@include('partials.trial-watermark')
<script nonce="{{ Vite::cspNonce() }}">
    // Les attributs onclick sont interdits par la politique de sécurité : écouteurs ici.
    document.getElementById('print-button')?.addEventListener('click', () => window.print());
    document.getElementById('close-button')?.addEventListener('click', () => window.close());
</script>
@if ($autoprint)
    <script nonce="{{ Vite::cspNonce() }}">
        // Impression directe à l'ouverture (vente comptoir) : la boîte de dialogue
        // du système permet de choisir l'imprimante thermique.
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    </script>
@endif
</body>
</html>
