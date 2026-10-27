<?php

/**
 * Explicit-connection tests for staging:bootstrap-administrator.
 *
 * Uses a named guarded SQLite :memory: target distinct from the default
 * RefreshDatabase connection. Live identity is injected so SQLite can exercise
 * the calibration_staging_db allowlist without PostgreSQL.
 * SQLite does not prove PostgreSQL lockForUpdate serialization.
 */

use App\Authorization\ApplicationRoleName;
use App\Authorization\AssignApplicationRole;
use App\Authorization\BootstrapFirstAdministrator;
use App\Authorization\LiveDatabaseIdentity;
use App\Authorization\ResolveApplicationRole;
use App\Authorization\ResolvesLiveDatabaseIdentity;
use App\Authorization\StagingAuthConnection;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestRefusingPostgresConnector;

const BOOTSTRAP_TARGET_CONNECTION = 'bootstrap_target_sqlite';
const BOOTSTRAP_OTHER_CONNECTION = 'bootstrap_other_sqlite';

beforeEach(function (): void {
    Auth::logout();
    UserRole::query()->delete();

    $default = DB::getDefaultConnection();

    configureNamedAuthSqlite(BOOTSTRAP_TARGET_CONNECTION);
    configureNamedAuthSqlite(BOOTSTRAP_OTHER_CONNECTION);

    app()->instance(StagingAuthConnection::class, new StagingAuthConnection(BOOTSTRAP_TARGET_CONNECTION));
    app()->instance(
        ResolvesLiveDatabaseIdentity::class,
        new class implements ResolvesLiveDatabaseIdentity
        {
            public function identity(Connection $connection): string
            {
                return BootstrapFirstAdministrator::REQUIRED_DATABASE;
            }
        },
    );

    expect(DB::getDefaultConnection())->toBe($default);
});

