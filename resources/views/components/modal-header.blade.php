@props(['icon' => 'plus'])

<div class="flex items-start justify-between gap-3 bg-navy px-5 py-4 text-white">
    <div class="flex min-w-0 items-start gap-3">
        <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white/15 text-white" aria-hidden="true">
            @if ($icon === 'pencil')
                <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4 16 10.5-10.5a2.12 2.12 0 0 1 3 3L7 19H4v-3Z" />
                    <path stroke-linecap="round" d="m13 7 4 4" />
                </svg>
            @elseif ($icon === 'user-plus')
                <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.5a6 6 0 0 0-12 0M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM19 8v6M16 11h6" />
                </svg>
            @elseif ($icon === 'user-edit')
                <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.5a6 6 0 0 0-12 0M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM15 16.5l4.75-4.75a1.77 1.77 0 0 1 2.5 2.5L17.5 18.99 14 20l1-3.5Z" />
                </svg>
            @elseif ($icon === 'key')
                <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <circle cx="8.5" cy="15.5" r="4" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="m11.5 12.5 8-8M16 5l3 3M14 7l3 3" />
                </svg>
            @elseif ($icon === 'settings-2')
                <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" d="M20 7h-9M14 17H5" />
                    <circle cx="7" cy="7" r="3" />
                    <circle cx="17" cy="17" r="3" />
                </svg>
            @else
                <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" d="M12 5v14M5 12h14" />
                </svg>
            @endif
        </span>

        <div class="min-w-0">
            {{ $title }}
            @isset($subtitle)
                {{ $subtitle }}
            @endisset
        </div>
    </div>

    @isset($close)
        {{ $close }}
    @endisset
</div>
