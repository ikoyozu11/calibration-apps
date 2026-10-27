<?php

namespace App\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Trusted writer that assigns exactly one application role per Laravel user.
 *
 * This primitive does not authorize the caller. HTTP/admin entry points must
 * Gate::authorize(ApplicationCapability::UsersManage) before invoking it.
 * Do not expose a public assignment endpoint.
 *
 * Connection boundary: default Laravel auth database when $connection is null.
 * When an explicit Connection is supplied (bootstrap), every read/write uses
 * that Connection only — no hidden fallback to the default connection.
 */
class AssignApplicationRole
{
    /**
     * Replace any existing membership with exactly one role.
     */
    public function assign(int $laravelUserId, ApplicationRoleName $role, ?Connection $connection = null): void
    {
        if ($connection !== null) {
            $this->assignOnConnection($laravelUserId, $role, $connection);

            return;
        }

        if (! User::query()->whereKey($laravelUserId)->exists()) {
            throw new InvalidArgumentException("Laravel user [{$laravelUserId}] does not exist.");
        }

        $roleId = Role::query()->where('name', $role->value)->value('id');

        if ($roleId === null) {
            throw new InvalidArgumentException("Application role [{$role->value}] is not seeded.");
        }

        DB::transaction(function () use ($laravelUserId, $roleId): void {
            UserRole::query()->where('laravel_user_id', $laravelUserId)->delete();

            UserRole::query()->create([
                'laravel_user_id' => $laravelUserId,
                'role_id' => $roleId,
            ]);
        });
    }

    private function assignOnConnection(int $laravelUserId, ApplicationRoleName $role, Connection $connection): void
    {
        $this->assertNamedConnection($connection);

        if (! $connection->table('users')->where('id', $laravelUserId)->exists()) {
            throw new InvalidArgumentException("Laravel user [{$laravelUserId}] does not exist.");
        }

        $roleId = $connection->table('roles')->where('name', $role->value)->value('id');

        if ($roleId === null) {
            throw new InvalidArgumentException("Application role [{$role->value}] is not seeded.");
        }

        $connection->transaction(function () use ($connection, $laravelUserId, $roleId): void {
            $connection->table('user_roles')->where('laravel_user_id', $laravelUserId)->delete();

            $now = now();

            $connection->table('user_roles')->insert([
                'laravel_user_id' => $laravelUserId,
                'role_id' => $roleId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function assertNamedConnection(Connection $connection): void
    {
        $name = $connection->getName();

        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException('Explicit role assignment requires a named database connection.');
        }
    }
}
