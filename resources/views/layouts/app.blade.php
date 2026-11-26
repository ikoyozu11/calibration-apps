@php
    $routeName = request()->route()?->getName();
    $calibrationTab = request()->query('tab', 'reports');

    if (! in_array($calibrationTab, ['reports', 'start', 'history'], true)) {
        $calibrationTab = 'reports';
    }

    if ($calibrationTab === 'history' && ! app(\Illuminate\Contracts\Auth\Access\Gate::class)->allows(\App\Authorization\ApplicationCapability::SessionHistoryView->value)) {
        $calibrationTab = 'reports';
    }

    if ($calibrationTab === 'start' && ! app(\Illuminate\Contracts\Auth\Access\Gate::class)->allows(\App\Authorization\ApplicationCapability::SessionStart->value)) {
        $calibrationTab = 'reports';
    }

    $calibrationTabLabels = [
        'reports' => 'Reports',
        'start' => 'Start Calibration',
        'history' => 'Session History',
    ];

    $breadcrumbs = match (true) {
        $routeName === 'home' => [['label' => 'Home', 'href' => null]],
        in_array($routeName, ['instruments.index', 'standards.index', 'activities.index'], true) => [['label' => 'Management', 'href' => null]],
        in_array($routeName, ['instruments.show', 'instruments.types.show', 'instruments.types.test-components', 'instruments.types.standards'], true) => [
            ['label' => 'Management', 'href' => route('instruments.index')],
            ['label' => $routeName === 'instruments.types.show' || $routeName === 'instruments.show' ? 'Instrument Detail' : ($routeName === 'instruments.types.test-components' ? 'Test Components' : 'Instrument Standards'), 'href' => null],
        ],
        $routeName === 'instruments.types.calibrations.start' => [
            ['label' => 'Calibrations', 'href' => route('calibrations.index', ['tab' => 'start'])],
            ['label' => 'Start Calibration', 'href' => null],
        ],
        $routeName === 'calibrations.index' => [
            ['label' => 'Calibrations', 'href' => route('calibrations.index')],
            ['label' => $calibrationTabLabels[$calibrationTab], 'href' => null],
        ],
        in_array($routeName, ['calibrations.show', 'calibrations.report-detail'], true) => [
            ['label' => 'Calibrations', 'href' => route('calibrations.index')],
            ['label' => 'Calibration Detail', 'href' => null],
        ],
        $routeName === 'standards.index' => [['label' => 'Standards', 'href' => null]],
        $routeName === 'activities.index' => [['label' => 'Activities', 'href' => null]],
        $routeName === 'users.index' => [['label' => 'User Management', 'href' => null]],
        $routeName === 'profile.show' => [['label' => 'Profile', 'href' => null]],
        $routeName === 'certificates.show' => [
            ['label' => 'Calibrations', 'href' => route('calibrations.index')],
            ['label' => 'Certificate', 'href' => null],
        ],
        default => [['label' => 'Home', 'href' => null]],
    };
@endphp
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
                try {
                    if (localStorage.getItem('instrumentsDesk.sidebarCollapsed') === 'true') {
                        document.documentElement.dataset.sidebarCollapsed = 'true';
                    }
                } catch {
                    // Storage may be unavailable; the expanded shell remains the safe fallback.
                }

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

        <div data-app-shell class="min-h-screen">
            <aside id="application-sidebar" data-dashboard-sidebar data-app-sidebar class="app-sidebar fixed inset-y-0 left-0 z-30 hidden w-64 flex-col gap-8 overflow-y-auto px-4 py-6 shadow-[8px_0_28px_-18px_rgb(20_46_76/0.7)] lg:flex">
                <div class="app-sidebar-brand flex flex-col gap-3 px-1">
                    <div class="flex items-center justify-center">
                        <img
                            src="{{ asset('images/icon/instruments-desk-logo-icon.svg') }}"
                            alt="Instruments Desk"
                            class="app-sidebar-brand-mark object-contain"
                        >
                    </div>
                    <div data-sidebar-wordmark class="px-1">
                        <p class="text-[11px] font-medium uppercase tracking-[0.22em] text-brand-soft">Instruments</p>
                        <p class="mt-1 text-xl font-semibold leading-none tracking-tight">Desk</p>
                    </div>
                </div>
                <div class="flex min-h-0 flex-1 flex-col">
                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <x-app-nav />
                    </div>
                </div>
            </aside>

            <div class="app-shell-main min-w-0 lg:pl-64">
                <header data-global-header class="sticky top-0 z-20 hidden min-h-16 items-center justify-between gap-4 border-b border-line/80 bg-white/95 px-6 py-3 shadow-[0_8px_24px_-24px_rgb(22_49_114/0.7)] backdrop-blur lg:flex xl:px-10">
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <button type="button" data-sidebar-toggle class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-transparent text-muted transition-colors duration-200 hover:bg-brand-soft/40 hover:text-navy focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" aria-controls="application-sidebar" aria-expanded="true" aria-pressed="false" aria-label="Collapse sidebar" title="Collapse sidebar">
                            <svg data-sidebar-toggle-icon class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect width="7" height="18" x="3" y="3" rx="1" />
                                <path data-sidebar-toggle-close d="m16 15-3-3 3-3" />
                                <path data-sidebar-toggle-open class="hidden" d="m13 9 3 3-3 3" />
                            </svg>
                        </button>

                        <nav aria-label="Breadcrumb" class="min-w-0">
                            <ol class="flex min-w-0 items-center gap-2 text-sm">
                                @foreach ($breadcrumbs as $index => $breadcrumb)
                                    @if ($index > 0)
                                        <li class="text-muted/60" aria-hidden="true">/</li>
                                    @endif
                                    <li class="min-w-0 truncate">
                                        @if ($breadcrumb['href'] && $index < count($breadcrumbs) - 1)
                                            <a href="{{ $breadcrumb['href'] }}" class="font-medium text-muted transition-colors hover:text-brand focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">{{ $breadcrumb['label'] }}</a>
                                        @else
                                            <span data-breadcrumb-current class="font-semibold text-navy" aria-current="page">{{ $breadcrumb['label'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        </nav>
                    </div>

                    <div class="flex shrink-0 items-center">
                        @if (config('calibration_notification_center.enabled', false))
                            <livewire:calibration-notification-center />
                        @endif
                        <x-user-profile-menu header />
                    </div>
                </header>

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
                                @if (config('calibration_notification_center.enabled', false))
                                    <div class="border-b border-white/15 px-1 pb-3">
                                        <livewire:calibration-notification-center :mobile="true" />
                                    </div>
                                @endif
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
