@extends('layouts.guest')

@section('content')
    <div data-login-page class="mx-auto min-h-screen w-full max-w-[1120px] xl:grid xl:grid-cols-[1.05fr_0.95fr] xl:gap-12">
        <div
            data-login-loader
            class="pointer-events-none fixed inset-0 z-[100] hidden grid place-items-center bg-white"
            aria-hidden="true"
        >
            <svg data-login-logo class="h-[76px] w-[76px] sm:h-[88px] sm:w-[88px]" width="88" height="88" viewBox="38 22 148 154" fill="none" role="img" aria-label="Instruments Desk">
                <defs>
                    <linearGradient id="loginLayerTop" x1="46" y1="49" x2="178" y2="94" gradientUnits="userSpaceOnUse">
                        <stop offset="0" stop-color="#0856AA" />
                        <stop offset="0.58" stop-color="#07458F" />
                        <stop offset="1" stop-color="#063A7E" />
                    </linearGradient>
                    <linearGradient id="loginLayerMiddle" x1="47" y1="91" x2="178" y2="135" gradientUnits="userSpaceOnUse">
                        <stop offset="0" stop-color="#0988ED" />
                        <stop offset="0.58" stop-color="#087CE1" />
                        <stop offset="1" stop-color="#0671D1" />
                    </linearGradient>
                    <linearGradient id="loginLayerBottom" x1="46" y1="123" x2="178" y2="165" gradientUnits="userSpaceOnUse">
                        <stop offset="0" stop-color="#55B6FA" />
                        <stop offset="0.55" stop-color="#4CAAF4" />
                        <stop offset="1" stop-color="#3F9DEB" />
                    </linearGradient>
                </defs>
                <path data-logo-layer="bottom" fill="url(#loginLayerBottom)" d="M51.2 125.3  C56.3 122.4 60.6 120.8 65.0 122.7  L105.2 144.2  C109.5 146.5 114.1 146.5 118.5 144.2  L159.2 122.6  C163.9 120.1 168.3 122.4 173.6 125.3  C181.0 129.5 180.9 135.9 173.4 140.0  L119.7 168.3  C115.0 170.8 109.4 170.8 104.7 168.3  L51.0 140.0  C43.7 136.0 43.8 129.4 51.2 125.3 Z" />
                <path data-logo-layer="middle" fill="url(#loginLayerMiddle)" d="M51.2 93.0  C56.2 90.1 60.7 88.3 65.2 90.6  L105.0 111.8  C109.4 114.2 114.1 114.2 118.5 111.8  L159.3 90.4  C163.9 88.0 168.3 90.2 173.5 93.0  C180.9 97.1 180.8 103.5 173.4 107.6  L119.7 135.6  C115.0 138.1 109.4 138.1 104.7 135.6  L51.0 107.6  C43.6 103.6 43.8 97.1 51.2 93.0 Z" />
                <path data-logo-layer="top" fill="url(#loginLayerTop)" d="M103.8 30.5  C109.2 27.6 114.9 27.6 120.2 30.5  L173.5 59.6  C180.8 63.6 180.9 70.6 173.5 74.7  L120.2 102.8  C114.9 105.7 109.2 105.7 103.8 102.8  L50.2 74.7  C42.8 70.6 42.9 63.6 50.2 59.6 Z" />
            </svg>
        </div>

        <section class="hidden min-h-screen items-center justify-center bg-white px-8 py-12 xl:flex xl:justify-end xl:px-0" aria-label="Instruments Desk overview">
            <img
                data-login-illustration
                src="{{ asset('images/auth/Instruments Desk Calibration Dashboard.webp') }}"
                alt="Instruments Desk calibration management overview"
                class="h-auto max-h-[min(78vh,760px)] w-full max-w-[920px] object-contain"
            >
        </section>

        <main class="flex min-h-screen flex-col items-center justify-center bg-white px-6 py-8 sm:px-10 sm:py-12 md:py-16 xl:flex-row xl:justify-start xl:px-0 xl:py-10">
            <div data-login-mobile-brand class="mb-7 flex w-full justify-center xl:hidden sm:mb-10">
                <img
                    src="{{ asset('images/auth/logo-text-only.png') }}"
                    alt="Instruments Desk"
                    class="h-auto w-full max-w-[300px] object-contain sm:max-w-[360px]"
                >
            </div>

            <div data-login-form-content class="w-full max-w-[440px] sm:max-w-[480px] xl:max-w-[420px]">
                <div data-login-heading class="mb-8 text-center lg:text-left">
                    <h1 class="mt-3 text-3xl font-semibold tracking-tight text-[#142D50]">Welcome back</h1>
                    <p class="mt-2 text-sm leading-6 text-muted">Log in to your Instruments Desk account.</p>
                </div>

                <form data-login-form method="POST" action="{{ route('login.store') }}" class="rounded-2xl border border-[#142D50]/10 bg-white p-6 shadow-[0_20px_50px_-28px_rgb(20_45_80/0.45)] sm:p-8">
                    @csrf

                    <div class="flex flex-col gap-5">
                        <div>
                            <label for="login" class="block text-sm font-medium text-[#142D50]">Email or username</label>
                            <input
                                id="login"
                                name="login"
                                type="text"
                                value="{{ old('login') }}"
                                required
                                autofocus
                                autocomplete="username"
                                class="mt-2 w-full rounded-xl border border-[#142D50]/15 bg-[#EAF2FC]/45 px-3.5 py-3 text-sm text-ink shadow-sm outline-none transition placeholder:text-muted/70 focus:border-[#245BA6] focus:ring-2 focus:ring-[#245BA6]/25"
                            >
                            @error('login')
                                <p class="mt-1.5 text-sm text-overdue" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-medium text-[#142D50]">Password</label>
                            <div class="relative mt-2">
                                <input
                                    id="password"
                                    name="password"
                                    type="password"
                                    required
                                    autocomplete="current-password"
                                    class="w-full rounded-xl border border-[#142D50]/15 bg-[#EAF2FC]/45 px-3.5 py-3 pr-12 text-sm text-ink shadow-sm outline-none transition focus:border-[#245BA6] focus:ring-2 focus:ring-[#245BA6]/25"
                                >
                                <button
                                    type="button"
                                    id="password-visibility"
                                    class="absolute inset-y-0 right-0 flex items-center px-3 text-muted transition hover:text-[#142D50] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#245BA6]"
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

                        <button type="submit" class="mt-1 inline-flex w-full items-center justify-center rounded-xl bg-[#245BA6] px-4 py-3 text-sm font-semibold text-white shadow-[0_12px_20px_-12px_rgb(36_91_166/0.9)] transition hover:bg-[#142D50] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#245BA6]">
                            Log in
                        </button>
                    </div>
                </form>

                <p class="mt-8 text-center text-xs text-muted">Copyright © 2026 Instruments Desk. All rights reserved.</p>
            </div>
        </main>
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