function configureNamedAuthSqlite(string $name): Connection
{
    config([
        'database.connections.'.$name => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge($name);

    $connection = DB::connection($name);

    $connection->getSchemaBuilder()->create('users', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });

    $connection->getSchemaBuilder()->create('roles', function ($table): void {
        $table->id();
        $table->string('name', 50)->unique();
        $table->timestamps();
    });

    $connection->getSchemaBuilder()->create('user_roles', function ($table): void {
        $table->id();
        $table->foreignId('laravel_user_id')->constrained('users')->restrictOnDelete();
        $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
        $table->timestamps();
        $table->unique('laravel_user_id');
        $table->unique(['laravel_user_id', 'role_id']);
    });

    $now = now();

    foreach (ApplicationRoleName::cases() as $role) {
        $connection->table('roles')->insert([
            'name' => $role->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return $connection;
}

function bootstrapTarget(): Connection
{
    return DB::connection(BOOTSTRAP_TARGET_CONNECTION);
}

function bootstrapOther(): Connection
{
    return DB::connection(BOOTSTRAP_OTHER_CONNECTION);
}

function bootstrapAdminOptions(array $overrides = []): array
{
    return array_merge([
        '--email' => 'admin.candidate@example.test',
        '--confirm-database' => BootstrapFirstAdministrator::REQUIRED_DATABASE,
    ], $overrides);
}

/**
 * @return array{id: int, email: string, password: string}
 */
function seedBootstrapCandidate(
    string $email = 'admin.candidate@example.test',
    ?Connection $connection = null,
    ?int $id = null,
): array {
    $connection ??= bootstrapTarget();
    $password = Hash::make('not-used-by-bootstrap');
    $now = now();
    // Keep target IDs far from default RefreshDatabase users to avoid id collisions
    // when asserting default-connection isolation.
    $id ??= (int) $connection->table('users')->max('id') + 50_000;
    if ($id < 50_000) {
        $id = 50_000;
    }

    $connection->table('users')->insert([
        'id' => $id,
        'name' => 'Admin Candidate',
        'email' => $email,
        'password' => $password,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'id' => $id,
        'email' => $email,
        'password' => $password,
    ];
}

function assignRoleOnConnection(Connection $connection, int $userId, ApplicationRoleName $role): void
{
    app(AssignApplicationRole::class)->assign($userId, $role, $connection);
}

it('bootstraps using the explicit target connection without changing the default', function () {
    $defaultBefore = DB::getDefaultConnection();
    $defaultRolesBefore = (int) DB::table('user_roles')->count();
    $otherRolesBefore = (int) bootstrapOther()->table('user_roles')->count();
    $user = seedBootstrapCandidate();

    $this->artisan('staging:bootstrap-administrator', bootstrapAdminOptions())
        ->expectsOutputToContain('connection: '.BOOTSTRAP_TARGET_CONNECTION)
        ->expectsOutputToContain('assigned: yes')
        ->assertSuccessful();

    expect(DB::getDefaultConnection())->toBe($defaultBefore)
        ->and((int) DB::table('user_roles')->count())->toBe($defaultRolesBefore)
        ->and((int) bootstrapOther()->table('user_roles')->count())->toBe($otherRolesBefore)
        ->and((int) bootstrapTarget()->table('user_roles')->count())->toBe(1)
        ->and(app(ResolveApplicationRole::class)->tryResolve($user['id'], bootstrapTarget()))
        ->toBe(ApplicationRoleName::Administrator)
        ->and(app(ResolveApplicationRole::class)->tryResolve($user['id']))->toBeNull()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('keeps the default connection unchanged when bootstrap throws', function () {
    $defaultBefore = DB::getDefaultConnection();

    $this->artisan('staging:bootstrap-administrator', bootstrapAdminOptions([
        '--email' => 'missing@example.test',
    ]))->assertFailed();

    expect(DB::getDefaultConnection())->toBe($defaultBefore)
        ->and((int) bootstrapTarget()->table('user_roles')->count())->toBe(0);
});

it('writes role assignment only through the explicit target connection', function () {
    $user = seedBootstrapCandidate();
    seedBootstrapCandidate('other.side@example.test', bootstrapOther());

    app(BootstrapFirstAdministrator::class)->run(
        $user['email'],
        BootstrapFirstAdministrator::REQUIRED_DATABASE,
        false,
    );

    expect((int) bootstrapTarget()->table('user_roles')->count())->toBe(1)
        ->and((int) bootstrapOther()->table('user_roles')->count())->toBe(0)
        ->and((int) DB::connection()->table('user_roles')->count())->toBe(0);
});

it('resolves and assigns roles on the explicit target connection', function () {
    $user = seedBootstrapCandidate();

    app(AssignApplicationRole::class)->assign(
        $user['id'],
        ApplicationRoleName::Administrator,
        bootstrapTarget(),
    );

    expect(app(ResolveApplicationRole::class)->tryResolve($user['id'], bootstrapTarget()))
        ->toBe(ApplicationRoleName::Administrator)
        ->and(app(ResolveApplicationRole::class)->tryResolve($user['id']))->toBeNull();
});

it('rolls back when assignment throws before completing', function () {
    $user = seedBootstrapCandidate();
    $defaultBefore = DB::getDefaultConnection();

    app()->instance(AssignApplicationRole::class, new class extends AssignApplicationRole
    {
        public function assign(int $laravelUserId, ApplicationRoleName $role, ?Connection $connection = null): void
        {
            throw new RuntimeException('forced assignment failure');
        }
    });

    expect(fn () => app(BootstrapFirstAdministrator::class)->run(
        $user['email'],
        BootstrapFirstAdministrator::REQUIRED_DATABASE,
        false,
    ))->toThrow(RuntimeException::class, 'forced assignment failure');

    expect((int) bootstrapTarget()->table('user_roles')->count())->toBe(0)
        ->and(DB::getDefaultConnection())->toBe($defaultBefore);
});

it('rolls back when verification fails after a role insert', function () {
    $user = seedBootstrapCandidate();
    $defaultBefore = DB::getDefaultConnection();

    app()->instance(AssignApplicationRole::class, new class extends AssignApplicationRole
    {
        public function assign(int $laravelUserId, ApplicationRoleName $role, ?Connection $connection = null): void
        {
            if ($connection === null) {
                parent::assign($laravelUserId, $role, null);

                return;
            }

            parent::assign($laravelUserId, $role, $connection);

            throw new RuntimeException('forced post-insert failure');
        }
    });

    expect(fn () => app(BootstrapFirstAdministrator::class)->run(
        $user['email'],
        BootstrapFirstAdministrator::REQUIRED_DATABASE,
        false,
    ))->toThrow(RuntimeException::class, 'forced post-insert failure');

    expect((int) bootstrapTarget()->table('user_roles')->count())->toBe(0)
        ->and(DB::getDefaultConnection())->toBe($defaultBefore);
});

it('denies a missing user without creating one', function () {
    $usersBefore = (int) bootstrapTarget()->table('users')->count();

    $this->artisan('staging:bootstrap-administrator', bootstrapAdminOptions([
        '--email' => 'missing@example.test',
    ]))
        ->expectsOutputToContain('No Laravel user exists with that exact email.')
        ->assertFailed();

    expect((int) bootstrapTarget()->table('users')->count())->toBe($usersBefore)
        ->and((int) bootstrapTarget()->table('user_roles')->count())->toBe(0);
});

it('denies bootstrap when an Administrator membership already exists', function () {
    $existing = seedBootstrapCandidate('already.admin@example.test');
    assignRoleOnConnection(bootstrapTarget(), $existing['id'], ApplicationRoleName::Administrator);
    $candidate = seedBootstrapCandidate();

    $this->artisan('staging:bootstrap-administrator', bootstrapAdminOptions())
        ->expectsOutputToContain('An Administrator membership already exists')
        ->assertFailed();

    expect(bootstrapTarget()->table('user_roles')->where('laravel_user_id', $candidate['id'])->exists())->toBeFalse()
        ->and(app(ResolveApplicationRole::class)->tryResolve($existing['id'], bootstrapTarget()))
        ->toBe(ApplicationRoleName::Administrator);
});

it('denies a target user that already has an Engineer role', function () {
    $user = seedBootstrapCandidate();
    assignRoleOnConnection(bootstrapTarget(), $user['id'], ApplicationRoleName::Engineer);

    $this->artisan('staging:bootstrap-administrator', bootstrapAdminOptions())
        ->expectsOutputToContain('Target user already has an application role.')
        ->assertFailed();

    expect(app(ResolveApplicationRole::class)->tryResolve($user['id'], bootstrapTarget()))
        ->toBe(ApplicationRoleName::Engineer);
});

it('denies an invalid live database identity', function () {
    seedBootstrapCandidate();
    app()->instance(ResolvesLiveDatabaseIdentity::class, new class implements ResolvesLiveDatabaseIdentity
    {
        public function identity(Connection $connection): string
        {
            return 'unexpected_db';
        }
    });

    $this->artisan('staging:bootstrap-administrator', bootstrapAdminOptions())
        ->expectsOutputToContain('Live database identity [unexpected_db] is not calibration_staging_db.')
        ->assertFailed();

    expect((int) bootstrapTarget()->table('user_roles')->count())->toBe(0);
});

it('denies a production database identity', function () {
    seedBootstrapCandidate();
    app()->instance(ResolvesLiveDatabaseIdentity::class, new class implements ResolvesLiveDatabaseIdentity
    {
        public function identity(Connection $connection): string
        {
            return BootstrapFirstAdministrator::FORBIDDEN_PRODUCTION_DATABASE;
        }
    });

    $this->artisan('staging:bootstrap-administrator', bootstrapAdminOptions())
        ->expectsOutputToContain('Refusing bootstrap against production database calibration_db.')
        ->assertFailed();

    expect((int) bootstrapTarget()->table('user_roles')->count())->toBe(0);
});

it('denies a confirmation mismatch before assignment', function () {
    seedBootstrapCandidate();

    $this->artisan('staging:bootstrap-administrator', bootstrapAdminOptions([
        '--confirm-database' => 'calibration_db',
    ]))
        ->expectsOutputToContain('Confirmation mismatch')
        ->assertFailed();

    expect((int) bootstrapTarget()->table('user_roles')->count())->toBe(0);
});

it('dry-run validates without writing user_roles or changing the default connection', function () {
    $user = seedBootstrapCandidate();
    $defaultBefore = DB::getDefaultConnection();

    $this->artisan('staging:bootstrap-administrator', bootstrapAdminOptions([
        '--dry-run' => true,
    ]))
        ->expectsOutputToContain('Dry-run OK')
        ->expectsOutputToContain('assigned: no')
        ->assertSuccessful();

    expect(DB::getDefaultConnection())->toBe($defaultBefore)
        ->and(bootstrapTarget()->table('user_roles')->where('laravel_user_id', $user['id'])->exists())->toBeFalse();
});

it('assigns the first Administrator exactly once and denies a second bootstrap', function () {
    $first = seedBootstrapCandidate('first.admin@example.test');
    seedBootstrapCandidate('second.admin@example.test');

    $this->artisan('staging:bootstrap-administrator', bootstrapAdminOptions([
        '--email' => 'first.admin@example.test',
    ]))->assertSuccessful();

    $this->artisan('staging:bootstrap-administrator', bootstrapAdminOptions([
        '--email' => 'second.admin@example.test',
    ]))
        ->expectsOutputToContain('An Administrator membership already exists')
        ->assertFailed();

    expect(app(ResolveApplicationRole::class)->tryResolve($first['id'], bootstrapTarget()))
        ->toBe(ApplicationRoleName::Administrator)
        ->and((int) bootstrapTarget()->table('user_roles')->count())->toBe(1);
});

it('does not modify the target user password hash', function () {
    $user = seedBootstrapCandidate();

    $this->artisan('staging:bootstrap-administrator', bootstrapAdminOptions())
        ->assertSuccessful();

    expect((string) bootstrapTarget()->table('users')->where('id', $user['id'])->value('password'))
        ->toBe($user['password']);
});

it('does not create domain actor mappings', function () {
    seedBootstrapCandidate();

    $this->artisan('staging:bootstrap-administrator', bootstrapAdminOptions())
        ->expectsOutputToContain('Domain actor mapping could not be verified')
        ->assertSuccessful();

    expect(bootstrapTarget()->getSchemaBuilder()->hasTable('user_app_user_map'))->toBeFalse()
        ->and(bootstrapTarget()->getSchemaBuilder()->hasTable('app_user'))->toBeFalse()
        ->and(Schema::hasTable('user_app_user_map'))->toBeFalse();
});

it('refuses sqlite as a proven live identity when using the production identity reader', function () {
    seedBootstrapCandidate();
    app()->instance(ResolvesLiveDatabaseIdentity::class, new LiveDatabaseIdentity);

    $this->artisan('staging:bootstrap-administrator', bootstrapAdminOptions())
        ->expectsOutputToContain('Live database identity could not be proven')
        ->assertFailed();

    expect((int) bootstrapTarget()->table('user_roles')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('does not fall back to the default connection when an explicit connection is supplied', function () {
    $targetUser = seedBootstrapCandidate('explicit@example.test');
    $defaultUser = User::factory()->create(['email' => 'default.only@example.test']);

    app(AssignApplicationRole::class)->assign(
        $targetUser['id'],
        ApplicationRoleName::Engineer,
        bootstrapTarget(),
    );

    expect(app(ResolveApplicationRole::class)->tryResolve($targetUser['id'], bootstrapTarget()))
        ->toBe(ApplicationRoleName::Engineer)
        ->and(app(ResolveApplicationRole::class)->tryResolve((int) $defaultUser->id))->toBeNull()
        ->and(DB::table('user_roles')->where('laravel_user_id', $targetUser['id'])->exists())->toBeFalse()
        ->and(bootstrapTarget()->table('user_roles')->where('laravel_user_id', (int) $defaultUser->id)->exists())->toBeFalse();
});

it('never opens postgresql while bootstrapping on sqlite', function () {
    seedBootstrapCandidate();

    $this->artisan('staging:bootstrap-administrator', bootstrapAdminOptions())
        ->assertSuccessful();

    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0)
        ->and(DB::getDriverName())->toBe('sqlite')
        ->and(bootstrapTarget()->getDriverName())->toBe('sqlite');
});
