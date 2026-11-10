<?php

namespace App\Authorization;

use App\StagingRuntime\StagingRuntimeIsolation;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Creates one non-Administrator identity on the explicitly guarded staging connection.
 */
final class CreateStagingTestUser
{
    public const REQUIRED_DATABASE = StagingRuntimeIsolation::REQUIRED_DATABASE;

    public const REQUIRED_CONNECTION = StagingAuthConnection::CONNECTION_NAME;

    public const WRITE_CONFIRMATION = 'AUTHORIZED';

    /** @var array<string, string> */
    private const DOMAIN_ROLE_NAMES = [
        'Engineer' => 'CALIBRATION_STAFF',
        'Viewer' => 'VIEWER',
    ];

    public function __construct(
        private readonly StagingAuthConnection $stagingAuth,
        private readonly ResolvesLiveDatabaseIdentity $identity,
        private readonly StagingRuntimeIsolation $isolation,
        private readonly Repository $config,
        private readonly AssignApplicationRole $assignRole,
        private readonly ResolveApplicationRole $resolveRole,
    ) {}

    /**
     * @return array{
     *     database: string,
     *     connection: string,
     *     laravel_user_id: int,
     *     app_user_id: int,
     *     email: string,
     *     username: string,
     *     role: string,
     *     message: string
     * }
     */
    public function run(
        string $displayName,
        string $username,
        string $email,
        string $role,
        string $password,
        string $passwordConfirmation,
        string $confirmDatabase,
        string $confirmWrite,
    ): array {
        $displayName = trim($displayName);
        $username = trim($username);
        $email = trim($email);
        $role = trim($role);

        $this->assertInput($displayName, $username, $email, $role, $password);

        if ($password === '' || ! hash_equals($password, $passwordConfirmation)) {
            throw new RuntimeException('Password confirmation does not match.');
        }

        if ($confirmDatabase !== self::REQUIRED_DATABASE) {
            throw new RuntimeException('Confirmation mismatch: target must be exactly '.self::REQUIRED_DATABASE.'.');
        }

        if ($confirmWrite !== self::WRITE_CONFIRMATION) {
            throw new RuntimeException('Explicit staging write confirmation is required.');
        }

        $this->assertRuntimeIsolation();

        $connection = $this->stagingAuth->connection();
        $this->assertExplicitStagingConnection($connection);
        $connectionName = (string) $connection->getName();
        $liveDatabase = $this->assertLiveIdentity($connection);
        $this->assertSchemaReady($connection);
        $this->assertRoleCatalog($connection, $role);
        $this->assertNoIdentityCollision($connection, $email, $username);

        $passwordHash = Hash::make($password);
        unset($password);

        $created = $connection->transaction(function () use (
            $connection,
            $displayName,
            $username,
            $email,
            $role,
            $passwordHash,
        ): array {
            $this->assertNoIdentityCollision($connection, $email, $username);

            $now = now();
            $laravelUserId = (int) $connection->table('users')->insertGetId([
                'name' => $displayName,
                'email' => $email,
                'email_verified_at' => null,
                'password' => $passwordHash,
                'remember_token' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $appUserId = (int) $connection->table('app_user')->insertGetId([
                'username' => $username,
                'full_name' => $displayName,
                'email' => $email,
                'role_name' => self::DOMAIN_ROLE_NAMES[$role],
                'is_active' => true,
                'created_at' => $now,
            ], 'app_user_id');

            $connection->table('user_app_user_map')->insert([
                'laravel_user_id' => $laravelUserId,
                'app_user_id' => $appUserId,
                'status' => 'active',
                'created_at' => $now,
                'inactivated_at' => null,
            ]);

            $this->assignRole->assign($laravelUserId, ApplicationRoleName::from($role), $connection);
            $this->assertCreatedIdentity($connection, $laravelUserId, $appUserId, $role, $passwordHash);

            return [
                'laravel_user_id' => $laravelUserId,
                'app_user_id' => $appUserId,
            ];
        });

        return [
            'database' => $liveDatabase,
            'connection' => $connectionName,
            'laravel_user_id' => $created['laravel_user_id'],
            'app_user_id' => $created['app_user_id'],
            'email' => $email,
            'username' => $username,
            'role' => $role,
            'message' => 'Staging test user created successfully.',
        ];
    }

    private function assertRuntimeIsolation(): void
    {
        if (! $this->isolation->enabled($this->config)) {
            throw new RuntimeException('Staging runtime isolation must be enabled.');
        }

        $this->isolation->assertNoProductionTarget($this->config);
        $this->isolation->assertSafe(
            $this->config,
            app()->configurationIsCached(),
            app()->getCachedConfigPath(),
        );
    }

    private function assertExplicitStagingConnection(Connection $connection): void
    {
        $connectionName = $connection->getName();
        $configured = $this->config->get('database.connections.'.$connectionName);
        if (! is_array($configured)) {
            throw new RuntimeException('The named staging connection configuration is missing.');
        }

        if (app()->environment('testing') && ($configured['driver'] ?? null) === 'sqlite' && ($configured['database'] ?? null) === ':memory:') {
            return;
        }

        if ($connectionName !== self::REQUIRED_CONNECTION) {
            throw new RuntimeException('Test user creation requires the named pgsql_staging connection.');
        }

        if (($configured['driver'] ?? null) !== 'pgsql'
            || ($configured['database'] ?? null) !== self::REQUIRED_DATABASE
            || ($configured['host'] ?? null) !== StagingRuntimeIsolation::REQUIRED_HOST
            || (int) ($configured['port'] ?? 0) !== StagingRuntimeIsolation::REQUIRED_PORT
            || ! in_array($configured['url'] ?? null, [null, ''], true)) {
            throw new RuntimeException('The named staging connection is not explicitly pinned to calibration_staging_db.');
        }
    }

    private function assertLiveIdentity(Connection $connection): string
    {
        if ($connection->getDriverName() === 'pgsql') {
            $identity = $connection->selectOne(
                'select current_database() as database_name, inet_server_addr() as server_address, inet_server_port() as server_port',
            );

            if ($identity === null
                || $identity->database_name !== self::REQUIRED_DATABASE
                || $identity->server_address !== StagingRuntimeIsolation::REQUIRED_HOST
                || (int) $identity->server_port !== StagingRuntimeIsolation::REQUIRED_PORT) {
                throw new RuntimeException('Live staging database identity or endpoint verification failed.');
            }

            return self::REQUIRED_DATABASE;
        }

        $identity = $this->identity->identity($connection);
        if ($identity !== self::REQUIRED_DATABASE) {
            throw new RuntimeException('Live database identity is not calibration_staging_db.');
        }

        return $identity;
    }

    private function assertSchemaReady(Connection $connection): void
    {
        $schema = Schema::connection($connection->getName());

        foreach (['users', 'roles', 'user_roles', 'app_user', 'user_app_user_map'] as $table) {
            if (! $schema->hasTable($table)) {
                throw new RuntimeException("Required identity table [{$table}] is missing.");
            }
        }

        $columns = [
            'users' => ['id', 'name', 'email', 'password', 'created_at', 'updated_at'],
            'app_user' => ['app_user_id', 'username', 'full_name', 'email', 'role_name', 'is_active', 'created_at'],
            'user_app_user_map' => ['laravel_user_id', 'app_user_id', 'status', 'created_at', 'inactivated_at'],
            'user_roles' => ['laravel_user_id', 'role_id'],
            'roles' => ['id', 'name'],
        ];

        foreach ($columns as $table => $required) {
            foreach ($required as $column) {
                if (! $schema->hasColumn($table, $column)) {
                    throw new RuntimeException("Required identity column [{$table}.{$column}] is missing.");
                }
            }
        }
    }

    private function assertRoleCatalog(Connection $connection, string $role): void
    {
        if ($connection->table('roles')->where('name', $role)->doesntExist()) {
            throw new RuntimeException("Application role [{$role}] is not seeded.");
        }
    }

    private function assertNoIdentityCollision(Connection $connection, string $email, string $username): void
    {
        if ($connection->table('users')->whereRaw('lower(email) = lower(?)', [$email])->exists()) {
            throw new RuntimeException('A Laravel user with that email already exists.');
        }

        if ($connection->table('app_user')->whereRaw('lower(username) = lower(?)', [$username])->exists()) {
            throw new RuntimeException('A domain app_user with that username already exists.');
        }

        if ($connection->table('app_user')->whereRaw('lower(email) = lower(?)', [$email])->exists()) {
            throw new RuntimeException('A domain app_user with that email already exists.');
        }
    }

    private function assertCreatedIdentity(
        Connection $connection,
        int $laravelUserId,
        int $appUserId,
        string $role,
        string $passwordHash,
    ): void {
        $storedHash = (string) $connection->table('users')->where('id', $laravelUserId)->value('password');
        if ($storedHash !== $passwordHash || ! str_starts_with($storedHash, '$')) {
            throw new RuntimeException('Identity creation aborted: password hash verification failed.');
        }

        $mapping = $connection->table('user_app_user_map')
            ->where('laravel_user_id', $laravelUserId)
            ->where('app_user_id', $appUserId)
            ->where('status', 'active')
            ->whereNull('inactivated_at')
            ->first();

        $active = $connection->table('app_user')->where('app_user_id', $appUserId)->value('is_active');
        if ($mapping === null || ! in_array($active, [true, 1, '1'], true)) {
            throw new RuntimeException('Identity creation aborted: active mapping verification failed.');
        }

        if ($this->resolveRole->tryResolve($laravelUserId, $connection) !== ApplicationRoleName::from($role)) {
            throw new RuntimeException('Identity creation aborted: application role verification failed.');
        }

        if ($connection->table('user_roles')->where('laravel_user_id', $laravelUserId)->count() !== 1) {
            throw new RuntimeException('Identity creation aborted: expected exactly one application role.');
        }
    }

    private function assertInput(string $displayName, string $username, string $email, string $role, string $password): void
    {
        if ($displayName === '' || mb_strlen($displayName) > 255) {
            throw new RuntimeException('A valid display name is required.');
        }

        if ($username === '' || mb_strlen($username) > 100 || preg_match('/^[A-Za-z0-9._-]+$/', $username) !== 1) {
            throw new RuntimeException('A valid username is required.');
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            throw new RuntimeException('A valid email address is required.');
        }

        if (! array_key_exists($role, self::DOMAIN_ROLE_NAMES)) {
            throw new RuntimeException('Only Engineer and Viewer roles may be created.');
        }

        try {
            Validator::make(
                ['password' => $password],
                ['password' => ['required', 'string', Password::min(8)]],
            )->validate();
        } catch (ValidationException $exception) {
            throw new RuntimeException('Password does not meet policy.');
        }
    }
}
