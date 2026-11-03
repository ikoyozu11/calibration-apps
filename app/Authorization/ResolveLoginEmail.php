<?php

namespace App\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves a login identifier to a Laravel users.email for Auth::attempt.
 *
 * Email identifiers authenticate against users directly. Username identifiers
 * resolve only through an active app_user and an active user_app_user_map.
 */
final class ResolveLoginEmail
{
    public function email(string $identifier): ?string
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return $identifier;
        }

        return $this->emailForUsername($identifier);
    }

    private function emailForUsername(string $username): ?string
    {
        if (! Schema::hasTable('app_user') || ! Schema::hasTable('user_app_user_map')) {
            return null;
        }

        $appUser = DB::table('app_user')
            ->whereRaw('lower(username) = ?', [mb_strtolower($username)])
            ->limit(2)
            ->get(['app_user_id', 'is_active']);

        if ($appUser->count() !== 1) {
            return null;
        }

        $row = $appUser->first();

        if (! $this->isActive($row->is_active ?? null)) {
            return null;
        }

        $appUserId = (int) $row->app_user_id;

        $map = DB::table('user_app_user_map')
            ->where('app_user_id', $appUserId)
            ->where('status', 'active')
            ->whereNull('inactivated_at')
            ->first(['laravel_user_id']);

        if ($map === null) {
            return null;
        }

        $email = User::query()->whereKey((int) $map->laravel_user_id)->value('email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    private function isActive(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
