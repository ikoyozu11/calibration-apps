<?php

namespace App\Authorization;

use App\CalibrationSessions\CalibrationHttpConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Transactional user-management operations for the Administrator UI.
 *
 * Authorization is enforced by the Livewire entry point before each call.
 * This service keeps Laravel and domain identity records synchronized.
 */
final class ManageApplicationUsers
{
    /** @var array<string, string> */
    public const DOMAIN_ROLE_NAMES = [
        'Administrator' => 'ADMIN',
        'Engineer' => 'CALIBRATION_STAFF',
        'Viewer' => 'VIEWER',
    ];

    public function __construct(
        private readonly CalibrationHttpConnection $connectionResolver,
    ) {}

    /**
     * @param  array{name: string, username: string, email: string, role: string, password: string}  $data
     */
    public function create(array $data): int
    {
        $connection = $this->connectionResolver->resolve();

        return (int) $connection->transaction(function () use ($connection, $data): int {
            $this->assertRole($data['role']);
            $this->assertUnique($connection, $data['email'], $data['username']);

            $now = now();
            $userId = (int) $connection->table('users')->insertGetId([
                'name' => $data['name'],
                'email' => $data['email'],
                'email_verified_at' => null,
                'password' => Hash::make($data['password']),
                'remember_token' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $appUserId = (int) $connection->table('app_user')->insertGetId([
                'username' => $data['username'],
                'full_name' => $data['name'],
                'email' => $data['email'],
                'role_name' => self::DOMAIN_ROLE_NAMES[$data['role']],
                'is_active' => true,
                'created_at' => $now,
            ], 'app_user_id');

            $connection->table('user_app_user_map')->insert([
                'laravel_user_id' => $userId,
                'app_user_id' => $appUserId,
                'status' => 'active',
                'created_at' => $now,
                'inactivated_at' => null,
            ]);

            $this->assignRole($connection, $userId, ApplicationRoleName::from($data['role']));

            return $userId;
        });
    }

    /**
     * @param  array{name: string, username: string, email: string, role: string}  $data
     */
    public function update(int $userId, array $data, int $actorId): void
    {
        $connection = $this->connectionResolver->resolve();

        $connection->transaction(function () use ($connection, $userId, $data, $actorId): void {
            $this->assertRole($data['role']);
            $this->assertRoleChangeAllowed($connection, $userId, $data['role'], $actorId);
            $this->assertUnique($connection, $data['email'], $data['username'], $userId);

            $mapping = $connection->table('user_app_user_map')
                ->where('laravel_user_id', $userId)
                ->lockForUpdate()
                ->first(['app_user_id']);

            if ($mapping === null) {
                throw new RuntimeException('The user identity mapping is missing.');
            }

            $connection->table('users')->where('id', $userId)->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'updated_at' => now(),
            ]);

            $connection->table('app_user')->where('app_user_id', $mapping->app_user_id)->update([
                'username' => $data['username'],
                'full_name' => $data['name'],
                'email' => $data['email'],
                'role_name' => self::DOMAIN_ROLE_NAMES[$data['role']],
            ]);

            $this->assignRole($connection, $userId, ApplicationRoleName::from($data['role']));
        });
    }

    public function resetPassword(int $userId, string $password): void
    {
        $connection = $this->connectionResolver->resolve();

        $connection->transaction(function () use ($connection, $userId, $password): void {
            if (! $connection->table('users')->where('id', $userId)->exists()) {
                throw new RuntimeException('The selected user could not be found.');
            }

            $connection->table('users')->where('id', $userId)->update([
                'password' => Hash::make($password),
                'remember_token' => null,
                'updated_at' => now(),
            ]);
        });
    }

    public function setActive(int $userId, bool $active, int $actorId): void
    {
        $connection = $this->connectionResolver->resolve();

        $connection->transaction(function () use ($connection, $userId, $active, $actorId): void {
            if ($userId === $actorId && ! $active) {
                throw new RuntimeException('You cannot deactivate your own account.');
            }

            $mapping = $connection->table('user_app_user_map')
                ->where('laravel_user_id', $userId)
                ->lockForUpdate()
                ->first(['app_user_id']);

            if ($mapping === null) {
                throw new RuntimeException('The user identity mapping is missing.');
            }

            $identity = $connection->table('app_user')
                ->where('app_user_id', $mapping->app_user_id)
                ->lockForUpdate()
                ->first(['app_user_id']);

            if ($identity === null) {
                throw new RuntimeException('The user identity record is missing.');
            }

            $roleName = $connection->table('user_roles as assignments')
                ->join('roles', 'roles.id', '=', 'assignments.role_id')
                ->where('assignments.laravel_user_id', $userId)
                ->value('roles.name');

            if (! $active && $roleName === ApplicationRoleName::Administrator->value) {
                $this->assertNotLastActiveAdministrator($connection, $userId);
            }

            $now = now();
            $connection->table('app_user')->where('app_user_id', $mapping->app_user_id)->update([
                'is_active' => $active,
            ]);
            $connection->table('user_app_user_map')->where('laravel_user_id', $userId)->update([
                'status' => $active ? 'active' : 'inactive',
                'inactivated_at' => $active ? null : $now,
            ]);
        });
    }

