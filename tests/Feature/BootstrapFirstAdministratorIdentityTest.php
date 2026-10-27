<?php

/**
 * Explicit-connection offline tests for first-Administrator identity bootstrap.
 *
 * Uses a named guarded SQLite :memory: target. Live identity is injected so
 * SQLite can exercise the calibration_staging_db allowlist without PostgreSQL.
 */

use App\Authorization\ApplicationRoleName;
use App\Authorization\AssignApplicationRole;
use App\Authorization\BootstrapFirstAdministratorIdentity;
use App\Authorization\ResolveApplicationRole;
use App\Authorization\ResolvesLiveDatabaseIdentity;
use App\Authorization\StagingAuthConnection;
use App\CalibrationSessions\ResolveDomainActor;
use App\Console\Commands\BootstrapFirstAdministratorIdentityCommand;
use App\Models\UserRole;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestRefusingPostgresConnector;

const IDENTITY_TARGET_CONNECTION = 'identity_target_sqlite';
const IDENTITY_OTHER_CONNECTION = 'identity_other_sqlite';
const IDENTITY_PASSWORD = 'Staging-Admin-Pass1!';

beforeEach(function (): void {
    Auth::logout();
    UserRole::query()->delete();

    $default = DB::getDefaultConnection();

    configureIdentitySqlite(IDENTITY_TARGET_CONNECTION);
    configureIdentitySqlite(IDENTITY_OTHER_CONNECTION);

    app()->instance(StagingAuthConnection::class, new StagingAuthConnection(IDENTITY_TARGET_CONNECTION));
    app()->instance(
        ResolvesLiveDatabaseIdentity::class,
        new class implements ResolvesLiveDatabaseIdentity
        {
            public function identity(Connection $connection): string
            {
                return BootstrapFirstAdministratorIdentity::REQUIRED_DATABASE;
            }
        },
    );

    expect(DB::getDefaultConnection())->toBe($default);
});

