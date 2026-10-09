<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite('resources/css/app.css')
        @livewireStyles
    </head>
    <body class="bg-canvas font-sans text-ink antialiased">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2">
            Skip to content
        </a>

        <div class="min-h-screen">
            <aside class="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col gap-8 overflow-y-auto bg-gradient-to-b from-brand to-navy px-4 py-6 text-white shadow-[8px_0_28px_-18px_rgb(22_49_114/0.7)] lg:flex">
                <div class="flex items-center gap-3 px-2">
                    <span class="flex size-10 items-center justify-center rounded-xl bg-white/15 text-sm font-semibold shadow-[inset_0_1px_0_rgb(255_255_255/0.35),0_8px_16px_-10px_rgb(0_0_0/0.55)]" aria-hidden="true">CM</span>
                    <p class="text-sm font-semibold leading-5 tracking-tight">Calibration Management</p>
                </div>
                <x-app-nav />
            </aside>

            <div class="min-w-0 lg:pl-64">
                <details class="bg-navy text-white lg:hidden">
                    <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold [&::-webkit-details-marker]:hidden">
                        Menu
                    </summary>
                    <div class="px-3 pb-4">
                        <x-app-nav />
                    </div>
                </details>

                <div id="main" class="min-w-0">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
