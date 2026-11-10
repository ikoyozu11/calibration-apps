<?php

use App\Authorization\ApplicationCapability;
use App\Authorization\ApplicationRoleName;
use App\CalibrationSessions\CalibrationHttpConnection;
use App\Authorization\ManageApplicationUsers;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $roleFilter = '';

    public string $statusFilter = '';

    public ?int $editingUserId = null;

    public ?int $resettingUserId = null;

    public bool $formOpen = false;

    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $role = 'Viewer';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        Gate::authorize(ApplicationCapability::UsersManage->value);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->authorizeManagement();
        $this->resetForm();
        $this->formOpen = true;
        $this->resetValidation();
    }

    public function openEdit(int $userId): void
    {
        $this->authorizeManagement();

        $user = $this->userRecord($userId);

        if ($user === null) {
            $this->addError('form', 'The selected user could not be found.');

            return;
        }

        $this->editingUserId = $userId;
        $this->resettingUserId = null;
        $this->formOpen = true;
        $this->name = (string) $user->name;
        $this->username = (string) ($user->username ?? '');
        $this->email = (string) $user->email;
        $this->role = (string) ($user->role_name ?? ApplicationRoleName::Viewer->value);
        $this->password = '';
        $this->password_confirmation = '';
        $this->resetValidation();
    }

    public function openResetPassword(int $userId): void
    {
        $this->authorizeManagement();

        if ($this->userRecord($userId) === null) {
            $this->addError('form', 'The selected user could not be found.');

            return;
        }

        $this->editingUserId = null;
        $this->resettingUserId = $userId;
        $this->formOpen = true;
        $this->password = '';
        $this->password_confirmation = '';
        $this->resetValidation();
    }

    public function closeForms(): void
    {
        $this->resetForm();
        $this->formOpen = false;
        $this->resetValidation();
    }

    public function saveUser(): void
    {
        $this->authorizeManagement();

        $this->validate($this->userRules(), $this->passwordMessages());

        try {
            $data = [
                'name' => trim($this->name),
                'username' => trim($this->username),
                'email' => trim($this->email),
                'role' => $this->role,
                'password' => $this->password,
            ];

            if ($this->editingUserId === null) {
                app(ManageApplicationUsers::class)->create($data);
                $message = 'User created successfully.';
            } else {
                unset($data['password']);
                app(ManageApplicationUsers::class)->update($this->editingUserId, $data, (int) auth()->id());
                $message = 'User updated successfully.';
            }
        } catch (\RuntimeException $exception) {
            $this->addError('form', $exception->getMessage());

            return;
        }

        $this->closeForms();
        $this->dispatch('instruments-desk-notification', type: 'success', message: $message);
    }

    public function resetPassword(): void
    {
        $this->authorizeManagement();
        $this->validate(['password' => $this->passwordRules()], $this->passwordMessages());

        try {
            app(ManageApplicationUsers::class)->resetPassword($this->resettingUserId ?? 0, $this->password);
        } catch (\RuntimeException $exception) {
            $this->addError('form', $exception->getMessage());

            return;
        }

        $this->closeForms();
        $this->dispatch('instruments-desk-notification', type: 'success', message: 'Password reset successfully.');
    }

    public function toggleStatus(int $userId): void
    {
        $this->authorizeManagement();

        $user = $this->userRecord($userId);

        if ($user === null) {
            $this->dispatch('instruments-desk-notification', type: 'error', message: 'The selected user could not be found.');

            return;
        }

        try {
            app(ManageApplicationUsers::class)->setActive(
                $userId,
                $user->account_status !== 'Active',
                (int) auth()->id(),
            );
        } catch (\RuntimeException $exception) {
            $this->dispatch('instruments-desk-notification', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->dispatch('instruments-desk-notification', type: 'success', message: $user->account_status === 'Active'
            ? 'User deactivated successfully.'
            : 'User activated successfully.');
    }

    /**
     * @return LengthAwarePaginator<int, object>
     */
    public function users(): LengthAwarePaginator
    {
        $query = $this->userQuery();

        if ($this->search !== '') {
            $search = '%'.mb_strtolower(trim($this->search)).'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->whereRaw('lower(users.name) like ?', [$search])
                    ->orWhereRaw('lower(users.email) like ?', [$search])
                    ->orWhereRaw('lower(identity.username) like ?', [$search]);
            });
        }

        if ($this->roleFilter !== '') {
            $query->where('roles.name', $this->roleFilter);
        }

        if ($this->statusFilter === 'active') {
            $query->where('identity.is_active', true)
                ->where('mapping.status', 'active')
                ->whereNull('mapping.inactivated_at');
        }

        if ($this->statusFilter === 'inactive') {
            $query->where(function (Builder $query): void {
                $query->whereNull('mapping.laravel_user_id')
                    ->orWhere('identity.is_active', false)
                    ->orWhere('mapping.status', '!=', 'active')
                    ->orWhereNotNull('mapping.inactivated_at');
            });
        }

        return $query->orderBy('users.name')->paginate(10);
    }

    /** @return array<string, array<int, mixed>> */
    private function userRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', 'in:'.implode(',', ApplicationRoleName::values())],
            'password' => $this->editingUserId === null
                ? $this->passwordRules()
                : ['nullable', 'string'],
        ];
    }

    /** @return array<int, string> */
    private function passwordRules(): array
    {
        return [
            'required',
            'string',
            'min:8',
            'regex:/[A-Za-z]/',
            'regex:/[0-9]/',
            'confirmed',
        ];
    }

    /** @return array<string, string> */
    private function passwordMessages(): array
    {
        return [
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.regex' => 'Password must contain at least one letter and one number.',
            'password.confirmed' => 'Passwords do not match.',
        ];
    }

    /** @return list<string> */
    public function roleOptions(): array
    {
        return ApplicationRoleName::values();
    }

    public function resettingUserName(): string
    {
        if ($this->resettingUserId === null) {
            return '';
        }

        return (string) ($this->userRecord($this->resettingUserId)?->name ?? 'selected user');
    }

    private function authorizeManagement(): void
    {
        Gate::authorize(ApplicationCapability::UsersManage->value);
    }

    private function resetForm(): void
    {
        $this->editingUserId = null;
        $this->resettingUserId = null;
        $this->formOpen = false;
        $this->name = '';
        $this->username = '';
        $this->email = '';
        $this->role = ApplicationRoleName::Viewer->value;
        $this->password = '';
        $this->password_confirmation = '';
    }

    private function userQuery(): Builder
    {
        return app(CalibrationHttpConnection::class)->resolve()->table('users')
            ->leftJoin('user_app_user_map as mapping', 'mapping.laravel_user_id', '=', 'users.id')
            ->leftJoin('app_user as identity', 'identity.app_user_id', '=', 'mapping.app_user_id')
            ->leftJoin('user_roles as assignments', 'assignments.laravel_user_id', '=', 'users.id')
            ->leftJoin('roles', 'roles.id', '=', 'assignments.role_id')
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'identity.username',
                'roles.name as role_name',
            ])
            ->selectRaw("case when identity.is_active is true and mapping.status = 'active' and mapping.inactivated_at is null then 'Active' else 'Inactive' end as account_status");
    }

    private function userRecord(int $userId): ?object
    {
        return $this->userQuery()->where('users.id', $userId)->first();
    }
};
?>

