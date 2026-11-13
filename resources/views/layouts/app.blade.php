<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <script>
            (() => {
                const shouldResetScroll = @js(request()->routeIs('home') || request()->routeIs('instruments.index') || request()->routeIs('calibrations.index'));

                if ('scrollRestoration' in history) {
                    history.scrollRestoration = shouldResetScroll ? 'manual' : 'auto';
                }

                if (! shouldResetScroll) {
                    return;
                }

                const resetPageScroll = () => {
                    document.scrollingElement?.scrollTo({
                        top: 0,
                        left: 0,
                        behavior: 'auto',
                    });
                };

                resetPageScroll();
            })();
        </script>

        @if (request()->routeIs('home') || request()->routeIs('instruments.index') || request()->routeIs('calibrations.index') || session('animate_shell_entrance'))
            <script>
                (() => {
                    const root = document.documentElement;

                    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true) {
                        return;
                    }

                    @if (request()->routeIs('home'))
                        root.classList.add('dashboard-animation-pending');
                    @endif

                    @if (request()->routeIs('instruments.index'))
                        root.classList.add('instruments-animation-pending');
                    @endif

                    @if (request()->routeIs('calibrations.index'))
                        root.classList.add('calibrations-animation-pending');
                    @endif

                    @if (session('animate_shell_entrance'))
                        root.classList.add('shell-animation-pending');
                    @endif

                    const revealAnimations = () => root.classList.remove(
                        'dashboard-animation-pending',
                        'instruments-animation-pending',
                        'calibrations-animation-pending',
                        'shell-animation-pending',
                    );

                    window.addEventListener('error', revealAnimations, { once: true });
                    window.addEventListener('unhandledrejection', revealAnimations, { once: true });
                })();
            </script>
        @endif

        @fonts
        @vite('resources/css/app.css')
        @vite('resources/js/app.js')
        @livewireStyles
    </head>
    <body class="bg-canvas font-sans text-ink antialiased">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2">
            Skip to content
        </a>

        <div class="min-h-screen">
            <aside data-dashboard-sidebar class="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col gap-8 overflow-y-auto bg-gradient-to-b from-brand to-navy px-4 py-6 text-white shadow-[8px_0_28px_-18px_rgb(22_49_114/0.7)] lg:flex">
                <div class="flex flex-col gap-3 px-1">
                    <div class="flex items-center justify-center">
                        <img
                            src="{{ asset('images/icon/instruments-desk-logo-icon.svg') }}"
                            alt="Instruments Desk"
                            class="h-14 w-14 object-contain"
                        >
                    </div>
                    <div class="px-1">
                        <p class="text-[11px] font-medium uppercase tracking-[0.22em] text-brand-soft">Instruments</p>
                        <p class="mt-1 text-xl font-semibold leading-none tracking-tight">Desk</p>
                    </div>
                </div>
                <div class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <x-app-nav />
                    </div>
                    <x-user-profile-menu />
                </div>
            </aside>

            <div class="min-w-0 lg:pl-64">
                <header class="mobile-nav bg-navy text-white lg:hidden" data-mobile-nav data-mobile-header>
                    <button
                        type="button"
                        class="mobile-nav-toggle flex h-16 min-h-0 w-full items-center justify-between gap-3 px-4 text-sm font-semibold leading-none"
                        aria-controls="mobile-primary-navigation"
                        aria-expanded="false"
                    >
                        <span class="flex min-w-0 items-center gap-2" data-mobile-header-brand>
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center">
                                <img
                                    src="{{ asset('images/icon/instruments-desk-logo-icon.svg') }}"
                                    alt="Instruments Desk"
                                    class="block h-8 w-8 object-contain"
                                >
                            </span>
                            <span class="truncate">
                                <span class="font-medium text-brand-soft">Instruments</span>
                                <span>Desk</span>
                            </span>
                        </span>
                        <span class="flex items-center gap-2" data-mobile-header-menu>
                            <span>Menu</span>
                            <span class="mobile-nav-icon" aria-hidden="true">
                                <span></span>
                                <span></span>
                                <span></span>
                            </span>
                        </span>
                    </button>
                    <div id="mobile-primary-navigation" class="mobile-nav-panel" aria-hidden="true">
                        <div class="mobile-nav-panel-inner">
                            <div class="flex min-h-0 flex-col px-3 pt-3 pb-4">
                                <div>
                                    <x-app-nav />
                                </div>
                                <x-user-profile-menu mobile />
                            </div>
                        </div>
                    </div>
                </header>

                <div id="main" class="min-w-0">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @livewireScripts

        @if (session('status'))
            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    window.dispatchEvent(new CustomEvent('instruments-desk-flash-notification', {
                        detail: {
                            type: 'success',
                            message: @js(session('status')),
                        },
                    }));
                }, { once: true });
            </script>
        @endif
    </body>
</html>
