<?php

use App\Authorization\ApplicationRoleName;
use App\Authorization\AssignApplicationRole;
use App\Authorization\CreateStagingTestUser;
use App\Authorization\ResolvesLiveDatabaseIdentity;
use App\Authorization\StagingAuthConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestRefusingPostgresConnector;

const TEST_USER_SQLITE_TARGET = 'identity_test_user_sqlite';
const TEST_USER_PASSWORD = 'simplepw';

beforeEach(function (): void {
    createTestUserTarget();
    app()->instance(StagingAuthConnection::class, new StagingAuthConnection(TEST_USER_SQLITE_TARGET));

    app()->instance(
        ResolvesLiveDatabaseIdentity::class,
        new class implements ResolvesLiveDatabaseIdentity
        {
            public function identity(Connection $connection): string
            {
                return CreateStagingTestUser::REQUIRED_DATABASE;
            }
        },
    );

    config([
        'database.staging_runtime_isolation' => true,
        'database.default' => 'pgsql',
        'database.connections.pgsql' => [
            'driver' => 'pgsql',
            'database' => CreateStagingTestUser::REQUIRED_DATABASE,
            'host' => '127.0.0.1',
            'port' => 5432,
            'url' => null,
        ],
        'session.driver' => 'file',
        'cache.default' => 'array',
        'queue.default' => 'sync',
    ]);
});

afterEach(function (): void {
    DB::purge(TEST_USER_SQLITE_TARGET);
    config([
        'database.default' => 'sqlite',
        'database.staging_runtime_isolation' => false,
    ]);
});

function createTestUserTarget(): Connection
{
    config(['database.connections.'.TEST_USER_SQLITE_TARGET => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::purge(TEST_USER_SQLITE_TARGET);

    $connection = DB::connection(TEST_USER_SQLITE_TARGET);
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
        $table->string('name')->unique();
        $table->timestamps();
    });
    $schema->create('user_roles', function ($table): void {
        $table->id();
        $table->foreignId('laravel_user_id')->constrained('users');
        $table->foreignId('role_id')->constrained('roles');
        $table->timestamps();
        $table->unique('laravel_user_id');
    });
    $connection->statement("CREATE TABLE app_user (
        app_user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        username VARCHAR(100) NOT NULL UNIQUE,
        full_name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE,
        role_name VARCHAR(50) NOT NULL,
        is_active BOOLEAN NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL,
        CONSTRAINT chk_app_user_role CHECK (role_name IN ('ADMIN', 'CALIBRATION_STAFF', 'VIEWER'))
    )");
    $schema->create('user_app_user_map', function ($table): void {
        $table->id('user_app_user_map_id');
        $table->unsignedBigInteger('laravel_user_id')->unique();
        $table->unsignedBigInteger('app_user_id')->unique();
        $table->string('status', 20);
        $table->timestamp('created_at');
        $table->timestamp('inactivated_at')->nullable();
    });

    $now = now();
    foreach (ApplicationRoleName::cases() as $role) {
        $connection->table('roles')->insert(['name' => $role->value, 'created_at' => $now, 'updated_at' => $now]);
    }

    return $connection;
}

function createTestUser(array $overrides = []): array
{
    return array_merge([
        '--name' => 'Staging Engineer',
        '--username' => 'staging.engineer',
        '--email' => 'staging.engineer@example.test',
        '--role' => 'Engineer',
        '--confirm-database' => CreateStagingTestUser::REQUIRED_DATABASE,
        '--confirm-write' => CreateStagingTestUser::WRITE_CONFIRMATION,
        '--yes' => true,
    ], $overrides);
}

function runStagingTestUser(array $overrides = []): void
{
    $options = createTestUser($overrides);

    app(CreateStagingTestUser::class)->run(
        (string) $options['--name'],
        (string) $options['--username'],
        (string) $options['--email'],
        (string) $options['--role'],
        TEST_USER_PASSWORD,
        TEST_USER_PASSWORD,
        (string) $options['--confirm-database'],
        (string) $options['--confirm-write'],
    );
}

it('creates an Engineer with an active mapping and hashed password', function (): void {
    runStagingTestUser();

    $connection = DB::connection(TEST_USER_SQLITE_TARGET);
    $user = $connection->table('users')->first();
    $appUser = $connection->table('app_user')->first();

    expect($user)->not->toBeNull()
        ->and(Hash::check(TEST_USER_PASSWORD, $user->password))->toBeTrue()
        ->and($appUser->role_name)->toBe('CALIBRATION_STAFF')
        ->and((bool) $appUser->is_active)->toBeTrue()
        ->and($connection->table('user_app_user_map')->where('status', 'active')->whereNull('inactivated_at')->count())->toBe(1)
        ->and($connection->table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->value('roles.name'))->toBe('Engineer');
});

it('creates a Viewer and rejects Administrator or invalid roles', function (): void {
    runStagingTestUser(createTestUser([
        '--name' => 'Staging Viewer',
        '--username' => 'staging.viewer',
        '--email' => 'staging.viewer@example.test',
        '--role' => 'Viewer',
    ]));

    expect(DB::connection(TEST_USER_SQLITE_TARGET)->table('app_user')->value('role_name'))->toBe('VIEWER')
        ->and(DB::connection(TEST_USER_SQLITE_TARGET)->table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->value('roles.name'))->toBe('Viewer');

    expect(fn () => runStagingTestUser(['--role' => 'Administrator']))
        ->toThrow(RuntimeException::class, 'Only Engineer and Viewer roles');
});

