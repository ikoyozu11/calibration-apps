<?php

namespace App\CalibrationSessions;

use Illuminate\Database\Connection;
use Illuminate\Support\Collection;

/**
 * Resolves one Laravel users.id to an active app_user.app_user_id.
 *
 * The caller owns the transaction and passes an already chosen Connection.
 * This service does not call auth(), open a connection, or write either table.
 * It does not inspect role_name. Staging acquisition stays outside this
 * service and outside Pest. SQLite compiles lockForUpdate() without taking
 * a PostgreSQL row lock.
 */
class ResolveDomainActor
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    public function resolve(int $laravelUserId, Connection $connection): int
    {
        $connection = $this->sessionConnection->resolve($connection);

        if ($laravelUserId < 1) {
            throw ResolveDomainActorException::invalidLaravelUserId();
        }

        if ($connection->transactionLevel() < 1) {
            throw ResolveDomainActorException::missingActiveTransaction();
        }

        $mappings = $this->lockedMappings($connection, $laravelUserId);

        if ($mappings->isEmpty()) {
            throw ResolveDomainActorException::unmapped();
        }

        if ($mappings->count() > 1) {
            throw ResolveDomainActorException::duplicateMapping();
        }

        /** @var object{status: mixed, inactivated_at: mixed, app_user_id: mixed} $mapping */
        $mapping = $mappings->first();

        if ($mapping->status !== 'active') {
            throw ResolveDomainActorException::inactiveMapping();
        }

        if ($mapping->inactivated_at !== null) {
            throw ResolveDomainActorException::inconsistentInactivatedAt();
        }

        $appUser = $this->lockedAppUser($connection, $mapping->app_user_id);

        if ($appUser === null) {
            throw ResolveDomainActorException::missingAppUser();
        }

        if (! $this->appUserIsActive($appUser->is_active)) {
            throw ResolveDomainActorException::inactiveAppUser();
        }

        return $this->appUserId($appUser->app_user_id);
    }

    /**
     * @return Collection<int, object>
     */
    private function lockedMappings(Connection $connection, int $laravelUserId): Collection
    {
        return $connection->table('user_app_user_map')
            ->where('laravel_user_id', $laravelUserId)
            ->orderBy('user_app_user_map_id')
            ->lockForUpdate()
            ->limit(2)
            ->get();
    }

    private function lockedAppUser(Connection $connection, mixed $appUserId): ?object
    {
        $appUser = $connection->table('app_user')
            ->where('app_user_id', $appUserId)
            ->lockForUpdate()
            ->first();

        return is_object($appUser) ? $appUser : null;
    }

    private function appUserIsActive(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private function appUserId(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            return (int) $value;
        }

        throw ResolveDomainActorException::missingAppUser();
    }
}
