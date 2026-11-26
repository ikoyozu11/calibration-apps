<?php

use App\Authorization\ApplicationRoleName;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createProfileTables(): void
{
    Schema::create('app_user', function (Blueprint $table): void {
        $table->unsignedBigInteger('app_user_id')->primary();
        $table->string('username', 100)->unique();
        $table->string('full_name');
        $table->boolean('is_active');
    });

    Schema::create('user_app_user_map', function (Blueprint $table): void {
        $table->unsignedBigInteger('user_app_user_map_id')->primary();
        $table->unsignedBigInteger('laravel_user_id');
        $table->unsignedBigInteger('app_user_id');
        $table->string('status', 20);
        $table->timestamp('created_at')->nullable();
        $table->timestamp('inactivated_at')->nullable();
    });
}

function seedProfileIdentity(User $user, ApplicationRoleName $role, bool $active = true): void
{
    $appUserId = $user->id + 1000;

    DB::table('app_user')->insert([
        'app_user_id' => $appUserId,
        'username' => strtolower($role->value).'vt',
        'full_name' => $user->name,
        'is_active' => $active,
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

it('renders the authenticated identity and canonical role in the sidebar and profile', function (): void {
    createProfileTables();

    foreach (ApplicationRoleName::cases() as $role) {
        $user = actingAsRole($role, User::factory()->create([
            'name' => 'Staging '.$role->value,
            'email' => strtolower($role->value).'@example.com',
        ]));
        seedProfileIdentity($user, $role);

        $response = $this->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Back to Home')
            ->assertSee('My Profile')
            ->assertSee('Staging '.$role->value)
            ->assertSee($role->value)
            ->assertSee(strtolower($role->value).'vt')
            ->assertSee($role->value === 'Administrator' ? 'SA' : ($role->value === 'Engineer' ? 'SE' : 'SV'));

        $navigation = ['href="'.route('home').'"', 'href="'.route('instruments.index').'"', 'href="'.route('calibrations.index').'"'];

        if ($role !== ApplicationRoleName::Viewer) {
            $response->assertSee('Management');
        }

        $response->assertSeeInOrder($navigation, false);
    }
});

it('requires authentication for the profile page', function (): void {
    auth()->logout();

    $this->get(route('profile.show'))->assertRedirect(route('login'));
});

it('does not expose another user profile and handles inactive mapping safely', function (): void {
    createProfileTables();
    $viewer = actingAsRole(ApplicationRoleName::Viewer, User::factory()->create([
        'name' => 'Inactive Viewer',
        'email' => 'inactive-viewer@example.com',
    ]));
    seedProfileIdentity($viewer, ApplicationRoleName::Viewer, false);
    User::factory()->create([
        'name' => 'Other User',
        'email' => 'other@example.com',
    ]);

    $this->get(route('profile.show'))->assertForbidden();
});

it('renders the responsive drawer controls and separate mobile profile actions', function (): void {
    createProfileTables();
    $user = actingAsRole(ApplicationRoleName::Engineer, User::factory()->create([
        'name' => 'Drawer Engineer',
        'email' => 'drawer-engineer@example.com',
    ]));
    seedProfileIdentity($user, ApplicationRoleName::Engineer);

    $this->get(route('profile.show'))
        ->assertSee('data-mobile-nav', false)
        ->assertSee('id="mobile-primary-navigation" class="mobile-nav-panel"', false)
        ->assertSee('class="mobile-nav-panel-inner"', false)
        ->assertDontSee('data-mobile-nav-backdrop', false)
        ->assertDontSee('data-mobile-nav-close', false)
        ->assertDontSee('role="dialog"', false)
        ->assertSee('href="'.route('home').'"', false)
        ->assertSee('bg-brand-soft', false)
        ->assertSee('bg-white/10', false)
        ->assertSee('My Profile')
        ->assertSee('Log out');
});