function configureIdentitySqlite(string $name): Connection
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
    $schema = $connection->getSchemaBuilder();

    $schema->create('users', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });

    $schema->create('roles', function ($table): void {
        $table->id();
        $table->string('name', 50)->unique();
        $table->timestamps();
    });

    $schema->create('user_roles', function ($table): void {
        $table->id();
        $table->foreignId('laravel_user_id')->constrained('users')->restrictOnDelete();
        $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
        $table->timestamps();
        $table->unique('laravel_user_id');
        $table->unique(['laravel_user_id', 'role_id']);
    });

    $schema->create('app_user', function ($table): void {
        $table->id('app_user_id');
        $table->string('username', 100)->unique();
        $table->string('full_name', 255);
        $table->string('email', 255)->nullable()->unique();
        $table->string('role_name', 50);
        $table->boolean('is_active')->default(true);
        $table->timestamp('created_at');
    });

    $schema->create('user_app_user_map', function ($table): void {
        $table->id('user_app_user_map_id');
        $table->unsignedBigInteger('laravel_user_id');
        $table->unsignedBigInteger('app_user_id');
        $table->string('status', 20);
        $table->timestamp('created_at');
        $table->timestamp('inactivated_at')->nullable();
        $table->unique('laravel_user_id');
        $table->unique('app_user_id');
        $table->foreign('laravel_user_id')->references('id')->on('users');
        $table->foreign('app_user_id')->references('app_user_id')->on('app_user');
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

function identityTarget(): Connection
{
    return DB::connection(IDENTITY_TARGET_CONNECTION);
}

function identityOther(): Connection
{
    return DB::connection(IDENTITY_OTHER_CONNECTION);
}

/**
 * @return array<string, mixed>
 */
function identityOptions(array $overrides = []): array
{
    return array_merge([
        '--name' => 'Staging Administrator',
        '--username' => 'staging.admin',
        '--email' => 'staging.admin@example.test',
        '--confirm-database' => BootstrapFirstAdministratorIdentity::REQUIRED_DATABASE,
        '--confirm-checkpoint' => BootstrapFirstAdministratorIdentityCommand::REQUIRED_CHECKPOINT,
        '--yes' => true,
    ], $overrides);
}

/**
 * @return array{
 *     database: string,
 *     connection: string,
 *     laravel_user_id: int,
 *     app_user_id: int,
 *     email: string,
 *     username: string,
 *     administrator_assigned: bool,
 *     actor_resolved: bool,
 *     message: string
 * }
 */
function runIdentity(
    string $displayName = 'Staging Administrator',
    string $username = 'staging.admin',
    string $email = 'staging.admin@example.test',
    string $password = IDENTITY_PASSWORD,
    string $confirmDatabase = BootstrapFirstAdministratorIdentity::REQUIRED_DATABASE,
): array {
    return app(BootstrapFirstAdministratorIdentity::class)->run(
        $displayName,
        $username,
        $email,
        $password,
        $confirmDatabase,
    );
}

it('creates the full identity on the explicit staging target without changing the default', function () {
    $defaultBefore = DB::getDefaultConnection();
    $defaultUsersBefore = (int) DB::table('users')->count();
    $otherUsersBefore = (int) identityOther()->table('users')->count();

    $report = runIdentity();

    expect($report['database'])->toBe(BootstrapFirstAdministratorIdentity::REQUIRED_DATABASE)
        ->and($report['connection'])->toBe(IDENTITY_TARGET_CONNECTION)
        ->and($report['administrator_assigned'])->toBeTrue()
        ->and($report['actor_resolved'])->toBeTrue()
        ->and(DB::getDefaultConnection())->toBe($defaultBefore)
        ->and((int) DB::table('users')->count())->toBe($defaultUsersBefore)
        ->and((int) identityOther()->table('users')->count())->toBe($otherUsersBefore)
        ->and((int) identityTarget()->table('users')->count())->toBe(1)
        ->and((int) identityTarget()->table('app_user')->count())->toBe(1)
        ->and((int) identityTarget()->table('user_app_user_map')->count())->toBe(1)
        ->and((int) identityTarget()->table('user_roles')->count())->toBe(1)
        ->and(app(ResolveApplicationRole::class)->tryResolve($report['laravel_user_id'], identityTarget()))
        ->toBe(ApplicationRoleName::Administrator)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('refuses production database identity', function () {
    app()->instance(
        ResolvesLiveDatabaseIdentity::class,
        new class implements ResolvesLiveDatabaseIdentity
        {
            public function identity(Connection $connection): string
            {
                return BootstrapFirstAdministratorIdentity::FORBIDDEN_PRODUCTION_DATABASE;
            }
        },
    );

    expect(fn () => runIdentity())
        ->toThrow(RuntimeException::class, 'production database calibration_db');

    expect((int) identityTarget()->table('users')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('refuses maintenance database identity', function () {
    app()->instance(
        ResolvesLiveDatabaseIdentity::class,
        new class implements ResolvesLiveDatabaseIdentity
        {
            public function identity(Connection $connection): string
            {
                return BootstrapFirstAdministratorIdentity::FORBIDDEN_MAINTENANCE_DATABASE;
            }
        },
    );

    expect(fn () => runIdentity())
        ->toThrow(RuntimeException::class, 'maintenance database postgres');

    expect((int) identityTarget()->table('users')->count())->toBe(0);
});

it('does not fall back to the default database connection', function () {
    $defaultBefore = DB::getDefaultConnection();

    runIdentity();

    expect(DB::getDefaultConnection())->toBe($defaultBefore)
        ->and(app(StagingAuthConnection::class)->name())->toBe(IDENTITY_TARGET_CONNECTION)
        ->and(app(StagingAuthConnection::class)->name())->not->toBe($defaultBefore);
});

it('refuses missing credentials and weak passwords', function () {
    expect(fn () => runIdentity(password: ''))
        ->toThrow(RuntimeException::class, 'Password does not meet policy');

    expect(fn () => runIdentity(password: 'short'))
        ->toThrow(RuntimeException::class, 'Password does not meet policy');

    expect(fn () => runIdentity(displayName: ''))
        ->toThrow(RuntimeException::class, 'display name');

    expect(fn () => runIdentity(email: 'not-an-email'))
        ->toThrow(RuntimeException::class, 'email');

    expect((int) identityTarget()->table('users')->count())->toBe(0);
});

it('stores a hashed password never plaintext', function () {
    $report = runIdentity();

    $stored = (string) identityTarget()->table('users')->where('id', $report['laravel_user_id'])->value('password');

    expect($stored)->not->toBe(IDENTITY_PASSWORD)
        ->and(str_starts_with($stored, '$'))->toBeTrue()
        ->and(Hash::check(IDENTITY_PASSWORD, $stored))->toBeTrue();
});

it('refuses duplicate email on users and app_user', function () {
    $now = now();
    identityTarget()->table('users')->insert([
        'name' => 'Existing User',
        'email' => 'staging.admin@example.test',
        'password' => Hash::make('Existing-Pass1!'),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(fn () => runIdentity())
        ->toThrow(RuntimeException::class, 'email already exists');

    expect((int) identityTarget()->table('users')->count())->toBe(1)
        ->and((int) identityTarget()->table('app_user')->count())->toBe(0)
        ->and((int) identityTarget()->table('user_roles')->count())->toBe(0);
});

it('refuses duplicate username', function () {
    $now = now();
    identityTarget()->table('app_user')->insert([
        'username' => 'staging.admin',
        'full_name' => 'Existing Actor',
        'email' => 'existing.actor@example.test',
        'role_name' => 'Engineer',
        'is_active' => true,
        'created_at' => $now,
    ]);

    expect(fn () => runIdentity())
        ->toThrow(RuntimeException::class, 'username already exists');

    expect((int) identityTarget()->table('users')->count())->toBe(0)
        ->and((int) identityTarget()->table('app_user')->count())->toBe(1);
});

it('refuses when an Administrator membership already exists', function () {
    $now = now();
    $userId = (int) identityTarget()->table('users')->insertGetId([
        'name' => 'Existing',
        'email' => 'existing@example.test',
        'password' => Hash::make('Existing-Pass1!'),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    app(AssignApplicationRole::class)->assign($userId, ApplicationRoleName::Administrator, identityTarget());

    expect(fn () => runIdentity())
        ->toThrow(RuntimeException::class, 'Administrator membership already exists');

    expect((int) identityTarget()->table('users')->count())->toBe(1)
        ->and((int) identityTarget()->table('app_user')->count())->toBe(0)
        ->and((int) identityTarget()->table('user_app_user_map')->count())->toBe(0);
});

it('enforces mapping uniqueness and a single active map', function () {
    $report = runIdentity();

    expect((int) identityTarget()->table('user_app_user_map')->where('status', 'active')->count())->toBe(1);

    $map = identityTarget()->table('user_app_user_map')->first();
    expect((int) $map->laravel_user_id)->toBe($report['laravel_user_id'])
        ->and((int) $map->app_user_id)->toBe($report['app_user_id'])
        ->and($map->status)->toBe('active')
        ->and($map->inactivated_at)->toBeNull();
});

it('enforces active app_user for actor resolution', function () {
    $report = runIdentity();

    $active = identityTarget()->table('app_user')->where('app_user_id', $report['app_user_id'])->value('is_active');
    expect($active === true || $active === 1)->toBeTrue();

    identityTarget()->transaction(function () use ($report): void {
        $actor = app(ResolveDomainActor::class)->resolve($report['laravel_user_id'], identityTarget());
        expect($actor)->toBe($report['app_user_id']);
    });
});

it('assigns exactly one Administrator role via existing AssignApplicationRole', function () {
    $report = runIdentity();

    expect(app(ResolveApplicationRole::class)->tryResolve($report['laravel_user_id'], identityTarget()))
        ->toBe(ApplicationRoleName::Administrator)
        ->and((int) identityTarget()->table('user_roles')->count())->toBe(1)
        ->and(identityTarget()->table('app_user')->where('app_user_id', $report['app_user_id'])->value('role_name'))
        ->toBe(BootstrapFirstAdministratorIdentity::DOMAIN_ADMIN_ROLE_NAME);
});

it('rolls back with no partial identity when assignment fails', function () {
    app()->instance(AssignApplicationRole::class, new class extends AssignApplicationRole
    {
        public function assign(int $laravelUserId, ApplicationRoleName $role, ?Connection $connection = null): void
        {
            throw new RuntimeException('forced assignment failure');
        }
    });

    expect(fn () => runIdentity())
        ->toThrow(RuntimeException::class, 'forced assignment failure');

    expect((int) identityTarget()->table('users')->count())->toBe(0)
        ->and((int) identityTarget()->table('app_user')->count())->toBe(0)
        ->and((int) identityTarget()->table('user_app_user_map')->count())->toBe(0)
        ->and((int) identityTarget()->table('user_roles')->count())->toBe(0);
});

it('rolls back when post-assignment verification fails', function () {
    app()->instance(AssignApplicationRole::class, new class extends AssignApplicationRole
    {
        public function assign(int $laravelUserId, ApplicationRoleName $role, ?Connection $connection = null): void
        {
            parent::assign($laravelUserId, $role, $connection);

            throw new RuntimeException('forced post-insert failure');
        }
    });

    expect(fn () => runIdentity())
        ->toThrow(RuntimeException::class, 'forced post-insert failure');

    expect((int) identityTarget()->table('users')->count())->toBe(0)
        ->and((int) identityTarget()->table('app_user')->count())->toBe(0)
        ->and((int) identityTarget()->table('user_app_user_map')->count())->toBe(0)
        ->and((int) identityTarget()->table('user_roles')->count())->toBe(0);
});

it('does not log plaintext passwords', function () {
    Log::spy();

    runIdentity();

    Log::shouldNotHaveReceived('info', fn (...$args): bool => str_contains(json_encode($args) ?: '', IDENTITY_PASSWORD));
    Log::shouldNotHaveReceived('debug', fn (...$args): bool => str_contains(json_encode($args) ?: '', IDENTITY_PASSWORD));
    Log::shouldNotHaveReceived('error', fn (...$args): bool => str_contains(json_encode($args) ?: '', IDENTITY_PASSWORD));
});

it('does not automatically retry after failure', function () {
    $calls = 0;

    app()->instance(AssignApplicationRole::class, new class($calls) extends AssignApplicationRole
    {
        public function __construct(private int &$calls) {}

        public function assign(int $laravelUserId, ApplicationRoleName $role, ?Connection $connection = null): void
        {
            $this->calls++;

            throw new RuntimeException('single failure');
        }
    });

    expect(fn () => runIdentity())->toThrow(RuntimeException::class, 'single failure');
    expect($calls)->toBe(1);
    expect(fn () => runIdentity())->toThrow(RuntimeException::class, 'single failure');
    expect($calls)->toBe(2);
});

it('remains compatible with existing RBAC resolve primitives', function () {
    $report = runIdentity();

    expect(app(ResolveApplicationRole::class)->tryResolve($report['laravel_user_id'], identityTarget()))
        ->toBe(ApplicationRoleName::Administrator)
        ->and(app(ResolveApplicationRole::class)->tryResolve($report['laravel_user_id']))->toBeNull();
});

it('runs through the artisan command without printing the password', function () {
    $this->artisan('staging:bootstrap-first-administrator-identity', identityOptions())
        ->expectsQuestion('Password (never logged)', IDENTITY_PASSWORD)
        ->expectsQuestion('Confirm password', IDENTITY_PASSWORD)
        ->expectsOutputToContain('administrator_assigned: yes')
        ->expectsOutputToContain('actor_resolved: yes')
        ->doesntExpectOutputToContain(IDENTITY_PASSWORD)
        ->assertSuccessful();

    expect((int) identityTarget()->table('users')->count())->toBe(1)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('refuses wrong confirm-database and confirm-checkpoint without writes', function () {
    $this->artisan('staging:bootstrap-first-administrator-identity', identityOptions([
        '--confirm-database' => 'calibration_db',
    ]))->assertFailed();

    $this->artisan('staging:bootstrap-first-administrator-identity', identityOptions([
        '--confirm-checkpoint' => 'WRONG',
    ]))->assertFailed();

    expect((int) identityTarget()->table('users')->count())->toBe(0);
});
