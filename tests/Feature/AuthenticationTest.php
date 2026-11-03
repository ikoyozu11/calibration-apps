<?php

use App\Authorization\ApplicationRoleName;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Auth::logout();
});

it('redirects guests from the dashboard to login', function () {
    $this->get(route('home'))
        ->assertRedirect(route('login'));
});

it('denies guests master-data pages', function () {
    $this->get(route('standards.index'))->assertRedirect(route('login'));
    $this->get(route('activities.index'))->assertRedirect(route('login'));
});

it('denies guests certificate pdf downloads', function () {
    $this->get(route('certificates.pdf', 1))
        ->assertRedirect(route('login'));
});

it('denies guests csv exports', function () {
    $this->get(route('instruments.export.csv'))->assertRedirect(route('login'));
    $this->get(route('calibrations.export.csv'))->assertRedirect(route('login'));
});

it('denies guest livewire access to write-capable pages', function () {
    $this->get(route('standards.index'))->assertRedirect(route('login'));
    $this->get(route('activities.index'))->assertRedirect(route('login'));
    $this->get(route('instruments.types.test-components', [
        'instrument' => 1,
        'instrumentType' => 1,
    ]))->assertRedirect(route('login'));
});

it('shows a password visibility toggle and omits remember me', function () {
    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSee('Email or username')
        ->assertSee('id="password-visibility"', false)
        ->assertDontSee('Remember me')
        ->assertDontSee('name="remember"', false);
});

it('authenticates with valid credentials', function () {
    $user = User::factory()->create([
        'email' => 'engineer@example.com',
        'password' => 'secret-password',
    ]);

    $response = $this->post(route('login.store'), [
        'login' => 'engineer@example.com',
        'password' => 'secret-password',
    ]);

    $response->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
});

it('authenticates with an active mapped username', function () {
    $user = seedLoginMappedUser('admin.desk', 'engineer@example.com', 'secret-password');

    $response = $this->post(route('login.store'), [
        'login' => 'Admin.Desk',
        'password' => 'secret-password',
    ]);

    $response->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
});

it('rejects an unmapped or inactive username with a generic error', function () {
    seedLoginMappedUser('admin.desk', 'engineer@example.com', 'secret-password', active: false);

    $response = $this->from(route('login'))->post(route('login.store'), [
        'login' => 'admin.desk',
        'password' => 'secret-password',
    ]);

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors('login');
    $this->assertGuest();
});

