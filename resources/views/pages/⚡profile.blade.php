<?php

use App\Authorization\AuthenticatedUserProfile;
use App\Models\User;
use Livewire\Component;

new class extends Component
{
    /** @var array{full_name: string, username: string, email: string, role: string, account_status: string, initials: string} */
    public array $profile = [];

    public function mount(AuthenticatedUserProfile $profiles): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $this->profile = $profiles->resolve($user);
    }
};
?>

<div class="px-4 py-8 sm:px-6 lg:px-10">
    <div class="mx-auto flex max-w-3xl flex-col gap-6">
        <header>
            <a href="{{ route('home') }}" class="ui-button-secondary gap-2">
                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 12H5m7 7-7-7 7-7" />
                </svg>
                Back to Home
            </a>
            <p class="mt-5 text-sm font-medium text-brand">Account</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-navy">My Profile</h1>
            <p class="mt-1 text-sm text-muted">Your authenticated Instruments Desk identity.</p>
        </header>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="profile-heading">
            <div class="flex flex-col gap-4 bg-navy px-5 py-5 text-white sm:flex-row sm:items-center">
                <span class="grid size-16 shrink-0 place-items-center rounded-full bg-brand-soft text-xl font-bold text-navy" aria-hidden="true">
                    {{ $profile['initials'] }}
                </span>
                <div class="min-w-0">
                    <h2 id="profile-heading" class="truncate text-xl font-semibold">{{ $profile['full_name'] }}</h2>
                    <p class="mt-1 text-sm text-brand-soft">{{ $profile['role'] }}</p>
                </div>
            </div>

            <dl class="grid gap-4 p-5 sm:grid-cols-2">
                <div class="rounded-xl border border-brand/15 bg-white px-4 py-3">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted">Full Name</dt>
                    <dd class="mt-1 break-words text-sm font-semibold text-navy">{{ $profile['full_name'] }}</dd>
                </div>
                <div class="rounded-xl border border-brand/15 bg-white px-4 py-3">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted">Username</dt>
                    <dd class="mt-1 break-words text-sm font-semibold text-navy">{{ $profile['username'] }}</dd>
                </div>
                <div class="rounded-xl border border-brand/15 bg-white px-4 py-3">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted">Email</dt>
                    <dd class="mt-1 break-words text-sm font-semibold text-navy">{{ $profile['email'] }}</dd>
                </div>
                <div class="rounded-xl border border-brand/15 bg-white px-4 py-3">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted">Application Role</dt>
                    <dd class="mt-1 break-words text-sm font-semibold text-navy">{{ $profile['role'] }}</dd>
                </div>
                <div class="rounded-xl border border-brand/15 bg-white px-4 py-3 sm:col-span-2">
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted">Account Status</dt>
                    <dd class="mt-1 text-sm font-semibold {{ $profile['account_status'] === 'Active' ? 'text-on-track' : 'text-overdue' }}">{{ $profile['account_status'] }}</dd>
                </div>
            </dl>
        </section>
    </div>
</div>
