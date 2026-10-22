<?php

namespace Tests;

use Illuminate\Database\DatabaseManager;
use UnitEnum;

use function Illuminate\Support\enum_value;

/**
 * Test-only manager that inspects the current config before cache, reconnect,
 * connectUsing, or extension resolvers can reach a PostgreSQL connector.
 */
final class TestSafeDatabaseManager extends DatabaseManager
{
    public function connection($name = null)
    {
        TestDatabaseGuard::assertNamedConnectionMayBeResolved(
            $this->app['config'],
            $this->resolvedConnectionName($name),
        );

        return parent::connection($name);
    }

    public function connectUsing(UnitEnum|string $name, array $config, bool $force = false)
    {
        TestDatabaseGuard::assertConfigIsSafe($config, enum_value($name));

        return parent::connectUsing($name, $config, $force);
    }

    public function reconnect($name = null)
    {
        TestDatabaseGuard::assertNamedConnectionMayBeResolved(
            $this->app['config'],
            $this->resolvedConnectionName($name),
        );

        return parent::reconnect($name);
    }

    public function usingConnection($name, callable $callback)
    {
        TestDatabaseGuard::assertNamedConnectionMayBeResolved(
            $this->app['config'],
            enum_value($name),
        );

        return parent::usingConnection($name, $callback);
    }

    public function extend($name, callable $resolver)
    {
        parent::extend($name, function (array $config, $connectionName) use ($name, $resolver) {
            $resolvedName = is_string($connectionName) && $connectionName !== '' ? $connectionName : $name;

            TestDatabaseGuard::assertConfigIsSafe($config, is_string($resolvedName) ? $resolvedName : null);

            return $resolver($config, $connectionName);
        });
    }

    public function setDefaultConnection($name)
    {
        TestDatabaseGuard::assertNamedConnectionMayBeResolved(
            $this->app['config'],
            is_string($name) ? $name : $this->resolvedConnectionName($name),
        );

        parent::setDefaultConnection($name);
    }

    private function resolvedConnectionName(UnitEnum|string|null $name): string
    {
        $resolved = enum_value($name) ?: $this->getDefaultConnection();
        [$database] = $this->parseConnectionName($resolved);

        return is_string($database) ? $database : '';
    }
}
