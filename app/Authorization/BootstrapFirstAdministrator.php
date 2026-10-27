<?php

namespace App\Authorization;

use App\StagingPreflight;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Assigns the first Administrator role to an existing Laravel user.
 *
 * Console-only bootstrap boundary. Does not create users, reset passwords,
 * replace roles, recover lost Admins, or write domain actor mappings.
 *
 * Every bootstrap query/lock/write/verify uses one explicit Connection from
 * StagingAuthConnection. Does not call DB::setDefaultConnection or unbound
 * Model/DB/Schema facades.
 *
 * Concurrency (PostgreSQL): locks the Administrator roles row with
 * lockForUpdate inside a transaction, then rechecks Administrator membership
 * before insert. SQLite compiles lockForUpdate without providing the same
 * row-lock guarantee — Pest proves control flow, not PostgreSQL locking.
 *
 * Residual ops risk: live identity is the database name on the approved
 * Connection (current_database() for pgsql). Host/endpoint correctness is
 * outside this guard and remains an operator responsibility.
 */
final class BootstrapFirstAdministrator
{
    public const REQUIRED_DATABASE = StagingPreflight::REQUIRED_DATABASE;

    public const FORBIDDEN_PRODUCTION_DATABASE = 'calibration_db';

    public function __construct(
        private readonly StagingAuthConnection $stagingAuth,
        private readonly ResolvesLiveDatabaseIdentity $identity,
        private readonly AssignApplicationRole $assignRole,
        private readonly ResolveApplicationRole $resolveRole,
    ) {}

    /**
     * @return array{
     *     dry_run: bool,
     *     database: string,
     *     connection: string,
     *     user_id: int|null,
     *     email: string,
     *     role_catalog_ready: bool,
     *     administrator_exists: bool,
     *     allowed: bool,
     *     assigned: bool,
     *     domain_mapping_warning: string|null,
     *     message: string
     * }
     */
    public function run(string $email, string $confirmDatabase, bool $dryRun = false): array
    {
        $email = trim($email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid --email of an existing Laravel user is required.');
        }

        if ($confirmDatabase !== self::REQUIRED_DATABASE) {
            throw new RuntimeException(
                'Confirmation mismatch: --confirm-database must be exactly '.self::REQUIRED_DATABASE.'.',
            );
        }

        $connection = $this->stagingAuth->connection();
        $connectionName = $connection->getName();

        if (! is_string($connectionName) || $connectionName === '') {
            throw new RuntimeException('Staging auth bootstrap requires a named database connection.');
        }

        $liveDatabase = $this->identity->identity($connection);

        if ($liveDatabase === '') {
            throw new RuntimeException(
                'Live database identity could not be proven for connection ['.$this->stagingAuth->name().'].',
            );
        }

        if ($liveDatabase === self::FORBIDDEN_PRODUCTION_DATABASE) {
            throw new RuntimeException('Refusing bootstrap against production database calibration_db.');
        }

        if ($liveDatabase !== self::REQUIRED_DATABASE) {
            throw new RuntimeException(
                'Live database identity ['.$liveDatabase.'] is not '.self::REQUIRED_DATABASE.'.',
            );
        }

        $this->assertSchemaReady($connection);

        $adminRole = $connection->table('roles')
            ->where('name', ApplicationRoleName::Administrator->value)
            ->first();

        if ($adminRole === null) {
            throw new RuntimeException('Administrator role is missing from the role catalog.');
        }

        $matches = $connection->table('users')
            ->where('email', $email)
            ->get(['id', 'email', 'password']);

        if ($matches->isEmpty()) {
            throw new RuntimeException('No Laravel user exists with that exact email.');
        }

        if ($matches->count() > 1) {
            throw new RuntimeException('Ambiguous Laravel user identity for that email.');
        }

        $user = $matches->first();
        $userId = (int) $user->id;
        $passwordHashBefore = (string) $user->password;

        if ($connection->table('user_roles')->where('laravel_user_id', $userId)->exists()) {
            throw new RuntimeException('Target user already has an application role.');
        }

        $administratorExists = $this->administratorMembershipExists($connection);

        $domainWarning = $this->domainMappingWarning($connection, $userId);

        $base = [
            'dry_run' => $dryRun,
            'database' => $liveDatabase,
            'connection' => $connectionName,
            'user_id' => $userId,
            'email' => (string) $user->email,
            'role_catalog_ready' => true,
            'administrator_exists' => $administratorExists,
            'allowed' => ! $administratorExists,
            'assigned' => false,
            'domain_mapping_warning' => $domainWarning,
            'message' => '',
        ];

        if ($administratorExists) {
            $base['message'] = 'An Administrator membership already exists; first-admin bootstrap is denied.';

            if ($dryRun) {
                return $base;
            }

            throw new RuntimeException($base['message']);
        }

        if ($dryRun) {
            $base['message'] = 'Dry-run OK: bootstrap would assign Administrator to the existing user.';

            return $base;
        }

        $connection->transaction(function () use ($connection, $userId, $passwordHashBefore): void {
            $connection->table('roles')
                ->where('name', ApplicationRoleName::Administrator->value)
                ->lockForUpdate()
                ->first();

            if ($this->administratorMembershipExists($connection)) {
                throw new RuntimeException(
                    'An Administrator membership already exists; first-admin bootstrap is denied.',
                );
            }

            if ($connection->table('user_roles')->where('laravel_user_id', $userId)->exists()) {
                throw new RuntimeException('Target user already has an application role.');
            }

            $this->assignRole->assign($userId, ApplicationRoleName::Administrator, $connection);

            $passwordHashAfter = (string) $connection->table('users')->where('id', $userId)->value('password');

            if ($passwordHashAfter !== $passwordHashBefore) {
                throw new RuntimeException('Bootstrap aborted: user password hash changed unexpectedly.');
            }

            if ($this->resolveRole->tryResolve($userId, $connection) !== ApplicationRoleName::Administrator) {
                throw new RuntimeException('Bootstrap failed to verify Administrator assignment.');
            }
        });

        $base['assigned'] = true;
        $base['administrator_exists'] = true;
        $base['allowed'] = false;
        $base['message'] = 'Administrator role assigned to existing user.';
        $base['domain_mapping_warning'] = $this->domainMappingWarning($connection, $userId);

        return $base;
    }

    private function assertSchemaReady(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();

        foreach (['users', 'roles', 'user_roles'] as $table) {
            if (! $schema->hasTable($table)) {
                throw new RuntimeException("Required auth table [{$table}] is missing.");
            }
        }
    }

    private function administratorMembershipExists(Connection $connection): bool
    {
        $adminRoleId = $connection->table('roles')
            ->where('name', ApplicationRoleName::Administrator->value)
            ->value('id');

        if ($adminRoleId === null) {
            return false;
        }

        return $connection->table('user_roles')->where('role_id', $adminRoleId)->exists();
    }

    private function domainMappingWarning(Connection $connection, int $laravelUserId): ?string
    {
        if (! $connection->getSchemaBuilder()->hasTable('user_app_user_map')) {
            return 'Domain actor mapping could not be verified (user_app_user_map is absent). Start/Cancel still require an active mapping.';
        }

        $active = $connection->table('user_app_user_map')
            ->where('laravel_user_id', $laravelUserId)
            ->where('status', 'active')
            ->whereNull('inactivated_at')
            ->count();

        if ($active === 1) {
            return null;
        }

        if ($active > 1) {
            return 'Domain actor mapping is ambiguous for this user. Start/Cancel require exactly one active mapping.';
        }

        return 'No active domain actor mapping for this user. Login may work; Start/Cancel still require an active user_app_user_map.';
    }
}
