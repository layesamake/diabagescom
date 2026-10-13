<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script nonce="{{ Vite::cspNonce() }}">
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        {{-- Identité DIABA GESCOM (docs/15-identite-visuelle.md) --}}
        <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
        <link rel="icon" href="/brand/diaba-icon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#002456">
        <meta name="application-name" content="DIABA GESCOM">
        <meta property="og:site_name" content="DIABA GESCOM">
        @isset($meta)
            {{-- Pages publiques : lues par Google et par les aperçus WhatsApp/Facebook sans JavaScript. --}}
            <meta name="description" content="{{ $meta['description'] }}">
            <link rel="canonical" href="{{ $meta['canonical'] }}">
            <meta property="og:type" content="website">
            <meta property="og:locale" content="fr_SN">
            <meta property="og:title" content="{{ $meta['title'] }}">
            <meta property="og:description" content="{{ $meta['description'] }}">
            <meta property="og:url" content="{{ $meta['canonical'] }}">
            <meta name="twitter:card" content="summary_large_image">
        @endisset
        <meta property="og:image" content="{{ url('/brand/og-diaba-gescom.png') }}">
        <meta property="og:image:alt" content="DIABA GESCOM">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ $meta['title'] ?? config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
