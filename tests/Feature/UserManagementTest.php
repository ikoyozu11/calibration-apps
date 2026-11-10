<?php

use App\Authorization\ApplicationRoleName;
use App\Authorization\ManageApplicationUsers;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

function createUserManagementTables(): void
{
    Schema::create('app_user', function (Blueprint $table): void {
        $table->id('app_user_id');
        $table->string('username', 100)->unique();
        $table->string('full_name');
        $table->string('email')->nullable();
        $table->string('role_name', 50);
        $table->boolean('is_active')->default(true);
        $table->timestamp('created_at')->nullable();
    });

    Schema::create('user_app_user_map', function (Blueprint $table): void {
        $table->id('user_app_user_map_id');
        $table->unsignedBigInteger('laravel_user_id')->unique();
        $table->unsignedBigInteger('app_user_id')->unique();
        $table->string('status', 20);
        $table->timestamp('created_at')->nullable();
        $table->timestamp('inactivated_at')->nullable();
    });
}

function seedManagedIdentity(User $user, ApplicationRoleName $role, bool $active = true): void
{
    $appUserId = $user->id + 1000;

    DB::table('app_user')->insert([
        'app_user_id' => $appUserId,
        'username' => strtolower($role->value).$user->id,
        'full_name' => $user->name,
        'email' => $user->email,
        'role_name' => match ($role) {
            ApplicationRoleName::Administrator => 'ADMIN',
            ApplicationRoleName::Engineer => 'CALIBRATION_STAFF',
            ApplicationRoleName::Viewer => 'VIEWER',
        },
        'is_active' => $active,
        'created_at' => now(),
    ]);

    DB::table('user_app_user_map')->insert([
        'user_app_user_map_id' => $appUserId,
        'laravel_user_id' => $user->id,
        'app_user_id' => $appUserId,
        'status' => $active ? 'active' : 'inactive',
        'created_at' => now(),
        'inactivated_at' => $active ? null : now(),
    ]);
}

it('allows an Administrator to create a fully mapped user atomically', function (): void {
    createUserManagementTables();
    $admin = actingAsRole(ApplicationRoleName::Administrator, User::factory()->create(['name' => 'Admin']));
    seedManagedIdentity($admin, ApplicationRoleName::Administrator);

    Livewire::test('pages::users')
        ->call('openCreate')
        ->set('name', 'New Engineer')
        ->set('username', 'newengineer')
        ->set('email', 'newengineer@example.com')
        ->set('role', ApplicationRoleName::Engineer->value)
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('saveUser')
        ->assertHasNoErrors()
        ->assertSet('formOpen', false);

    $user = User::query()->where('email', 'newengineer@example.com')->firstOrFail();
    $appUser = DB::table('app_user')->where('username', 'newengineer')->first();

    expect($appUser)->not->toBeNull()
        ->and($appUser->role_name)->toBe('CALIBRATION_STAFF')
        ->and(Hash::check('password123', $user->password))->toBeTrue()
        ->and(DB::table('user_app_user_map')->where('laravel_user_id', $user->id)->where('status', 'active')->count())->toBe(1)
        ->and(DB::table('user_roles')->where('laravel_user_id', $user->id)->join('roles', 'roles.id', '=', 'user_roles.role_id')->value('roles.name'))->toBe('Engineer');
});

it('applies the same password policy to Add User and Reset Password', function (): void {
    createUserManagementTables();
    $admin = actingAsRole(ApplicationRoleName::Administrator, User::factory()->create(['name' => 'Admin']));
    seedManagedIdentity($admin, ApplicationRoleName::Administrator);

    Livewire::test('pages::users')
        ->call('openCreate')
        ->set('name', 'New Viewer')
        ->set('username', 'newviewer')
        ->set('email', 'newviewer@example.com')
        ->set('role', ApplicationRoleName::Viewer->value)
        ->set('password', '')
        ->set('password_confirmation', '')
        ->call('saveUser')
        ->assertHasErrors(['password' => 'Password is required.']);

    Livewire::test('pages::users')
        ->call('openCreate')
        ->set('name', 'New Viewer')
        ->set('username', 'newviewer')
        ->set('email', 'newviewer@example.com')
        ->set('role', ApplicationRoleName::Viewer->value)
        ->set('password', 'short1')
        ->set('password_confirmation', 'short1')
        ->call('saveUser')
        ->assertHasErrors(['password' => 'Password must be at least 8 characters.']);

    Livewire::test('pages::users')
        ->call('openCreate')
        ->set('name', 'New Viewer')
        ->set('username', 'newviewer')
        ->set('email', 'newviewer@example.com')
        ->set('role', ApplicationRoleName::Viewer->value)
        ->set('password', 'abcdefgh')
        ->set('password_confirmation', 'abcdefgh')
        ->call('saveUser')
        ->assertHasErrors(['password' => 'Password must contain at least one letter and one number.']);

    $engineer = User::factory()->create(['name' => 'Engineer']);
    seedManagedIdentity($engineer, ApplicationRoleName::Engineer);

    Livewire::test('pages::users')
        ->call('openResetPassword', $engineer->id)
        ->set('password', 'validpass1')
        ->set('password_confirmation', 'different1')
        ->call('resetPassword')
        ->assertHasErrors(['password' => 'Passwords do not match.']);
});

