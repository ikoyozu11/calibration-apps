<?php

namespace App\Authorization;

use App\CalibrationSessions\ResolveDomainActor;
use App\StagingPreflight;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Staging-only first Administrator identity bootstrap.
 *
 * Creates users + app_user + active user_app_user_map + Administrator membership
 * inside one explicit Connection transaction. Does not call
 * BootstrapFirstAdministrator::run() (that service opens its own transaction
 * and requires a pre-existing user). Reuses AssignApplicationRole /
 * ResolveApplicationRole / ResolveDomainActor for assignment and verification.
 *
 * Does not log or return passwords or password hashes.
 */
final class BootstrapFirstAdministratorIdentity
{
    public const REQUIRED_DATABASE = StagingPreflight::REQUIRED_DATABASE;

    public const FORBIDDEN_PRODUCTION_DATABASE = 'calibration_db';

    public const FORBIDDEN_MAINTENANCE_DATABASE = 'postgres';

    /**
     * Domain catalog value for app_user.role_name (chk_app_user_role).
     * Distinct from Laravel RBAC ApplicationRoleName::Administrator.
     */
    public const DOMAIN_ADMIN_ROLE_NAME = 'ADMIN';

    public function __construct(
        private readonly StagingAuthConnection $stagingAuth,
        private readonly ResolvesLiveDatabaseIdentity $identity,
        private readonly AssignApplicationRole $assignRole,
        private readonly ResolveApplicationRole $resolveRole,
        private readonly ResolveDomainActor $resolveDomainActor,
    ) {}

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
    public function run(
        string $displayName,
        string $username,
        string $email,
        string $password,
        string $confirmDatabase,
    ): array {
        $displayName = trim($displayName);
        $username = trim($username);
        $email = trim($email);

        $this->assertIdentityInput($displayName, $username, $email, $password);

        if ($confirmDatabase !== self::REQUIRED_DATABASE) {
            throw new RuntimeException(
                'Confirmation mismatch: confirm-database must be exactly '.self::REQUIRED_DATABASE.'.',
            );
        }

        $connection = $this->stagingAuth->connection();
        $connectionName = $connection->getName();

        if (! is_string($connectionName) || $connectionName === '') {
            throw new RuntimeException('Staging identity bootstrap requires a named database connection.');
        }

        $liveDatabase = $this->identity->identity($connection);

        if ($liveDatabase === self::FORBIDDEN_PRODUCTION_DATABASE) {
            throw new RuntimeException('Refusing identity bootstrap against production database calibration_db.');
        }

        if ($liveDatabase === self::FORBIDDEN_MAINTENANCE_DATABASE) {
            throw new RuntimeException('Refusing identity bootstrap against maintenance database postgres.');
        }

        if ($liveDatabase !== self::REQUIRED_DATABASE) {
            throw new RuntimeException(
                'Live database identity ['.$liveDatabase.'] is not '.self::REQUIRED_DATABASE.'.',
            );
        }

        $this->assertSchemaReady($connection);
        $this->assertNoExistingAdministrator($connection);
        $this->assertNoIdentityCollision($connection, $email, $username);

        $passwordHash = Hash::make($password);
        // Drop plaintext from local scope as soon as hashed.
        unset($password);

        $created = $connection->transaction(function () use ($connection, $displayName, $username, $email, $passwordHash): array {
            $connection->table('roles')
                ->where('name', ApplicationRoleName::Administrator->value)
                ->lockForUpdate()
                ->first();

            $this->assertNoExistingAdministrator($connection);
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

            $storedHash = (string) $connection->table('users')->where('id', $laravelUserId)->value('password');
            if ($storedHash === '' || ! str_starts_with($storedHash, '$') || $storedHash !== $passwordHash) {
                throw new RuntimeException('Identity bootstrap aborted: password was not stored as a hash.');
            }

            $appUserId = (int) $connection->table('app_user')->insertGetId([
                'username' => $username,
                'full_name' => $displayName,
                'email' => $email,
                'role_name' => self::DOMAIN_ADMIN_ROLE_NAME,
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

            $this->assignRole->assign($laravelUserId, ApplicationRoleName::Administrator, $connection);

            if ($this->resolveRole->tryResolve($laravelUserId, $connection) !== ApplicationRoleName::Administrator) {
                throw new RuntimeException('Identity bootstrap failed to verify Administrator assignment.');
            }

            $actorId = $this->resolveDomainActor->resolve($laravelUserId, $connection);
            if ($actorId !== $appUserId) {
                throw new RuntimeException('Identity bootstrap failed actor resolution verification.');
            }

            $this->assertSingleAdministratorMembership($connection, $laravelUserId);
            $this->assertSingleActiveMapping($connection, $laravelUserId, $appUserId);

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
            'administrator_assigned' => true,
            'actor_resolved' => true,
            'message' => 'First Administrator identity created on staging.',
        ];
    }

    private function assertIdentityInput(string $displayName, string $username, string $email, string $password): void
    {
        if ($displayName === '' || mb_strlen($displayName) > 255) {
            throw new RuntimeException('A valid display name is required.');
        }

        if ($username === '' || mb_strlen($username) > 100 || ! preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
            throw new RuntimeException('A valid username is required (letters, numbers, dot, underscore, hyphen).');
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            throw new RuntimeException('A valid email address is required.');
        }

        try {
            Validator::make(
                ['password' => $password],
                ['password' => ['required', 'string', Password::min(12)->mixedCase()->letters()->numbers()->symbols()]],
            )->validate();
        } catch (ValidationException $exception) {
            throw new RuntimeException('Password does not meet policy: '.implode(' ', $exception->validator->errors()->all()));
        }
    }

    private function assertSchemaReady(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();

        foreach (['users', 'roles', 'user_roles', 'app_user', 'user_app_user_map'] as $table) {
            if (! $schema->hasTable($table)) {
                throw new RuntimeException("Required identity table [{$table}] is missing.");
            }
        }

        foreach (['username', 'full_name', 'email', 'role_name', 'is_active', 'created_at'] as $column) {
            if (! $schema->hasColumn('app_user', $column)) {
                throw new RuntimeException("Required app_user column [{$column}] is missing.");
            }
        }
    }

    private function assertNoExistingAdministrator(Connection $connection): void
    {
        $adminRoleId = $connection->table('roles')
            ->where('name', ApplicationRoleName::Administrator->value)
            ->value('id');

        if ($adminRoleId === null) {
            throw new RuntimeException('Administrator role is missing from the role catalog.');
        }

        if ($connection->table('user_roles')->where('role_id', $adminRoleId)->exists()) {
            throw new RuntimeException('An Administrator membership already exists; first-admin identity bootstrap is denied.');
        }
    }

    private function assertNoIdentityCollision(Connection $connection, string $email, string $username): void
    {
        if ($connection->table('users')->where('email', $email)->exists()) {
            throw new RuntimeException('A Laravel user with that email already exists.');
        }

        if ($connection->table('app_user')->where('username', $username)->exists()) {
            throw new RuntimeException('A domain app_user with that username already exists.');
        }

        if ($connection->table('app_user')->where('email', $email)->exists()) {
            throw new RuntimeException('A domain app_user with that email already exists.');
        }
    }

    private function assertSingleAdministratorMembership(Connection $connection, int $laravelUserId): void
    {
        $adminRoleId = $connection->table('roles')
            ->where('name', ApplicationRoleName::Administrator->value)
            ->value('id');

        $adminCount = $connection->table('user_roles')->where('role_id', $adminRoleId)->count();
        if ($adminCount !== 1) {
            throw new RuntimeException('Expected exactly one Administrator membership after bootstrap.');
        }

        $userRole = $connection->table('user_roles')->where('laravel_user_id', $laravelUserId)->first();
        if ($userRole === null || (int) $userRole->role_id !== (int) $adminRoleId) {
            throw new RuntimeException('Administrator assignment missing for the new Laravel user.');
        }
    }

    private function assertSingleActiveMapping(Connection $connection, int $laravelUserId, int $appUserId): void
    {
        $maps = $connection->table('user_app_user_map')
            ->where('laravel_user_id', $laravelUserId)
            ->where('status', 'active')
            ->whereNull('inactivated_at')
            ->get();

        if ($maps->count() !== 1) {
            throw new RuntimeException('Expected exactly one active user_app_user_map for the new user.');
        }

        $map = $maps->first();
        if ((int) $map->app_user_id !== $appUserId) {
            throw new RuntimeException('Active mapping does not reference the created app_user.');
        }

        $active = $connection->table('app_user')->where('app_user_id', $appUserId)->value('is_active');
        if (! filter_var($active, FILTER_VALIDATE_BOOLEAN) && $active !== 1 && $active !== true) {
            throw new RuntimeException('Created app_user must be active.');
        }
    }
}
