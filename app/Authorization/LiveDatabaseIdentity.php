<?php

namespace App\Authorization;

use Illuminate\Database\Connection;

/**
 * Production live-identity reader for the Administrator bootstrap command.
 *
 * Only PostgreSQL current_database() counts as proven identity. Configured
 * DB_DATABASE / connection names are ignored here so a mis-pointed .env cannot
 * satisfy the staging allowlist by itself.
 */
final class LiveDatabaseIdentity implements ResolvesLiveDatabaseIdentity
{
    public function identity(Connection $connection): string
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return '';
        }

        $name = $connection->scalar('select current_database()');

        return is_string($name) ? $name : '';
    }
}