it('denies User Management to Engineer and Viewer at the route and Livewire mount', function (ApplicationRoleName $role): void {
    createUserManagementTables();
    actingAsRole($role);

    $this->get(route('users.index'))->assertForbidden();

    expect(Gate::allows('users.manage'))->toBeFalse();
})->with([ApplicationRoleName::Engineer, ApplicationRoleName::Viewer]);

it('updates identity records, resets passwords, and protects the last Administrator', function (): void {
    createUserManagementTables();
    $admin = actingAsRole(ApplicationRoleName::Administrator, User::factory()->create(['name' => 'Admin']));
    seedManagedIdentity($admin, ApplicationRoleName::Administrator);
    $engineer = actingAsRole(ApplicationRoleName::Engineer, User::factory()->create(['name' => 'Engineer']));
    seedManagedIdentity($engineer, ApplicationRoleName::Engineer);
    actingAsRole(ApplicationRoleName::Administrator, $admin);

    Livewire::test('pages::users')
        ->call('openEdit', $engineer->id)
        ->set('name', 'Updated Engineer')
        ->set('username', 'updated-engineer')
        ->set('email', 'updated-engineer@example.com')
        ->set('role', ApplicationRoleName::Viewer->value)
        ->call('saveUser')
        ->call('openResetPassword', $engineer->id)
        ->set('password', 'newpassword123')
        ->set('password_confirmation', 'newpassword123')
        ->call('resetPassword')
        ->assertHasNoErrors();

    expect(Hash::check('newpassword123', User::query()->findOrFail($engineer->id)->password))->toBeTrue()
        ->and(DB::table('app_user')->where('app_user_id', $engineer->id + 1000)->value('role_name'))->toBe('VIEWER')
        ->and(DB::table('user_roles')->where('laravel_user_id', $engineer->id)->join('roles', 'roles.id', '=', 'user_roles.role_id')->value('roles.name'))->toBe('Viewer');

    app(ManageApplicationUsers::class)->setActive($engineer->id, false, $admin->id);
    expect(DB::table('app_user')->where('app_user_id', $engineer->id + 1000)->value('is_active'))->toBe(0)
        ->and(DB::table('user_app_user_map')->where('laravel_user_id', $engineer->id)->value('status'))->toBe('inactive');

    app(ManageApplicationUsers::class)->setActive($engineer->id, true, $admin->id);

    expect(fn () => app(ManageApplicationUsers::class)->setActive($admin->id, false, $admin->id))
        ->toThrow(RuntimeException::class, 'cannot deactivate your own account');

    expect(fn () => app(ManageApplicationUsers::class)->update($admin->id, [
        'name' => 'Admin',
        'username' => 'administrator1',
        'email' => 'admin@example.com',
        'role' => ApplicationRoleName::Engineer->value,
    ], $admin->id))->toThrow(RuntimeException::class, 'cannot demote your own Administrator account');
});

it('rejects duplicate email or username without creating partial identities', function (): void {
    createUserManagementTables();
    $admin = actingAsRole(ApplicationRoleName::Administrator, User::factory()->create(['name' => 'Admin']));
    seedManagedIdentity($admin, ApplicationRoleName::Administrator);
    $existing = User::factory()->create(['email' => 'existing@example.com']);
    seedManagedIdentity($existing, ApplicationRoleName::Engineer);

    expect(fn () => app(ManageApplicationUsers::class)->create([
        'name' => 'Duplicate',
        'username' => 'engineer'.$existing->id,
        'email' => 'EXISTING@example.com',
        'role' => ApplicationRoleName::Viewer->value,
        'password' => 'password123',
    ]))->toThrow(RuntimeException::class, 'already in use');

    expect(User::query()->count())->toBe(3)
        ->and(DB::table('app_user')->count())->toBe(2)
        ->and(DB::table('user_app_user_map')->count())->toBe(2);
});

