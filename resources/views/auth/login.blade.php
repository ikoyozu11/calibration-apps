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
                    <label for="email" class="block text-sm font-medium text-navy">Email</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                        autocomplete="username"
                        class="mt-1.5 w-full rounded-xl border border-navy/15 bg-canvas px-3 py-2.5 text-sm text-ink shadow-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/30"
                    >
                    @error('email')
                        <p class="mt-1.5 text-sm text-overdue" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-navy">Password</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="mt-1.5 w-full rounded-xl border border-navy/15 bg-canvas px-3 py-2.5 text-sm text-ink shadow-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/30"
                    >
                    @error('password')
                        <p class="mt-1.5 text-sm text-overdue" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <label class="flex items-center gap-2 text-sm text-muted">
                    <input
                        type="checkbox"
                        name="remember"
                        value="1"
                        class="size-4 rounded border-navy/25 text-brand focus:ring-brand/30"
                        @checked(old('remember'))
                    >
                    Remember me
                </label>

                <button
                    type="submit"
                    class="mt-2 inline-flex w-full items-center justify-center rounded-xl bg-brand px-4 py-2.5 text-sm font-semibold text-white shadow-[0_12px_20px_-12px_rgb(30_86_160/0.9)] transition hover:bg-navy focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                >
                    Sign in
                </button>
            </div>
        </form>
    </div>
@endsection
