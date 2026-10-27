<?php

namespace App\Authorization;

use App\Models\Role;
use App\Models\UserRole;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Trusted server-side reader for Laravel authentication identity roles.
 *
 * Connection boundary: reads the default Laravel auth database when
 * $connection is null. When an explicit Connection is supplied, every role
 * read uses that Connection only — no hidden fallback to the default.
 *
 * Never queries calibration domain tables (app_user, user_app_user_map,
 * calibration_*). Callers pass laravel users.id explicitly so services can
 * authorize without coupling to auth() or browser-supplied role strings.
 */
final class ResolveApplicationRole
{
    public function __construct(
        private readonly ApplicationCapabilityMap $capabilities = new ApplicationCapabilityMap,
    ) {}

    /**
     * @throws ApplicationRoleException
     */
    public function resolve(int $laravelUserId, ?Connection $connection = null): ApplicationRoleName
    {
        try {
            $assignments = $connection === null
                ? $this->assignmentsOnDefault($laravelUserId)
                : $this->assignmentsOnConnection($connection, $laravelUserId);
        } catch (ApplicationRoleException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ApplicationRoleException::storageFailure($laravelUserId, $exception->getMessage());
        }

        if ($assignments->isEmpty()) {
            throw ApplicationRoleException::missing($laravelUserId);
        }

        if ($assignments->count() > 1) {
            throw ApplicationRoleException::ambiguous($laravelUserId);
        }

        $roleName = $assignments->first()?->name;

        if (! is_string($roleName) || $roleName === '') {
            throw ApplicationRoleException::invalid($laravelUserId, '(empty)');
        }

        $resolved = ApplicationRoleName::tryFrom($roleName);

        if ($resolved === null) {
            throw ApplicationRoleException::invalid($laravelUserId, $roleName);
        }

        return $resolved;
    }

    public function tryResolve(int $laravelUserId, ?Connection $connection = null): ?ApplicationRoleName
    {
        try {
            return $this->resolve($laravelUserId, $connection);
        } catch (ApplicationRoleException) {
            return null;
        }
    }

    public function allows(int $laravelUserId, ApplicationCapability $capability, ?Connection $connection = null): bool
    {
        $role = $this->tryResolve($laravelUserId, $connection);

        if ($role === null) {
            return false;
        }

        return $this->capabilities->allows($role, $capability);
    }

    /**
     * Seed the three supported role rows when missing (idempotent).
     */
    public static function ensureRoleCatalog(): void
    {
        foreach (ApplicationRoleName::cases() as $role) {
            Role::query()->firstOrCreate(
                ['name' => $role->value],
                ['name' => $role->value],
            );
        }
    }

    /**
     * @return Collection<int, object{name: mixed}>
     */
    private function assignmentsOnDefault(int $laravelUserId): Collection
    {
        return UserRole::query()
            ->where('laravel_user_id', $laravelUserId)
            ->with('role:id,name')
            ->get()
            ->map(static fn (UserRole $assignment): object => (object) [
                'name' => $assignment->role?->name,
            ]);
    }

    /**
     * @return Collection<int, object{name: mixed}>
     */
    private function assignmentsOnConnection(Connection $connection, int $laravelUserId): Collection
    {
        $name = $connection->getName();

        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException('Explicit role resolution requires a named database connection.');
        }

        return $connection->table('user_roles as user_roles')
            ->join('roles as roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.laravel_user_id', $laravelUserId)
            ->orderBy('user_roles.id')
            ->get(['roles.name']);
    }
}
