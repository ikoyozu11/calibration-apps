<?php

namespace App\CalibrationDueNotifications;

use App\CalibrationSessions\CalibrationSessionConnection;
use Illuminate\Database\Connection;

final class CalibrationNotificationConnection
{
    private ?Connection $isolatedTestConnection = null;

    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    public function resolve(?Connection $connection = null): Connection
    {
        if ($connection !== null) {
            return $this->sessionConnection->resolve($connection);
        }

        if ($this->isolatedTestConnection !== null) {
            return $this->sessionConnection->resolve($this->isolatedTestConnection);
        }

        return $this->sessionConnection->resolveNamed(
            CalibrationSessionConnection::STAGING_CONNECTION,
        );
    }

    /**
     * Test-only explicit SQLite injection. The connection is still validated
     * by CalibrationSessionConnection before it can be used.
     */
    public function forIsolatedTest(Connection $connection): self
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Isolated notification connection injection is testing-only.');
        }

        $instance = clone $this;
        $instance->isolatedTestConnection = $connection;

        return $instance;
    }
}
