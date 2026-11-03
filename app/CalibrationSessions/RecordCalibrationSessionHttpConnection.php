<?php

namespace App\CalibrationSessions;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;

/**
 * Connection used by HTTP In Progress session-record actions.
 *
 * Offline tests keep SQLite :memory:. Layer 2 pgsql_staging access is
 * prepared in {@see CalibrationHttpConnection} and remains disabled.
 * Default pgsql is never opened.
 */
final class RecordCalibrationSessionHttpConnection
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
        private Repository $config,
    ) {}

    public function resolve(): Connection
    {
        try {
            return (new CalibrationHttpConnection($this->sessionConnection, $this->config))->resolve();
        } catch (CalibrationHttpConnectionException $exception) {
            throw RecordCalibrationSessionHttpException::writesUnavailable();
        }
    }
}
