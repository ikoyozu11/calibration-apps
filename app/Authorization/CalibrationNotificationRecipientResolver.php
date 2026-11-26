<?php

namespace App\Authorization;

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;

final class CalibrationNotificationRecipientResolver
{
    public function __construct(
        private ResolveApplicationRole $roles,
        private ResolveActiveApplicationIdentity $identities,
    ) {}

    /**
     * Resolve recipients from trusted server-side account state.
     *
     * @return Collection<int, int>
     */
    public function eligibleUserIds(?Connection $connection = null): Collection
    {
        $query = $connection === null
            ? User::query()
            : User::on($connection->getName());

        return $query
            ->orderBy('id')
            ->get(['id'])
            ->filter(fn (User $user): bool => $this->isEligible($user, $connection))
            ->map(static fn (User $user): int => (int) $user->getKey())
            ->values();
    }

    public function isEligible(User $user, ?Connection $connection = null): bool
    {
        $userId = (int) $user->getKey();

        return $userId > 0
            && $this->identities->isActive($userId, $connection)
            && $this->roles->allows($userId, ApplicationCapability::InstrumentsView, $connection);
    }
}
