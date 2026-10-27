<?php

namespace App\Authorization;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Explicit auth-database connection for staging Administrator bootstrap.
 *
 * Production always uses the named connection "pgsql_staging" (literal database
 * name calibration_staging_db in config). Tests rebind this class to point at
 * the guarded SQLite default connection — never open PostgreSQL in Pest.
 */
final class StagingAuthConnection
{
    public const CONNECTION_NAME = 'pgsql_staging';

    public function __construct(
        private readonly string $connectionName = self::CONNECTION_NAME,
    ) {}

    public function connection(): Connection
    {
        if ($this->connectionName === '') {
            throw new RuntimeException('Staging auth bootstrap requires an explicit connection name.');
        }

        return DB::connection($this->connectionName);
    }

    public function name(): string
    {
        return $this->connectionName;
    }
}