it('rejects invalid credentials with a generic error', function () {
    User::factory()->create([
        'email' => 'engineer@example.com',
        'password' => 'secret-password',
    ]);

    $response = $this->from(route('login'))->post(route('login.store'), [
        'login' => 'engineer@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors('login');
    $this->assertGuest();

    expect(session('errors')->first('login'))
        ->toBe(__('These credentials do not match our records.'));
});

it('rate limits repeated failed logins', function () {
    $user = User::factory()->create([
        'email' => 'engineer@example.com',
        'password' => 'secret-password',
    ]);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->from(route('login'))->post(route('login.store'), [
            'login' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('login');
    }

    $response = $this->from(route('login'))->post(route('login.store'), [
        'login' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors('login');
    expect(session('errors')->first('login'))->toContain('Too many login attempts');
    $this->assertGuest();
});

it('redirects to the intended url after login', function () {
    $user = User::factory()->create([
        'password' => 'secret-password',
    ]);

    $this->get(route('instruments.index'))->assertRedirect(route('login'));

    $response = $this->post(route('login.store'), [
        'login' => $user->email,
        'password' => 'secret-password',
    ]);

    $response->assertRedirect(route('instruments.index'));
    $this->assertAuthenticatedAs($user);
});

it('regenerates the session on login', function () {
    $user = User::factory()->create([
        'password' => 'secret-password',
    ]);

    $this->get(route('login'));
    $sessionBefore = session()->getId();

    $this->post(route('login.store'), [
        'login' => $user->email,
        'password' => 'secret-password',
    ])->assertRedirect(route('home'));

    expect(session()->getId())->not->toBe($sessionBefore);
});

it('logs out and invalidates the session via post only', function () {
    $user = actingAsRole(ApplicationRoleName::Administrator);
    $this->assertAuthenticatedAs($user);

    $response = $this->post(route('logout'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();

    $this->get(route('instruments.index'))->assertRedirect(route('login'));
    $this->get(route('home'))->assertRedirect(route('login'));
});

it('does not expose logout over get', function () {
    $user = actingAsRole(ApplicationRoleName::Administrator);

    $this->get('/logout')->assertMethodNotAllowed();
    $this->assertAuthenticatedAs($user);
});

it('allows authenticated access to application pages', function () {
    createAuthenticationPageTables();

    $user = actingAsRole(ApplicationRoleName::Administrator);

    $this->get(route('login'))->assertRedirect(route('home'));
    $this->get(route('home'))->assertOk()->assertSee('Dashboard');
    $this->get(route('instruments.index'))->assertOk();
});
it('preserves existing application route names', function () {
    expect(route('home', absolute: false))->toBe('/');
    expect(route('instruments.index', absolute: false))->toBe('/instruments');
    expect(route('instruments.show', 1, absolute: false))->toBe('/instruments/1');
    expect(route('instruments.types.show', ['instrument' => 1, 'instrumentType' => 2], absolute: false))
        ->toBe('/instruments/1/types/2');
    expect(route('instruments.types.standards', ['instrument' => 1, 'instrumentType' => 2], absolute: false))
        ->toBe('/instruments/1/types/2/standards');
    expect(route('instruments.types.test-components', ['instrument' => 1, 'instrumentType' => 2], absolute: false))
        ->toBe('/instruments/1/types/2/test-components');
    expect(route('instruments.export.csv', absolute: false))->toBe('/instruments/export.csv');
    expect(route('standards.index', absolute: false))->toBe('/standards');
    expect(route('activities.index', absolute: false))->toBe('/activities');
    expect(route('calibrations.index', absolute: false))->toBe('/calibrations');
    expect(route('calibrations.show', 1, absolute: false))->toBe('/calibrations/1');
    expect(route('calibrations.export.csv', absolute: false))->toBe('/calibrations/export.csv');
    expect(route('certificates.show', 1, absolute: false))->toBe('/certificates/1');
    expect(route('certificates.pdf', 1, absolute: false))->toBe('/certificates/1/pdf');
    expect(route('login', absolute: false))->toBe('/login');
    expect(route('login.store', absolute: false))->toBe('/login');
    expect(route('logout', absolute: false))->toBe('/logout');
});

function seedLoginMappedUser(
    string $username,
    string $email,
    string $password,
    bool $active = true,
): User {
    $user = User::factory()->create([
        'email' => $email,
        'password' => $password,
    ]);

    if (! Schema::hasTable('app_user')) {
        Schema::create('app_user', function ($table): void {
            $table->id('app_user_id');
            $table->string('username', 100)->unique();
            $table->boolean('is_active');
        });
    }

    if (! Schema::hasTable('user_app_user_map')) {
        Schema::create('user_app_user_map', function ($table): void {
            $table->id('user_app_user_map_id');
            $table->unsignedBigInteger('laravel_user_id');
            $table->unsignedBigInteger('app_user_id');
            $table->string('status', 20);
            $table->timestamp('created_at');
            $table->timestamp('inactivated_at')->nullable();
        });
    }

    $appUserId = (int) DB::table('app_user')->insertGetId([
        'username' => $username,
        'is_active' => $active ? 1 : 0,
    ], 'app_user_id');

    DB::table('user_app_user_map')->insert([
        'laravel_user_id' => $user->id,
        'app_user_id' => $appUserId,
        'status' => $active ? 'active' : 'inactive',
        'created_at' => now(),
        'inactivated_at' => $active ? null : now(),
    ]);

    return $user;
}