it('calculates account status and applies active and inactive filters consistently', function (): void {
    createUserManagementTables();
    $admin = actingAsRole(ApplicationRoleName::Administrator, User::factory()->create(['name' => 'Admin']));
    seedManagedIdentity($admin, ApplicationRoleName::Administrator);
    $active = User::factory()->create(['name' => 'Active User']);
    seedManagedIdentity($active, ApplicationRoleName::Engineer);
    $inactiveIdentity = User::factory()->create(['name' => 'Inactive Identity']);
    seedManagedIdentity($inactiveIdentity, ApplicationRoleName::Engineer, false);
    $inactiveMapping = User::factory()->create(['name' => 'Inactive Mapping']);
    seedManagedIdentity($inactiveMapping, ApplicationRoleName::Viewer);
    DB::table('user_app_user_map')->where('laravel_user_id', $inactiveMapping->id)->update([
        'status' => 'inactive',
        'inactivated_at' => now(),
    ]);
    $missingMapping = User::factory()->create(['name' => 'Missing Mapping']);

    actingAsRole(ApplicationRoleName::Administrator, $admin);
    $component = Livewire::test('pages::users');
    $allRows = collect($component->instance()->users()->items())->keyBy('id');

    expect($allRows[$active->id]->account_status)->toBe('Active')
        ->and($allRows[$inactiveIdentity->id]->account_status)->toBe('Inactive')
        ->and($allRows[$inactiveMapping->id]->account_status)->toBe('Inactive')
        ->and($allRows[$missingMapping->id]->account_status)->toBe('Inactive');

    $component->set('statusFilter', 'active');
    $activeIds = collect($component->instance()->users()->items())->pluck('id')->all();

    expect($activeIds)->toContain($admin->id, $active->id)
        ->not->toContain($inactiveIdentity->id, $inactiveMapping->id, $missingMapping->id);

    $component->set('statusFilter', 'inactive');
    $inactiveIds = collect($component->instance()->users()->items())->pluck('id')->all();

    expect($inactiveIds)->toContain($inactiveIdentity->id, $inactiveMapping->id, $missingMapping->id)
        ->not->toContain($active->id);
});

it('does not compare PostgreSQL boolean columns with integer literals in the user listing query', function (): void {
    $source = file_get_contents(resource_path('views/pages/⚡users.blade.php'));

    expect($source)->toContain('identity.is_active is true')
        ->not->toContain('identity.is_active = 1')
        ->not->toContain('identity.is_active = 0');
});

it('uses separate base-table locks for status changes', function (): void {
    $source = file_get_contents(base_path('app/Authorization/ManageApplicationUsers.php'));

    expect($source)->toContain("\$connection->table('user_app_user_map')")
        ->toContain("\$connection->table('app_user')")
        ->toContain('->lockForUpdate()')
        ->not->toContain("->leftJoin('user_roles as assignments'")
        ->not->toContain("->leftJoin('roles'");
});

it('does not bypass the guarded connection for user management operations', function (): void {
    $source = file_get_contents(base_path('app/Authorization/ManageApplicationUsers.php'));

    expect($source)
        ->toContain('$this->connectionResolver->resolve()')
        ->not->toContain('DB::connection()')
        ->not->toContain('DB::table(');
});

it('requires shared SweetAlert confirmation for activation and deactivation', function (): void {
    $viewSource = file_get_contents(resource_path('views/pages/⚡users.blade.php'));
    $scriptSource = file_get_contents(resource_path('js/app.js'));

    expect($viewSource)
        ->toContain('data-user-action="activate"')
        ->toContain('data-user-action="deactivate"')
        ->not->toContain('wire:click="toggleStatus({{ $user->id }})"');

    expect($scriptSource)
        ->toContain('confirmUserStatusChange')
        ->toContain('title: `${activate ? \'Activate\' : \'Deactivate\'} User?`')
        ->toContain('confirmButtonText: actionLabel')
        ->toContain("await component.call('toggleStatus', Number(userId))");
});