    private function assertRole(string $role): void
    {
        if (! array_key_exists($role, self::DOMAIN_ROLE_NAMES)) {
            throw new RuntimeException('A valid application role is required.');
        }
    }

    private function assertUnique(Connection $connection, string $email, string $username, ?int $exceptUserId = null): void
    {
        $users = $connection->table('users')->whereRaw('lower(email) = lower(?)', [$email]);
        $domainEmail = $connection->table('app_user')->whereRaw('lower(email) = lower(?)', [$email]);
        $domainUsername = $connection->table('app_user')->whereRaw('lower(username) = lower(?)', [$username]);

        if ($exceptUserId !== null) {
            $users->where('id', '!=', $exceptUserId);
            $domainEmail->whereNotIn('app_user_id', $this->appUserIdsFor($connection, $exceptUserId));
            $domainUsername->whereNotIn('app_user_id', $this->appUserIdsFor($connection, $exceptUserId));
        }

        if ($users->exists() || $domainEmail->exists() || $domainUsername->exists()) {
            throw new RuntimeException('Email or username is already in use.');
        }
    }

    /** @return list<int> */
    private function appUserIdsFor(Connection $connection, int $userId): array
    {
        return $connection->table('user_app_user_map')
            ->where('laravel_user_id', $userId)
            ->pluck('app_user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function assertRoleChangeAllowed(Connection $connection, int $userId, string $role, int $actorId): void
    {
        $currentRole = $connection->table('user_roles as assignments')
            ->join('roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.laravel_user_id', $userId)
            ->value('roles.name');

        if ($userId === $actorId && $currentRole === ApplicationRoleName::Administrator->value && $role !== $currentRole) {
            throw new RuntimeException('You cannot demote your own Administrator account.');
        }

        if ($currentRole === ApplicationRoleName::Administrator->value
            && $role !== $currentRole) {
            $this->assertNotLastActiveAdministrator($connection, $userId);
        }
    }

    private function assertNotLastActiveAdministrator(Connection $connection, int $excludedUserId): void
    {
        $adminRoleId = $connection->table('roles')
            ->where('name', ApplicationRoleName::Administrator->value)
            ->value('id');

        $adminUserIds = $connection->table('user_roles')
            ->where('role_id', $adminRoleId)
            ->where('laravel_user_id', '!=', $excludedUserId)
            ->lockForUpdate()
            ->pluck('laravel_user_id')
            ->map(static fn (mixed $userId): int => (int) $userId)
            ->all();

        if ($adminUserIds === []) {
            throw new RuntimeException('At least one active Administrator must remain.');
        }

        $activeMappings = $connection->table('user_app_user_map')
            ->whereIn('laravel_user_id', $adminUserIds)
            ->where('status', 'active')
            ->whereNull('inactivated_at')
            ->lockForUpdate()
            ->get(['laravel_user_id', 'app_user_id']);

        $activeAppUserIds = $activeMappings
            ->pluck('app_user_id')
            ->map(static fn (mixed $appUserId): int => (int) $appUserId)
            ->all();

        $activeAdministrators = $connection->table('app_user')
            ->whereIn('app_user_id', $activeAppUserIds)
            ->where('is_active', true)
            ->lockForUpdate()
            ->get(['app_user_id'])
            ->count();

        if ($activeAdministrators < 1) {
            throw new RuntimeException('At least one active Administrator must remain.');
        }
    }

    private function assignRole(Connection $connection, int $userId, ApplicationRoleName $role): void
    {
        $roleId = $connection->table('roles')->where('name', $role->value)->value('id');

        if ($roleId === null) {
            throw new RuntimeException('The selected application role is not configured.');
        }

        $connection->table('user_roles')->where('laravel_user_id', $userId)->delete();
        $connection->table('user_roles')->insert([
            'laravel_user_id' => $userId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
