<?php

use App\Authorization\ApplicationRoleName;
use App\Authorization\AssignApplicationRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Existing feature coverage expects an authenticated Administrator unless a
        // test explicitly acts as guest / roleless / Engineer / Viewer.
        actingAsRole(ApplicationRoleName::Administrator);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| The "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Authenticate as a user with exactly one trusted application role.
 */
function actingAsRole(ApplicationRoleName $role, ?User $user = null): User
{
    $user ??= User::factory()->create();

    app(AssignApplicationRole::class)->assign((int) $user->id, $role);

    test()->actingAs($user);

    return $user->fresh() ?? $user;
}

/**
 * Authenticate without any application role (must be denied by application.role).
 */
function actingAsRolelessUser(?User $user = null): User
{
    $user ??= User::factory()->create();

    test()->actingAs($user);

    return $user;
}

require_once __DIR__.'/Support/InstrumentDetailFixtures.php';
require_once __DIR__.'/Support/ApplicationPageTables.php';
