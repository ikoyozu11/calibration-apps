<?php

namespace Tests;

use Illuminate\Database\Connectors\ConnectionFactory;

/**
 * Test-only factory that refuses unsafe configs before Laravel creates a
 * Connection or a PostgreSQL connector/PDO.
 */
final class TestSafeConnectionFactory extends ConnectionFactory
{
    /**
     * @var list<array{name: ?string, driver: mixed}>
     */
    public static array $refusedMakes = [];

    public function make(array $config, $name = null)
    {
        $connectionName = is_string($name) && $name !== '' ? $name : null;

        try {
            TestDatabaseGuard::assertConfigIsSafe($config, $connectionName);
        } catch (UnsafeTestDatabaseException $exception) {
            self::$refusedMakes[] = [
                'name' => $connectionName,
                'driver' => $config['driver'] ?? null,
            ];

            throw $exception;
        }

        return parent::make($config, $name);
    }

    public function createConnector(array $config)
    {
        TestDatabaseGuard::assertConfigIsSafe($config);

        return parent::createConnector($config);
    }
}
