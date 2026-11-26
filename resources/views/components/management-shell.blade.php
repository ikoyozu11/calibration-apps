@props(['active'])

@php
    $canViewInstruments = app(\Illuminate\Contracts\Auth\Access\Gate::class)->allows('instruments.view');
    $canViewMasterData = app(\Illuminate\Contracts\Auth\Access\Gate::class)->allows('masterdata.view');
    $overview = \App\Management\ManagementOverview::current(app(\App\CalibrationSessions\CalibrationSessionHttpRead::class)->connection());
    $tabs = [];

    if ($canViewInstruments) {
        $tabs[] = ['key' => 'instruments', 'label' => 'Instruments', 'href' => route('instruments.index')];
    }

    if ($canViewMasterData) {
        $tabs[] = ['key' => 'standards', 'label' => 'Standards', 'href' => route('standards.index')];
        $tabs[] = ['key' => 'activities', 'label' => 'Activities', 'href' => route('activities.index')];
    }
@endphp

<div class="mx-auto flex max-w-[1760px] flex-col gap-6 xl:gap-8">
    <section data-management-hero class="overflow-hidden rounded-2xl bg-gradient-to-br from-[#14263F] via-[#1D4E73] to-[#365F83] p-4 text-white shadow-[0_22px_44px_-28px_rgb(20_38_63/0.8)] sm:p-6">
        <header data-management-header @if ($active === 'instruments') data-instruments-header @endif>
            <h1 class="text-2xl font-semibold tracking-tight text-white">Management</h1>
            <p class="mt-1 text-sm text-blue-100/75">Manage instruments, standards, and calibration activities.</p>
        </header>

        <div class="mt-5 h-px bg-white/20" aria-hidden="true"></div>

        <section aria-label="Management statistics" class="mt-4 grid grid-cols-2 gap-y-4 sm:gap-y-0 md:grid-cols-4">
            @foreach ([
                ['label' => 'Instruments', 'value' => $overview->instruments],
                ['label' => 'Locations', 'value' => $overview->locations],
                ['label' => 'Standards', 'value' => $overview->standards],
                ['label' => 'Activities', 'value' => $overview->activities],
            ] as $metric)
                <article data-management-stat @if ($active === 'instruments') data-instruments-stat @endif class="relative flex min-w-0 flex-col items-center gap-1 px-2 py-1 text-center sm:gap-2 sm:px-4">
                    @if ($loop->index > 0)
                        <span class="pointer-events-none absolute left-0 top-1/2 hidden h-10 w-px -translate-y-1/2 bg-white/15 md:block" aria-hidden="true"></span>
                    @endif
                    @if ($loop->index === 2)
                        <span class="pointer-events-none absolute left-0 top-0 h-full w-px bg-white/15 md:hidden" aria-hidden="true"></span>
                    @endif
                    <div class="flex items-center gap-2">
                        <span class="size-2 shrink-0 rounded-full bg-white/15" aria-hidden="true"></span>
                        <p class="text-[10px] font-medium leading-tight text-blue-50/80 sm:text-sm">{{ $metric['label'] }}</p>
                    </div>
                    <p class="text-2xl font-semibold tracking-tight tabular-nums text-white sm:text-4xl" data-management-counter="{{ $metric['value'] }}" @if ($active === 'instruments') data-instruments-counter="{{ $metric['value'] }}" @endif>{{ $metric['value'] }}</p>
                </article>
            @endforeach
        </section>
    </section>

    <nav aria-label="Management sections" class="overflow-x-auto overflow-y-hidden border-b border-line">
        <div role="tablist" class="flex min-w-max gap-1">
            @foreach ($tabs as $tab)
                <a
                    href="{{ $tab['href'] }}"
                    role="tab"
                    aria-selected="{{ $active === $tab['key'] ? 'true' : 'false' }}"
                    @class([
                        'relative rounded-t-lg px-4 py-3 text-sm font-semibold transition-colors duration-150 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:px-5',
                        'text-brand after:absolute after:inset-x-0 after:-bottom-px after:h-0.5 after:bg-brand' => $active === $tab['key'],
                        'text-muted hover:bg-brand-soft/40 hover:text-navy' => $active !== $tab['key'],
                    ])
                >
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </div>
    </nav>

    {{ $slot }}
</div>
