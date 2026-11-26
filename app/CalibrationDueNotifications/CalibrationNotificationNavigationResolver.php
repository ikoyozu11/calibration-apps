<?php

namespace App\CalibrationDueNotifications;

use App\Authorization\ApplicationCapability;
use App\Authorization\ResolveActiveApplicationIdentity;
use App\Authorization\ResolveApplicationRole;
use App\CalibrationSessions\CalibrationSessionConnection;
use App\Models\CalibrationNotification;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\User;
use Illuminate\Database\Connection;

final class CalibrationNotificationNavigationResolver
{
    public function __construct(
        private ResolveApplicationRole $roles,
        private ResolveActiveApplicationIdentity $identities,
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    public function resolve(
        CalibrationNotification $notification,
        User $user,
        Connection $connection,
    ): ?string {
        $this->sessionConnection->assertSafe($connection);
        $userId = (int) $user->getKey();

        if ($userId < 1
            || ! $this->identities->isActive($userId, $connection)
            || ! $this->roles->allows($userId, ApplicationCapability::InstrumentsView, $connection)) {
            return null;
        }

        $instrumentId = $notification->instrument_id;

        if ($instrumentId === null) {
            return null;
        }

        $instrument = Instrument::on($connection->getName())->find($instrumentId);

        if (! $instrument instanceof Instrument) {
            return null;
        }

        $instrumentTypeId = $notification->instrument_type_id;

        if ($instrumentTypeId === null) {
            return route('instruments.show', ['instrument' => $instrument->getKey()]);
        }

        $instrumentType = InstrumentType::on($connection->getName())->find($instrumentTypeId);

        if (! $instrumentType instanceof InstrumentType
            || (int) $instrumentType->instrument_id !== (int) $instrument->getKey()) {
            return null;
        }

        return route('instruments.types.show', [
            'instrument' => $instrument->getKey(),
            'instrumentType' => $instrumentType->getKey(),
        ]);
    }
}
