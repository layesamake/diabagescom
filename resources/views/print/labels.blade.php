<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('catalog.labels.title') }}</title>
    <style>
        @if ($roll)
            /* Rouleau : une étiquette par page, à la taille exacte du support. */
            @page { size: {{ $width }}mm {{ $height }}mm; margin: 0; }
        @else
            @page { size: A4; margin: 8mm; }
        @endif
        * { box-sizing: border-box; }
        html, body { margin: 0; background: #fff; color: #000; }
        body { font-family: 'Segoe UI', Roboto, Arial, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .sheet { display: flex; flex-wrap: wrap; gap: {{ $roll ? 0 : 2 }}mm; }
        .label {
            width: {{ $width }}mm; height: {{ $height }}mm; padding: 1.5mm 2mm;
            display: flex; flex-direction: column; justify-content: space-between; overflow: hidden;
            break-inside: avoid; page-break-inside: avoid;
            @if ($roll) page-break-after: always; @else border: 0.2mm dashed #bbb; @endif
        }
        .name { font-size: 8pt; font-weight: 600; line-height: 1.15; max-height: 2.3em; overflow: hidden; }
        .bars { flex: 1; min-height: 0; margin: 0.8mm 0; display: flex; justify-content: center; }
        .bars svg { width: 100%; height: 100%; }
        .bars svg.square { width: auto; aspect-ratio: 1; }
        .code { text-align: center; font-family: ui-monospace, Consolas, monospace; font-size: 7pt; letter-spacing: 0.05em; }
        .meta { display: flex; justify-content: space-between; align-items: baseline; gap: 2mm; }
        .price { font-size: 11pt; font-weight: 700; white-space: nowrap; }
        .sku { font-size: 6pt; color: #333; }
        .toolbar { display: flex; gap: 8px; align-items: center; justify-content: center; flex-wrap: wrap; padding: 12px; font-family: sans-serif; }
        .toolbar button { min-height: 44px; padding: 0 16px; font-size: 15px; border-radius: 8px; border: 1px solid #018270; background: #018270; color: #fff; cursor: pointer; }
        .toolbar button.secondary { background: #fff; color: #018270; }
        .warn { margin: 0 12px 12px; padding: 10px 12px; border: 1px solid #d97706; background: #fffbeb; color: #78350f; border-radius: 8px; font-family: sans-serif; font-size: 14px; }
        @media screen { html { background: #e9eef5; } .sheet { padding: 12px; justify-content: center; } .label { background: #fff; } }
        @media print { .toolbar, .warn { display: none; } }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" id="print-button">{{ __('catalog.labels.print', ['count' => count($labels) * $copies]) }}</button>
    <button type="button" class="secondary" id="close-button">{{ __('catalog.labels.close') }}</button>
</div>
@if ($skipped !== [])
    <p class="warn" role="alert">{{ __('catalog.labels.skipped', ['names' => implode(', ', $skipped)]) }}</p>
@endif

<div class="sheet">
    @foreach ($labels as $label)
        @for ($i = 0; $i < $copies; $i++)
            <div class="label">
                <div class="name">{{ $label['name'] }}</div>
                <div class="bars">{!! $label['square'] ? str_replace('<svg ', '<svg class="square" ', $label['svg']) : $label['svg'] !!}</div>
                <div class="code">{{ $label['code'] }}</div>
                <div class="meta">
                    <span class="sku">{{ $label['sku'] }}</span>
                    @if ($showPrice)
                        <span class="price">{{ number_format($label['price'], 0, ',', ' ') }} F</span>
                    @endif
                </div>
            </div>
        @endfor
    @endforeach
</div>

<script nonce="{{ Vite::cspNonce() }}">
    // Les attributs onclick sont interdits par la politique de sécurité : écouteurs ici.
    document.getElementById('print-button')?.addEventListener('click', () => window.print());
    document.getElementById('close-button')?.addEventListener('click', () => window.close());
</script>

@if ($autoprint && $labels !== [])
    <script nonce="{{ Vite::cspNonce() }}">
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    </script>
@endif
</body>
</html>
