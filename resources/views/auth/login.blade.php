@extends('layouts.guest')

@section('content')
    <div class="w-full max-w-md">
        <div class="mb-8 flex flex-col items-center gap-4 text-center">
            <div class="flex items-center justify-center rounded-xl bg-white px-4 py-3 shadow-[0_8px_16px_-10px_rgb(0_0_0/0.45)]">
                <img
                    src="{{ asset('images/vision-teknik-logo.png') }}"
                    alt="Vision Teknik"
                    class="h-12 w-auto max-w-full object-contain"
                >
            </div>
            <div>
                <p class="text-[11px] font-medium uppercase tracking-[0.22em] text-brand">Instruments Desk</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-navy">Sign in</h1>
                <p class="mt-1 text-sm text-muted">Use your application account to continue.</p>
            </div>
        </div>

        <form
            method="POST"
            action="{{ route('login.store') }}"
            class="rounded-2xl bg-white p-6 shadow-[0_16px_28px_-16px_rgb(22_49_114/0.35)] ring-1 ring-navy/10"
        >
            @csrf

            <div class="flex flex-col gap-4">
                <div>
                    <label for="login" class="block text-sm font-medium text-navy">Email or username</label>
                    <input
                        id="login"
                        name="login"
                        type="text"
                        value="{{ old('login') }}"
                        required
                        autofocus
                        autocomplete="username"
                        class="mt-1.5 w-full rounded-xl border border-navy/15 bg-canvas px-3 py-2.5 text-sm text-ink shadow-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/30"
                    >
                    @error('login')
                        <p class="mt-1.5 text-sm text-overdue" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-navy">Password</label>
                    <div class="relative mt-1.5">
                        <input
                            id="password"
                            name="password"
                            type="password"
                            required
                            autocomplete="current-password"
                            class="w-full rounded-xl border border-navy/15 bg-canvas px-3 py-2.5 pr-11 text-sm text-ink shadow-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/30"
                        >
                        <button
                            type="button"
                            id="password-visibility"
                            class="absolute inset-y-0 right-0 flex items-center px-3 text-muted transition hover:text-navy focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                            aria-controls="password"
                            aria-pressed="false"
                            aria-label="Show password"
                            data-show-label="Show password"
                            data-hide-label="Hide password"
                        >
                            <svg id="password-icon-show" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            <svg id="password-icon-hide" class="hidden size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M9.9 9.9A3 3 0 0 0 12 15a3 3 0 0 0 2.1-.9M6.6 6.7C4.4 8.1 2.8 10.4 2.25 12c0 0 3.75 6.75 9.75 6.75 1.7 0 3.25-.35 4.6-.95M17.4 17.3C19.5 15.9 21.1 13.6 21.75 12c0 0-3.75-6.75-9.75-6.75-1.05 0-2.04.14-2.96.38" />
                            </svg>
                        </button>
                    </div>
                    @error('password')
                        <p class="mt-1.5 text-sm text-overdue" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <button
                    type="submit"
                    class="mt-2 inline-flex w-full items-center justify-center rounded-xl bg-brand px-4 py-2.5 text-sm font-semibold text-white shadow-[0_12px_20px_-12px_rgb(30_86_160/0.9)] transition hover:bg-navy focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                >
                    Sign in
                </button>
            </div>
        </form>
    </div>

    <script>
        (() => {
            const input = document.getElementById('password');
            const toggle = document.getElementById('password-visibility');
            const showIcon = document.getElementById('password-icon-show');
            const hideIcon = document.getElementById('password-icon-hide');

            if (! input || ! toggle || ! showIcon || ! hideIcon) {
                return;
            }

            toggle.addEventListener('click', () => {
                const visible = input.type === 'text';
                input.type = visible ? 'password' : 'text';
                toggle.setAttribute('aria-pressed', visible ? 'false' : 'true');
                toggle.setAttribute('aria-label', visible ? toggle.dataset.showLabel : toggle.dataset.hideLabel);
                showIcon.classList.toggle('hidden', ! visible);
                hideIcon.classList.toggle('hidden', visible);
            });
        })();
    </script>
@endsection
