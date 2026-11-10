<?php

namespace App\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AuthenticatedUserProfile
{
    /**
     * @return array{
     *     full_name: string,
     *     username: string,
     *     email: string,
     *     role: string,
     *     account_status: string,
     *     initials: string
     * }
     */
    public function resolve(User $user): array
    {
        $role = app(ResolveApplicationRole::class)->tryResolve((int) $user->getKey());
        $identity = $this->activeIdentity((int) $user->getKey());
        $fullName = $identity?->full_name ?: $user->name;
        $isActive = $identity !== null;

        return [
            'full_name' => $fullName,
            'username' => $identity?->username ?? 'Unavailable',
            'email' => $user->email,
            'role' => $role?->value ?? 'Unavailable',
            'account_status' => $isActive ? 'Active' : 'Identity unavailable',
            'initials' => $this->initials($fullName),
        ];
    }

    private function activeIdentity(int $laravelUserId): ?object
    {
        if (! Schema::hasTable('app_user')
            || ! Schema::hasTable('user_app_user_map')
            || ! Schema::hasColumn('app_user', 'app_user_id')
            || ! Schema::hasColumn('app_user', 'username')
            || ! Schema::hasColumn('app_user', 'full_name')
            || ! Schema::hasColumn('app_user', 'is_active')
            || ! Schema::hasColumn('user_app_user_map', 'user_app_user_map_id')
            || ! Schema::hasColumn('user_app_user_map', 'laravel_user_id')
            || ! Schema::hasColumn('user_app_user_map', 'app_user_id')
            || ! Schema::hasColumn('user_app_user_map', 'status')
            || ! Schema::hasColumn('user_app_user_map', 'inactivated_at')) {
            return null;
        }

        $identities = DB::table('user_app_user_map as mapping')
            ->join('app_user as identity', 'identity.app_user_id', '=', 'mapping.app_user_id')
            ->where('mapping.laravel_user_id', $laravelUserId)
            ->where('mapping.status', 'active')
            ->whereNull('mapping.inactivated_at')
            ->where('identity.is_active', true)
            ->orderBy('mapping.user_app_user_map_id')
            ->limit(2)
            ->get([
                'identity.username',
                'identity.full_name',
            ]);

        return $identities->count() === 1 ? $identities->first() : null;
    }

    private function initials(string $fullName): string
    {
        $initials = Str::initials($fullName, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : ($initials !== '' ? $initials : '?');
    }
}
