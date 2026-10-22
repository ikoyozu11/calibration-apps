<?php

namespace Tests;

use Illuminate\Database\Connectors\ConnectorInterface;
use PDO;

/**
 * Bound as db.connector.pgsql during tests. Instantiating a PostgreSQL PDO
 * is a test failure, not a connection attempt.
 */
final class TestRefusingPostgresConnector implements ConnectorInterface
{
    public static int $connectCalls = 0;

    public function connect(array $config): PDO
    {
        self::$connectCalls++;

        throw new UnsafeTestDatabaseException(
            'PostgreSQL PDO connector must not be used during automated tests.'
        );
    }
}
