<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title inertia>{{ config('app.name', 'NexusERP') }}</title>

        <!-- Inter Font -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

        @if (app()->environment('local'))
            <script type="module">
                import RefreshRuntime from 'http://localhost:5173/@react-refresh'
                RefreshRuntime.injectIntoGlobalHook(window)
                window.$RefreshReg$ = () => {}
                window.$RefreshSig$ = () => (type) => type
                window.__vite_plugin_react_preamble_installed__ = true
            </script>
            <script type="module" src="http://localhost:5173/@@vite/client"></script>
            <script type="module" src="http://localhost:5173/src/app.tsx"></script>
        @else
            @php
                $manifestPath = public_path('build/.vite/manifest.json');
                if (! file_exists($manifestPath)) {
                    $manifestPath = public_path('build/manifest.json');
                }
                $manifest = file_exists($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : [];
                $entry = $manifest['index.html'] ?? $manifest['src/app.tsx'] ?? null;
            @endphp
            @if ($entry)
                @if (! empty($entry['css']))
                    @foreach ($entry['css'] as $cssFile)
                        <link rel="stylesheet" href="{{ asset('build/' . $cssFile) }}">
                    @endforeach
                @endif
                <script type="module" src="{{ asset('build/' . $entry['file']) }}"></script>
            @endif
        @endif

        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-background text-foreground">
        @inertia
    </body>
</html>