<div class="px-4 py-8 sm:px-6 lg:px-10" data-component-id="{{ $this->getId() }}">
    <div class="mx-auto flex max-w-7xl flex-col gap-6">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-brand">Administration</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-navy">User Management</h1>
                <p class="mt-1 text-sm text-muted">Manage application users and access.</p>
            </div>
            <button type="button" wire:click="openCreate" class="ui-button-primary gap-2">
                <span class="text-lg leading-none" aria-hidden="true">+</span>
                Add User
            </button>
        </header>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="user-management-table-heading">
            <div class="sr-only" id="user-management-table-heading">Application users</div>
            <div class="grid gap-3 border-y border-brand/15 px-5 py-4 sm:grid-cols-3">
                <label class="text-sm font-medium text-navy sm:col-span-1">
                    Search
                    <input wire:model.live.debounce.300ms="search" type="search" class="ui-input mt-1 w-full" placeholder="Name, username, or email">
                </label>
                <label class="text-sm font-medium text-navy">
                    Role
                    <select wire:model.live="roleFilter" class="ui-input mt-1 w-full">
                        <option value="">All roles</option>
                        @foreach ($this->roleOptions() as $roleOption)
                            <option value="{{ $roleOption }}">{{ $roleOption }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm font-medium text-navy">
                    Status
                    <select wire:model.live="statusFilter" class="ui-input mt-1 w-full">
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </label>
            </div>

            <div class="overflow-x-auto">
                <table class="ui-table w-full table-fixed min-w-max xl:min-w-0">
                    <colgroup>
                        <col class="w-[14%]">
                        <col class="w-[12%]">
                        <col class="w-[18%]">
                        <col class="w-[11%]">
                        <col class="w-[10%]">
                        <col class="w-[35%]">
                    </colgroup>
                    <thead class="bg-navy text-xs font-medium text-white">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-medium">Full Name</th>
                            <th scope="col" class="px-5 py-3 font-medium">Username</th>
                            <th scope="col" class="px-5 py-3 font-medium">Email</th>
                            <th scope="col" class="px-5 py-3 font-medium">Application Role</th>
                            <th scope="col" class="px-5 py-3 font-medium">Account Status</th>
                            <th scope="col" class="px-5 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/70">
                        @forelse ($this->users() as $user)
                            <tr wire:key="managed-user-{{ $user->id }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec] hover:bg-[#edf4fa]">
                                <td class="px-5 py-3.5 font-medium text-navy"><span class="block truncate" title="{{ $user->name }}">{{ $user->name }}</span></td>
                                <td class="px-5 py-3.5 text-navy"><span class="block truncate" title="{{ $user->username ?? 'Unavailable' }}">{{ $user->username ?? 'Unavailable' }}</span></td>
                                <td class="break-words px-5 py-3.5 text-navy">{{ $user->email }}</td>
                                <td class="px-5 py-3.5 text-navy"><span class="block truncate" title="{{ $user->role_name ?? 'Unavailable' }}">{{ $user->role_name ?? 'Unavailable' }}</span></td>
                                <td class="whitespace-nowrap px-5 py-3.5">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $user->account_status === 'Active' ? 'bg-emerald-50 text-on-track' : 'bg-slate-100 text-muted' }}">
                                        {{ $user->account_status }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5">
                                    <div class="flex items-center justify-end gap-2">
                                        <button type="button" wire:click="openEdit({{ $user->id }})" class="btn-edit shrink-0">
                                            <svg aria-hidden="true" class="size-4" viewBox="0 0 20 20" fill="currentColor">
                                                <path d="m13.7 2.3 4 4a1 1 0 0 1 0 1.4l-9.5 9.5-5.2 1.2 1.2-5.2 9.5-9.5a1 1 0 0 1 1.4 0ZM5.1 14.1l-.5 2.3 2.3-.5 7.7-7.7-1.8-1.8-7.7 7.7Z" />
                                            </svg>
                                            Edit
                                        </button>
                                        <button type="button" wire:click="openResetPassword({{ $user->id }})" class="btn-select shrink-0">
                                            <svg aria-hidden="true" class="size-4" viewBox="0 0 20 20" fill="currentColor">
                                                <path d="M11.8 2.5a4.7 4.7 0 0 0-4.5 6.1L2.8 13a1 1 0 0 0-.3.7v2.8h2.8v-1.8h1.8v-1.8h1.8l1.6-1.6a4.7 4.7 0 1 0 1.3-8.8Zm2.5 4.7a1.2 1.2 0 1 1-2.4 0 1.2 1.2 0 0 1 2.4 0Z" />
                                            </svg>
                                            Reset Password
                                        </button>
                                        @if ($user->account_status === 'Active' && (int) $user->id === (int) auth()->id())
                                        <button type="button" class="btn-deactivate shrink-0 cursor-not-allowed opacity-60" disabled aria-disabled="true" title="You cannot deactivate your own account">
                                        @elseif ($user->account_status === 'Active')
                                        <button type="button" data-user-status data-user-action="deactivate" data-user-id="{{ $user->id }}" data-user-name="{{ $user->name }}" class="btn-deactivate shrink-0">
                                        @else
                                        <button type="button" data-user-status data-user-action="activate" data-user-id="{{ $user->id }}" data-user-name="{{ $user->name }}" class="btn-activate shrink-0">
                                        @endif
                                            <svg aria-hidden="true" class="size-4" viewBox="0 0 20 20" fill="currentColor">
                                                @if ($user->account_status === 'Active')
                                                    <path d="M10 2.2a7.8 7.8 0 1 0 7.8 7.8A7.8 7.8 0 0 0 10 2.2Zm0 14a6.2 6.2 0 1 1 6.2-6.2 6.2 6.2 0 0 1-6.2 6.2Zm-2.5-6.9h5a.8.8 0 1 1 0 1.6h-5a.8.8 0 1 1 0-1.6Z" />
                                                @else
                                                    <path d="M10 2.2a7.8 7.8 0 1 0 7.8 7.8A7.8 7.8 0 0 0 10 2.2Zm0 14a6.2 6.2 0 1 1 6.2-6.2 6.2 6.2 0 0 1-6.2 6.2Zm3.2-8.6-4 4-2.2-2.2a.8.8 0 0 0-1.1 1.1l2.8 2.8a.8.8 0 0 0 1.1 0l4.6-4.6a.8.8 0 0 0-1.1-1.1Z" />
                                                @endif
                                            </svg>
                                            {{ $user->account_status === 'Active' ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-8 text-center text-sm text-muted">No users found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-line px-5 py-4">
                {{ $this->users()->links() }}
            </div>
        </section>
    </div>

    @if ($formOpen)
        <div
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-navy/40 px-4 py-8 sm:items-center"
            x-data="{
                passwordVisible: false,
                passwordConfirmationVisible: false,
                resetPasswordVisible: false,
                resetPasswordConfirmationVisible: false,
                passwordTouched: false,
                passwordConfirmationTouched: false,
                passwordAttempted: false,
                passwordClientError: '',
                passwordConfirmationClientError: '',
                validatePassword(value) {
                    if (value === '') {
                        this.passwordClientError = 'Password is required.';
                    } else if (value.length < 8) {
                        this.passwordClientError = 'Password must be at least 8 characters.';
                    } else if (!/[A-Za-z]/.test(value) || !/[0-9]/.test(value)) {
                        this.passwordClientError = 'Password must contain at least one letter and one number.';
                    } else {
                        this.passwordClientError = '';
                    }
                },
                validatePasswordConfirmation(value, password) {
                    this.passwordConfirmationClientError = value !== password
                        ? 'Passwords do not match.'
                        : '';
                },
                validatePasswordFields() {
                    this.passwordAttempted = true;
                    this.passwordTouched = true;
                    this.passwordConfirmationTouched = true;
                    this.validatePassword($wire.password ?? '');
                    this.validatePasswordConfirmation($wire.password_confirmation ?? '', $wire.password ?? '');
                },
                passwordInput(value) {
                    this.passwordTouched = true;
                    this.validatePassword(value);
                    this.validatePasswordConfirmation($wire.password_confirmation ?? '', value);
                },
                passwordConfirmationInput(value) {
                    this.passwordConfirmationTouched = true;
                    this.validatePasswordConfirmation(value, $wire.password ?? '');
                }
            }"
            role="presentation"
        >
            <section class="w-full max-w-xl overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="user-form-heading">
                <x-modal-header :icon="$resettingUserId !== null ? 'key' : ($editingUserId !== null ? 'user-edit' : 'user-plus')">
                    <x-slot:title>
                        <h2 id="user-form-heading" class="text-base font-semibold tracking-tight text-white">{{ $resettingUserId !== null ? 'Reset Password' : ($editingUserId !== null ? 'Edit User' : 'Add User') }}</h2>
                    </x-slot:title>
                    <x-slot:subtitle>
                        <p class="mt-1 text-sm text-white/80">{{ $resettingUserId !== null ? 'Set a new password for '. $this->resettingUserName().'.' : 'Keep identity and access records synchronized.' }}</p>
                    </x-slot:subtitle>
                    <x-slot:close>
                        <button type="button" wire:click="closeForms" class="shrink-0 rounded-lg px-3 py-2 text-xl leading-none text-white/80 transition hover:bg-white/15 hover:text-white" aria-label="Close">&times;</button>
                    </x-slot:close>
                </x-modal-header>

                @if ($errors->has('form'))
                    <div class="mx-5 mt-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-overdue">{{ $errors->first('form') }}</div>
                @endif

                @if ($resettingUserId !== null)
                    <form wire:submit="resetPassword" x-on:submit="validatePasswordFields()" class="space-y-4 px-5 py-5">
                        <div>
                            <label for="reset-password" class="ui-label">New Password</label>
                            <div class="relative mt-1">
                                <input id="reset-password" wire:model="password" x-on:input="passwordInput($event.target.value)" x-bind:type="resetPasswordVisible ? 'text' : 'password'" x-bind:class="passwordClientError ? 'border-red-500 ring-1 ring-red-500' : ''" class="ui-input pr-11" autocomplete="new-password">
                                <button type="button" class="absolute inset-y-0 right-0 grid w-11 place-items-center text-navy/70 transition hover:text-brand focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-brand" x-on:click="resetPasswordVisible = ! resetPasswordVisible" x-bind:aria-label="resetPasswordVisible ? 'Hide password' : 'Show password'">
                                    <svg x-show="! resetPasswordVisible" x-cloak aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6-9.75-6-9.75-6Z" /><circle cx="12" cy="12" r="2.5" /></svg>
                                    <svg x-show="resetPasswordVisible" x-cloak aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18M10.58 10.58a2 2 0 0 0 2.83 2.83M9.88 5.09A10.9 10.9 0 0 1 12 4.75c6.25 0 9.75 7.25 9.75 7.25a17.6 17.6 0 0 1-3.02 3.95M6.23 6.23C3.75 8 2.25 12 2.25 12s3.5 7.25 9.75 7.25a10.8 10.8 0 0 0 4.36-.93" /></svg>
                                </button>
                            </div>
                            <p class="mt-1 text-xs text-muted">Password must be at least 8 characters and contain both letters and numbers.</p>
                            <p x-show="passwordClientError && (passwordTouched || passwordAttempted)" x-text="passwordClientError" class="ui-error" x-cloak></p>
                        </div>
                        @error('password') <p class="ui-error">{{ $message }}</p> @enderror
                        <div>
                            <label for="reset-password-confirmation" class="ui-label">Confirm New Password</label>
                            <div class="relative mt-1">
                                <input id="reset-password-confirmation" wire:model="password_confirmation" x-on:input="passwordConfirmationInput($event.target.value)" x-bind:type="resetPasswordConfirmationVisible ? 'text' : 'password'" x-bind:class="passwordConfirmationClientError ? 'border-red-500 ring-1 ring-red-500' : ''" class="ui-input pr-11" autocomplete="new-password">
                                <button type="button" class="absolute inset-y-0 right-0 grid w-11 place-items-center text-navy/70 transition hover:text-brand focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-brand" x-on:click="resetPasswordConfirmationVisible = ! resetPasswordConfirmationVisible" x-bind:aria-label="resetPasswordConfirmationVisible ? 'Hide password' : 'Show password'">
                                    <svg x-show="! resetPasswordConfirmationVisible" x-cloak aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6-9.75-6-9.75-6Z" /><circle cx="12" cy="12" r="2.5" /></svg>
                                    <svg x-show="resetPasswordConfirmationVisible" x-cloak aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18M10.58 10.58a2 2 0 0 0 2.83 2.83M9.88 5.09A10.9 10.9 0 0 1 12 4.75c6.25 0 9.75 7.25 9.75 7.25a17.6 17.6 0 0 1-3.02 3.95M6.23 6.23C3.75 8 2.25 12 2.25 12s3.5 7.25 9.75 7.25a10.8 10.8 0 0 0 4.36-.93" /></svg>
                                </button>
                            </div>
                            <p x-show="passwordConfirmationClientError && (passwordConfirmationTouched || passwordAttempted)" x-text="passwordConfirmationClientError" class="ui-error" x-cloak></p>
                        </div>
                        <div class="flex justify-end gap-3"><button type="button" wire:click="closeForms" class="ui-button-secondary">Cancel</button><button type="submit" class="ui-button-primary">Reset Password</button></div>
                    </form>
                @else
                    <form wire:submit="saveUser" x-on:submit="validatePasswordFields()" class="space-y-4 px-5 py-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="ui-label">Full Name<input wire:model="name" type="text" class="ui-input mt-1" autocomplete="name"></label>
                            <label class="ui-label">Username<input wire:model="username" type="text" class="ui-input mt-1" autocomplete="username"></label>
                        </div>
                        <label class="ui-label">Email<input wire:model="email" type="email" class="ui-input mt-1" autocomplete="email"></label>
                        <label class="ui-label">Application Role<select wire:model="role" class="ui-input mt-1">@foreach ($this->roleOptions() as $roleOption)<option value="{{ $roleOption }}">{{ $roleOption }}</option>@endforeach</select></label>
                        @if ($editingUserId === null)
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="user-password" class="ui-label">Password</label>
                                    <div class="relative mt-1">
                                        <input id="user-password" wire:model="password" x-on:input="passwordInput($event.target.value)" x-bind:type="passwordVisible ? 'text' : 'password'" x-bind:class="passwordClientError ? 'border-red-500 ring-1 ring-red-500' : ''" class="ui-input pr-11" autocomplete="new-password">
                                        <button type="button" class="absolute inset-y-0 right-0 grid w-11 place-items-center text-navy/70 transition hover:text-brand focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-brand" x-on:click="passwordVisible = ! passwordVisible" x-bind:aria-label="passwordVisible ? 'Hide password' : 'Show password'"><svg x-show="! passwordVisible" x-cloak aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6-9.75-6-9.75-6Z" /><circle cx="12" cy="12" r="2.5" /></svg><svg x-show="passwordVisible" x-cloak aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18M10.58 10.58a2 2 0 0 0 2.83 2.83M9.88 5.09A10.9 10.9 0 0 1 12 4.75c6.25 0 9.75 7.25 9.75 7.25a17.6 17.6 0 0 1-3.02 3.95M6.23 6.23C3.75 8 2.25 12 2.25 12s3.5 7.25 9.75 7.25a10.8 10.8 0 0 0 4.36-.93" /></svg></button>
                                    </div>
                                    <p class="mt-1 text-xs text-muted">Password must be at least 8 characters and contain both letters and numbers.</p>
                                    <p x-show="passwordClientError && (passwordTouched || passwordAttempted)" x-text="passwordClientError" class="ui-error" x-cloak></p>
                                </div>
                                <div>
                                    <label for="user-password-confirmation" class="ui-label">Confirm Password</label>
                                    <div class="relative mt-1">
                                        <input id="user-password-confirmation" wire:model="password_confirmation" x-on:input="passwordConfirmationInput($event.target.value)" x-bind:type="passwordConfirmationVisible ? 'text' : 'password'" x-bind:class="passwordConfirmationClientError ? 'border-red-500 ring-1 ring-red-500' : ''" class="ui-input pr-11" autocomplete="new-password">
                                        <button type="button" class="absolute inset-y-0 right-0 grid w-11 place-items-center text-navy/70 transition hover:text-brand focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-brand" x-on:click="passwordConfirmationVisible = ! passwordConfirmationVisible" x-bind:aria-label="passwordConfirmationVisible ? 'Hide password' : 'Show password'"><svg x-show="! passwordConfirmationVisible" x-cloak aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6-9.75-6-9.75-6Z" /><circle cx="12" cy="12" r="2.5" /></svg><svg x-show="passwordConfirmationVisible" x-cloak aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18M10.58 10.58a2 2 0 0 0 2.83 2.83M9.88 5.09A10.9 10.9 0 0 1 12 4.75c6.25 0 9.75 7.25 9.75 7.25a17.6 17.6 0 0 1-3.02 3.95M6.23 6.23C3.75 8 2.25 12 2.25 12s3.5 7.25 9.75 7.25a10.8 10.8 0 0 0 4.36-.93" /></svg></button>
                                    </div>
                                    <p x-show="passwordConfirmationClientError && (passwordConfirmationTouched || passwordAttempted)" x-text="passwordConfirmationClientError" class="ui-error" x-cloak></p>
                                </div>
                            </div>
                        @endif
                        <div class="flex justify-end gap-3"><button type="button" wire:click="closeForms" class="ui-button-secondary">Cancel</button><button type="submit" class="ui-button-primary">Save User</button></div>
                    </form>
                @endif
            </section>
        </div>
    @endif
</div>