it('rejects duplicate email and username without partial writes', function (): void {
    runStagingTestUser();

    expect(fn () => runStagingTestUser(['--username' => 'another.user']))
        ->toThrow(RuntimeException::class, 'email already exists');

    expect(fn () => runStagingTestUser(['--email' => 'another@example.test']))
        ->toThrow(RuntimeException::class, 'username already exists');

    expect(DB::connection(TEST_USER_SQLITE_TARGET)->table('users')->count())->toBe(1)
        ->and(DB::connection(TEST_USER_SQLITE_TARGET)->table('app_user')->count())->toBe(1)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects passwords shorter than eight characters before writes', function (): void {
    expect(fn () => app(CreateStagingTestUser::class)->run(
        'Staging Engineer',
        'staging.engineer',
        'staging.engineer@example.test',
        'Engineer',
        'weak',
        'weak',
        CreateStagingTestUser::REQUIRED_DATABASE,
        CreateStagingTestUser::WRITE_CONFIRMATION,
    ))->toThrow(RuntimeException::class, 'Password does not meet policy');

    expect(fn () => app(CreateStagingTestUser::class)->run(
        'Staging Engineer',
        'staging.engineer',
        'staging.engineer@example.test',
        'Engineer',
        TEST_USER_PASSWORD,
        TEST_USER_PASSWORD,
        'calibration_db',
        CreateStagingTestUser::WRITE_CONFIRMATION,
    ))->toThrow(RuntimeException::class, 'target must be exactly');

    expect(DB::connection(TEST_USER_SQLITE_TARGET)->table('users')->count())->toBe(0);
});

it('rejects a password confirmation mismatch before writes', function (): void {
    expect(fn () => app(CreateStagingTestUser::class)->run(
        'Staging Engineer',
        'staging.engineer',
        'staging.engineer@example.test',
        'Engineer',
        TEST_USER_PASSWORD,
        'different',
        CreateStagingTestUser::REQUIRED_DATABASE,
        CreateStagingTestUser::WRITE_CONFIRMATION,
    ))->toThrow(RuntimeException::class, 'Password confirmation does not match');

    expect(DB::connection(TEST_USER_SQLITE_TARGET)->table('users')->count())->toBe(0);
});

it('fails closed when staging runtime isolation is disabled', function (): void {
    config(['database.staging_runtime_isolation' => false]);

    expect(fn () => runStagingTestUser())
        ->toThrow(RuntimeException::class, 'isolation must be enabled');

    expect(DB::connection(TEST_USER_SQLITE_TARGET)->table('users')->count())->toBe(0);
});

it('rejects a command without explicit staging write confirmation', function (): void {
    test()->artisan('staging:create-test-user', createTestUser(['--confirm-write' => '']))
        ->assertFailed();

    expect(DB::connection(TEST_USER_SQLITE_TARGET)->table('users')->count())->toBe(0);
});

it('rolls back every identity table when role assignment fails', function (): void {
    app()->instance(AssignApplicationRole::class, new class extends AssignApplicationRole
    {
        public function assign(int $laravelUserId, ApplicationRoleName $role, ?Connection $connection = null): void
        {
            throw new RuntimeException('forced role assignment failure');
        }
    });

    expect(fn () => runStagingTestUser())
        ->toThrow(RuntimeException::class, 'forced role assignment failure');

    $connection = DB::connection(TEST_USER_SQLITE_TARGET);
    expect($connection->table('users')->count())->toBe(0)
        ->and($connection->table('app_user')->count())->toBe(0)
        ->and($connection->table('user_app_user_map')->count())->toBe(0)
        ->and($connection->table('user_roles')->count())->toBe(0);
});

it('rejects a live production identity before writing', function (): void {
    app()->instance(
        ResolvesLiveDatabaseIdentity::class,
        new class implements ResolvesLiveDatabaseIdentity
        {
            public function identity(Connection $connection): string
            {
                return 'calibration_db';
            }
        },
    );

    expect(fn () => runStagingTestUser())
        ->toThrow(RuntimeException::class, 'not calibration_staging_db');

    expect(DB::connection(TEST_USER_SQLITE_TARGET)->table('users')->count())->toBe(0);
});

it('does not change an existing Administrator identity', function (): void {
    $connection = DB::connection(TEST_USER_SQLITE_TARGET);
    $now = now();
    $adminUserId = (int) $connection->table('users')->insertGetId([
        'name' => 'Existing Administrator',
        'email' => 'existing.admin@example.test',
        'password' => Hash::make('Existing-Admin-Pass1!'),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $adminAppUserId = (int) $connection->table('app_user')->insertGetId([
        'username' => 'existing.admin',
        'full_name' => 'Existing Administrator',
        'email' => 'existing.admin@example.test',
        'role_name' => 'ADMIN',
        'is_active' => true,
        'created_at' => $now,
    ]);
    $connection->table('user_app_user_map')->insert([
        'laravel_user_id' => $adminUserId,
        'app_user_id' => $adminAppUserId,
        'status' => 'active',
        'created_at' => $now,
        'inactivated_at' => null,
    ]);
    $adminRoleId = $connection->table('roles')->where('name', 'Administrator')->value('id');
    $connection->table('user_roles')->insert([
        'laravel_user_id' => $adminUserId,
        'role_id' => $adminRoleId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    runStagingTestUser();

    expect($connection->table('app_user')->where('app_user_id', $adminAppUserId)->value('role_name'))->toBe('ADMIN')
        ->and($connection->table('user_roles')->where('laravel_user_id', $adminUserId)->value('role_id'))->toBe($adminRoleId)
        ->and($connection->table('user_app_user_map')->where('laravel_user_id', $adminUserId)->value('status'))->toBe('active');
});
