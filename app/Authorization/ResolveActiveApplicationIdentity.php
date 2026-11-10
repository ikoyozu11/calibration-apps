<?php

namespace App\Authorization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checks whether a Laravel user has an active domain identity mapping.
 *
 * Users without the optional domain mapping remain compatible with the
 * existing role-only authentication fixtures; mapped users must be active.
 */
final class ResolveActiveApplicationIdentity
{
    public function isActive(int $laravelUserId): bool
    {
        if (! $this->mappingSchemaAvailable()) {
            return true;
        }

        $identities = DB::table('user_app_user_map as mapping')
            ->join('app_user as identity', 'identity.app_user_id', '=', 'mapping.app_user_id')
            ->where('mapping.laravel_user_id', $laravelUserId)
            ->limit(2)
            ->get(['mapping.status', 'mapping.inactivated_at', 'identity.is_active']);

        if ($identities->isEmpty()) {
            return true;
        }

        if ($identities->count() !== 1) {
            return false;
        }

        $identity = $identities->first();

        return ($identity->status ?? null) === 'active'
            && $identity->inactivated_at === null
            && in_array($identity->is_active ?? null, [true, 1, '1'], true);
    }

    private function mappingSchemaAvailable(): bool
    {
        return Schema::hasTable('app_user')
            && Schema::hasTable('user_app_user_map')
            && Schema::hasColumn('app_user', 'app_user_id')
            && Schema::hasColumn('app_user', 'is_active')
            && Schema::hasColumn('user_app_user_map', 'laravel_user_id')
            && Schema::hasColumn('user_app_user_map', 'app_user_id')
            && Schema::hasColumn('user_app_user_map', 'status')
            && Schema::hasColumn('user_app_user_map', 'inactivated_at');
    }
}
