@php
    $items = [
        ['label' => 'Home', 'href' => route('home'), 'active' => request()->routeIs('home'), 'icon' => 'home'],
    ];

    if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->allows('instruments.view')) {
        $items[] =
            ['label' => 'Instruments', 'href' => route('instruments.index'), 'active' => request()->routeIs('instruments.*'), 'icon' => 'instrument'];
    }

    $items[] = ['label' => 'Calibrations', 'href' => route('calibrations.index'), 'active' => request()->routeIs('calibrations.*'), 'icon' => 'calibration'];

    if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->allows('masterdata.view')) {
        array_push($items,
            ['label' => 'Standards', 'href' => route('standards.index'), 'active' => request()->routeIs('standards.*'), 'icon' => 'standard'],
            ['label' => 'Activities', 'href' => route('activities.index'), 'active' => request()->routeIs('activities.*'), 'icon' => 'activity'],
        );
    }

    if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->allows('users.manage')) {
        $items[] = ['label' => 'User Management', 'href' => route('users.index'), 'active' => request()->routeIs('users.*'), 'icon' => 'users'];
    }
@endphp

<nav aria-label="Primary" class="flex flex-col gap-1">
    @foreach ($items as $item)
        <a
            href="{{ $item['href'] }}"
            @class([
                'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white',
                'bg-brand-soft text-navy shadow-[inset_0_1px_0_rgb(255_255_255/0.65)]' => $item['active'],
                'text-white hover:bg-white/10' => ! $item['active'],
            ])
            @if ($item['active']) aria-current="page" @endif
        >
            @if ($item['icon'] === 'home')
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-9.5Z" />
                </svg>
            @elseif ($item['icon'] === 'instrument')
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 3h8v4H8V3Zm0 4h8l1 14H7L8 7Z" />
                </svg>
            @elseif ($item['icon'] === 'standard')
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h10M4 18h7M16 14l2 2 4-4" />
                </svg>
            @elseif ($item['icon'] === 'activity')
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01" />
                </svg>
            @elseif ($item['icon'] === 'users')
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-3A4.5 4.5 0 0 0 4 18.5V20m6-10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm6.5-3a3 3 0 1 1 0 6m.5 2h.5a4 4 0 0 1 4 4v1" />
                </svg>
            @else
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 3v4M16 3v4M4 9h16M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" />
                </svg>
            @endif
            {{ $item['label'] }}
        </a>
    @endforeach

</nav>
