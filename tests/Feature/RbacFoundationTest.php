<?php

use App\Authorization\ApplicationCapability;
use App\Authorization\ApplicationRoleName;
use App\Authorization\AssignApplicationRole;
use App\Authorization\ResolveApplicationRole;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestRefusingPostgresConnector;

beforeEach(function (): void {
    Auth::logout();
});

it('denies guests application pages', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
    $this->get(route('standards.index'))->assertRedirect(route('login'));
    $this->get(route('certificates.pdf', 1))->assertRedirect(route('login'));
    $this->get(route('instruments.export.csv'))->assertRedirect(route('login'));
});

it('denies roleless authenticated users', function () {
    actingAsRolelessUser();

    $this->get(route('home'))->assertForbidden();
    $this->get(route('standards.index'))->assertForbidden();
    $this->get(route('instruments.export.csv'))->assertForbidden();
    $this->get(route('calibrations.export.csv'))->assertForbidden();
    $this->get(route('certificates.pdf', 1))->assertForbidden();
});

it('denies invalid role names', function () {
    $user = User::factory()->create();
    $role = Role::query()->create(['name' => 'Superuser']);
    UserRole::query()->create([
        'laravel_user_id' => $user->id,
        'role_id' => $role->id,
    ]);

    $this->actingAs($user);

    expect(app(ResolveApplicationRole::class)->tryResolve((int) $user->id))->toBeNull();
    $this->get(route('home'))->assertForbidden();
    expect(Gate::forUser($user)->allows(ApplicationCapability::HistoryView->value))->toBeFalse();
});

it('denies ambiguous role memberships when duplicates exist', function () {
    $user = User::factory()->create();
    $adminId = (int) Role::query()->where('name', ApplicationRoleName::Administrator->value)->value('id');
    $engineerId = (int) Role::query()->where('name', ApplicationRoleName::Engineer->value)->value('id');

    Schema::table('user_roles', function (Blueprint $table): void {
        $table->dropUnique(['laravel_user_id']);
    });

    UserRole::query()->insert([
        [
            'laravel_user_id' => $user->id,
            'role_id' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'laravel_user_id' => $user->id,
            'role_id' => $engineerId,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    expect(app(ResolveApplicationRole::class)->tryResolve((int) $user->id))->toBeNull();
    $this->actingAs($user)->get(route('home'))->assertForbidden();
});

it('allows administrator master-data reads and writes', function () {
    createAuthenticationPageTables();
    actingAsRole(ApplicationRoleName::Administrator);

    $this->get(route('home'))->assertOk();
    $this->get(route('standards.index'))->assertOk();

    expect(Gate::allows(ApplicationCapability::MasterdataManage->value))->toBeTrue();

    Livewire::test('pages::standards')
        ->call('startAdding')
        ->assertSet('adding', true);
});

it('allows engineer reads but denies master-data writes', function () {
    createAuthenticationPageTables();
    actingAsRole(ApplicationRoleName::Engineer);

    $this->get(route('home'))->assertOk();
    $this->get(route('standards.index'))->assertOk();
    $this->get(route('activities.index'))->assertOk();

    expect(Gate::allows(ApplicationCapability::MasterdataManage->value))->toBeFalse();
    expect(Gate::allows(ApplicationCapability::HistoryView->value))->toBeTrue();
    expect(Gate::allows(ApplicationCapability::CertificatesView->value))->toBeTrue();
    expect(Gate::allows(ApplicationCapability::DocumentsDownload->value))->toBeTrue();

    Livewire::test('pages::standards')
        ->call('startAdding')
        ->assertForbidden();

    Livewire::test('pages::activities')
        ->call('startAdding')
        ->assertForbidden();
});

it('allows viewer reads but denies master-data and private document capabilities', function () {
    createAuthenticationPageTables();
    actingAsRole(ApplicationRoleName::Viewer);

    $this->get(route('home'))->assertOk();
    $this->get(route('standards.index'))->assertOk();

    expect(Gate::allows(ApplicationCapability::MasterdataManage->value))->toBeFalse();
    expect(Gate::allows(ApplicationCapability::DocumentsDownload->value))->toBeFalse();
    expect(Gate::allows(ApplicationCapability::DocumentsUpload->value))->toBeFalse();
    expect(Gate::allows(ApplicationCapability::CertificatesView->value))->toBeTrue();
    expect(Gate::allows(ApplicationCapability::HistoryView->value))->toBeTrue();

    Livewire::test('pages::standards')
        ->call('saveStandard')
        ->assertForbidden();

    Livewire::test('pages::activities')
        ->call('saveActivity')
        ->assertForbidden();
});

it('denies role escalation through request parameters', function () {
    actingAsRole(ApplicationRoleName::Viewer);

    $this->post(route('login.store'), [
        'email' => 'ignored@example.com',
        'password' => 'ignored',
        'role' => 'Administrator',
    ]);

    expect(Gate::allows(ApplicationCapability::MasterdataManage->value))->toBeFalse();
    expect(app(ResolveApplicationRole::class)->resolve((int) auth()->id()))
        ->toBe(ApplicationRoleName::Viewer);
});

it('authorizes certificate pdf downloads by role', function () {
    actingAsRole(ApplicationRoleName::Viewer);
    expect(Gate::allows(ApplicationCapability::CertificatesView->value))->toBeTrue();

    actingAsRole(ApplicationRoleName::Engineer);
    expect(Gate::allows(ApplicationCapability::CertificatesView->value))->toBeTrue();

    actingAsRolelessUser();
    expect(Gate::allows(ApplicationCapability::CertificatesView->value))->toBeFalse();
});

it('authorizes csv exports for valid roles only', function () {
    actingAsRole(ApplicationRoleName::Viewer);
    expect(Gate::allows(ApplicationCapability::HistoryView->value))->toBeTrue();

    actingAsRolelessUser();
    $this->get(route('calibrations.export.csv'))->assertForbidden();
});

it('preserves existing application route names', function () {
    expect(route('home', absolute: false))->toBe('/');
    expect(route('standards.index', absolute: false))->toBe('/standards');
    expect(route('activities.index', absolute: false))->toBe('/activities');
    expect(route('certificates.pdf', 1, absolute: false))->toBe('/certificates/1/pdf');
    expect(route('instruments.export.csv', absolute: false))->toBe('/instruments/export.csv');
    expect(route('login', absolute: false))->toBe('/login');
    expect(route('logout', absolute: false))->toBe('/logout');
});

it('keeps session.cancel fail-closed until ownership exists', function () {
    foreach (ApplicationRoleName::cases() as $role) {
        actingAsRole($role);
        expect(Gate::allows(ApplicationCapability::SessionCancel->value))->toBeFalse();
    }
});

it('keeps login and logout working with roles', function () {
    $user = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'secret-password',
    ]);
    app(AssignApplicationRole::class)->assign((int) $user->id, ApplicationRoleName::Administrator);

    $this->post(route('login.store'), [
        'email' => 'admin@example.com',
        'password' => 'secret-password',
    ])->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('never opens postgresql during rbac checks', function () {
    actingAsRole(ApplicationRoleName::Administrator);

    expect(app(ResolveApplicationRole::class)->resolve((int) auth()->id()))
        ->toBe(ApplicationRoleName::Administrator);

    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});
