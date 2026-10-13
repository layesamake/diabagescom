<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title }}</title>
    <style>
        @page { margin: 28px 32px 50px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1f2937; }
        h1 { font-size: 17px; color: #002456; margin: 0 0 2px; }
        h2 { font-size: 11px; color: #002456; margin: 14px 0 4px; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #002456; color: #fff; padding: 5px; text-align: left; font-size: 8px; }
        td { padding: 4px 5px; border-bottom: 1px solid #e5e7eb; }
        .num { text-align: right; white-space: nowrap; }
        .strong td { font-weight: bold; background: #f3f4f6; }
        .footer { position: fixed; bottom: -34px; left: 0; right: 0; font-size: 7px; color: #6b7280; }
    </style>
</head>
<body>
<table style="margin-bottom: 8px;">
    <tr>
        <td style="border: 0; width: 60%;">
            @if (! empty($logo))
                <img src="{{ $logo }}" alt="" style="max-height: 40px; max-width: 140px; margin-bottom: 4px;"><br>
            @endif
            <strong>{{ $tenant->display_name }}</strong>
            @if ($tenant->legal_name !== $tenant->display_name)
                <br><span class="muted">{{ $tenant->legal_name }}</span>
            @endif
        </td>
        <td style="border: 0;" class="num">
            <h1>{{ $report->title }}</h1>
            <div class="muted">{{ $report->subtitle }}</div>
        </td>
    </tr>
</table>

@foreach ($report->sections as $section)
    @if ($section['title'] !== null)
        <h2>{{ $section['title'] }}</h2>
    @endif
    <table>
        @if ($section['headers'] !== [])
            <thead>
            <tr>
                @foreach ($section['headers'] as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
            </thead>
        @endif
        <tbody>
        @foreach ($section['rows'] as $i => $row)
            <tr class="{{ in_array($i, $section['strong'], true) ? 'strong' : '' }}">
                @foreach ($row as $cell)
                    @if (is_int($cell))
                        <td class="num">{{ number_format($cell, 0, ',', ' ') }}</td>
                    @else
                        <td>{{ $cell }}</td>
                    @endif
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
@endforeach

<div class="footer">{{ $tenant->display_name }} — {{ $report->title }} — édité le {{ $generatedAt }} — DIABA GESCOM</div>
</body>
</html>
