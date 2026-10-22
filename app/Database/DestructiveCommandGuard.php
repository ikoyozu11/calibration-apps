<?php

namespace App\Database;

use Illuminate\Contracts\Config\Repository;

final class DestructiveCommandGuard
{
    /**
     * @var list<string>
     */
    public const PROTECTED_DATABASE_NAMES = [
        'calibration_db',
        'calibration_staging_db',
    ];

    /**
     * Fail closed for production and staging PostgreSQL names regardless of APP_ENV.
     * SQLite :memory: remains writable so Pest/RefreshDatabase can run.
     */
    public static function shouldProhibit(?string $driver, mixed $database, bool $isProduction): bool
    {
        if ($driver === 'sqlite' && $database === ':memory:') {
            return false;
        }

        if ($isProduction) {
            return true;
        }

        $name = self::normalizedDatabaseName($database);

        if ($name === null) {
            return true;
        }

        return in_array($name, self::PROTECTED_DATABASE_NAMES, true);
    }

    /**
     * Laravel's DB::prohibitDestructiveCommands() is process-global for
     * migrate:fresh, migrate:refresh, migrate:reset, migrate:rollback, and
     * db:wipe. It is not per-connection. Scanning every configured connection
     * prevents a protected PostgreSQL name on a non-default connection from
     * being wiped while the default connection is sqlite.
     *
     * Pest RefreshDatabase issues migrate:fresh on sqlite :memory:. Because
     * prohibition is process-global, a named pgsql_staging target in config
     * would otherwise block the suite. The testing + sqlite :memory: default
     * exemption restores RefreshDatabase without opening PostgreSQL.
     * Production and non-testing environments still fail closed.
     */
    public static function shouldProhibitConfiguredConnections(
        Repository $config,
        bool $isProduction,
        bool $isTesting = false,
    ): bool {
        if ($isProduction) {
            return true;
        }

        if ($isTesting && self::defaultConnectionIsSqliteMemory($config)) {
            return false;
        }

        $connections = $config->get('database.connections');

        if (! is_array($connections) || $connections === []) {
            return true;
        }

        foreach ($connections as $connection) {
            if (! is_array($connection)) {
                return true;
            }

            if (self::connectionIsSqliteMemory($connection)) {
                continue;
            }

            $name = self::databaseNameFromConnection($connection);

            if ($name === null) {
                return true;
            }

            if (in_array($name, self::PROTECTED_DATABASE_NAMES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    public static function databaseNameFromConnection(array $connection): ?string
    {
        $name = self::normalizedDatabaseName($connection['database'] ?? null);

        if ($name !== null) {
            return $name;
        }

        return self::databaseNameFromUrl($connection['url'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    public static function connectionIsSqliteMemory(array $connection): bool
    {
        return ($connection['driver'] ?? null) === 'sqlite'
            && ($connection['database'] ?? null) === ':memory:';
    }

    public static function defaultConnectionIsSqliteMemory(Repository $config): bool
    {
        $name = $config->get('database.default');

        if (! is_string($name) || $name === '') {
            return false;
        }

        $connection = $config->get('database.connections.'.$name);

        return is_array($connection) && self::connectionIsSqliteMemory($connection);
    }

    public static function databaseNameFromUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '' || $path === '/') {
            return null;
        }

        return self::normalizedDatabaseName(ltrim($path, '/'));
    }

    public static function normalizedDatabaseName(mixed $database): ?string
    {
        if (! is_string($database) || $database === '') {
            return null;
        }

        return $database;
    }
}
