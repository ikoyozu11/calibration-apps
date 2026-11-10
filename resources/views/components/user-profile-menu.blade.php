@props(['mobile' => false])

@php
    /** @var array{full_name: string, username: string, email: string, role: string, account_status: string, initials: string} $profile */
    $profile = app(\App\Authorization\AuthenticatedUserProfile::class)->resolve(auth()->user());
@endphp

@if ($mobile)
    <div class="shrink-0 border-t border-white/15 px-1 pt-3 pb-[env(safe-area-inset-bottom)]">
        <div class="flex min-w-0 items-center gap-3 px-2 py-2">
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-brand-soft text-sm font-bold text-navy" aria-hidden="true">
                {{ $profile['initials'] }}
            </span>
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-white">{{ $profile['full_name'] }}</p>
                <p class="truncate text-xs text-brand-soft">{{ $profile['role'] }}</p>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-2 pt-2">
            <a
                href="{{ route('profile.show') }}"
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-brand-soft px-3 py-2 text-sm font-semibold text-navy hover:bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
            >
                My Profile
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button
                    type="submit"
                    class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-lg bg-white/10 px-3 py-2 text-sm font-semibold text-white hover:bg-white/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                >
                    Log out
                </button>
            </form>
        </div>
    </div>
@else
    <div
        x-data="{ open: false }"
        x-on:keydown.escape.window="open = false"
        x-on:click.outside="open = false"
        class="relative shrink-0 border-t border-white/15 pt-3 pb-[env(safe-area-inset-bottom)]"
    >
        <button
            type="button"
            class="flex min-h-11 w-full min-w-0 items-center gap-3 rounded-xl px-2 py-2 text-left transition-colors duration-150 hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
            aria-haspopup="menu"
            :aria-expanded="open ? 'true' : 'false'"
            x-on:click="open = !open"
        >
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-brand-soft text-sm font-bold text-navy" aria-hidden="true">
                {{ $profile['initials'] }}
            </span>
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold text-white">{{ $profile['full_name'] }}</span>
                <span class="block truncate text-xs text-brand-soft">{{ $profile['role'] }}</span>
            </span>
            <svg class="size-4 shrink-0 text-brand-soft transition-transform duration-150" :class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
            </svg>
        </button>

        <div
            x-cloak
            x-show="open"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="translate-y-1 opacity-0"
            x-transition:enter-end="translate-y-0 opacity-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="translate-y-0 opacity-100"
            x-transition:leave-end="translate-y-1 opacity-0"
            class="absolute inset-x-0 bottom-full z-50 mb-3 overflow-hidden rounded-xl border border-white/15 bg-navy text-white shadow-xl"
            role="menu"
        >
            <div class="grid gap-2 p-2">
                <a
                    href="{{ route('profile.show') }}"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-brand-soft px-3 py-2 text-sm font-semibold text-navy hover:bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-white"
                    role="menuitem"
                    x-on:click="open = false"
                >
                    <svg class="size-4 shrink-0 text-navy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.5a6 6 0 0 0-6 0M12 13.5a3.25 3.25 0 1 0 0-6.5 3.25 3.25 0 0 0 0 6.5ZM4.5 20.25h15" />
                    </svg>
                    My Profile
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button
                        type="submit"
                        class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-lg bg-white/10 px-3 py-2 text-sm font-semibold text-white hover:bg-white/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-white"
                        role="menuitem"
                    >
                        <svg class="size-4 shrink-0 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l3 3m0 0-3 3m3-3H9" />
                        </svg>
                        Log out
                    </button>
                </form>
            </div>
        </div>
    </div>
@endif
