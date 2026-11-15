<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="commy-light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- A control panel is private: no crawler should index or follow any page of it, the sign-in page included. --}}
        <meta name="robots" content="noindex, nofollow">

        <script>
            // Apply the saved theme before first paint to avoid a flash.
            (function () {
                try {
                    var pref = localStorage.getItem('commypanel-theme') || 'system';
                    var themes = { light: 'commy-light', dim: 'commy-dim', dark: 'commy-dark' };
                    var theme = themes[pref] || (matchMedia('(prefers-color-scheme: dark)').matches ? 'commy-dim' : 'commy-light');
                    document.documentElement.setAttribute('data-theme', theme);
                } catch (e) {}
            })();
        </script>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
