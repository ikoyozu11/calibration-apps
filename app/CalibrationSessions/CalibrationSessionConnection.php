<?php

namespace App\CalibrationSessions;

use App\Database\DestructiveCommandGuard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Fail-closed connection ownership for calibration-session workflow services.
 *
 * Inspects configured connection metadata before opening PostgreSQL.
 * A Connection already created by the caller cannot be uncreated; entry
 * points must use resolve() or resolveNamed() instead of DB::connection()
 * for acquisition.
 */
final class CalibrationSessionConnection
{
    public const PRODUCTION_DATABASE = 'calibration_db';

    public const STAGING_DATABASE = 'calibration_staging_db';

    /**
     * Sole PostgreSQL connection name authorized for session workflows.
     */
    public const STAGING_CONNECTION = 'pgsql_staging';

    public function __construct(
        private DatabaseManager $db,
        private Repository $config,
    ) {}

    public function resolve(?Connection $connection): Connection
    {
        if ($connection !== null) {
            $this->assertSafe($connection);

            return $connection;
        }

        $this->assertDefaultMayBeOpened();

        $opened = $this->db->connection();
        $this->assertSafe($opened);

        return $opened;
    }

    /**
     * Acquire a named connection after fail-closed metadata validation.
     *
     * PostgreSQL is limited to {@see self::STAGING_CONNECTION}. Named SQLite
     * :memory: connections are allowed for automated tests. Never falls back
     * to the default connection.
     */
    public function resolveNamed(string $name): Connection
    {
        $name = trim($name);

        if ($name === '') {
            throw CalibrationSessionConnectionException::ambiguous();
        }

        $config = $this->config->get('database.connections.'.$name);

        if (! is_array($config)) {
            throw CalibrationSessionConnectionException::ambiguous();
        }

        $this->assertNamedConfigurationMayBeOpened($name, $config);

        $opened = $this->db->connection($name);
        $this->assertSafe($opened);

        return $opened;
    }

    public function assertSafe(Connection $connection): void
    {
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            if ($this->isSqliteMemory($connection)) {
                return;
            }

            throw CalibrationSessionConnectionException::ambiguous();
        }

        if ($driver !== 'pgsql') {
            throw CalibrationSessionConnectionException::ambiguous();
        }

        $configured = $this->configuredDatabaseName($connection);

        if ($configured === null || $configured === '') {
            throw CalibrationSessionConnectionException::ambiguous();
        }

        if ($configured === self::PRODUCTION_DATABASE) {
            throw CalibrationSessionConnectionException::production();
        }

        if ($configured !== self::STAGING_DATABASE) {
            throw CalibrationSessionConnectionException::stagingRequired();
        }

        $live = $connection->scalar('select current_database()');

        if ($live === self::PRODUCTION_DATABASE) {
            throw CalibrationSessionConnectionException::production();
        }

        if ($live !== self::STAGING_DATABASE) {
            throw CalibrationSessionConnectionException::identityMismatch();
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function assertNamedConfigurationMayBeOpened(string $name, array $config): void
    {
        if (array_key_exists('read', $config) || array_key_exists('write', $config)) {
            throw CalibrationSessionConnectionException::ambiguous();
        }

        $driver = $config['driver'] ?? null;

        if ($driver === 'sqlite') {
            $this->assertNamedSqliteMemoryConfiguration($config);

            return;
        }

        if ($name === 'pgsql') {
            $this->refuseNamedPostgresqlConfiguration($config);

            throw CalibrationSessionConnectionException::unsafeDefault();
        }

        if ($name !== self::STAGING_CONNECTION) {
            $this->refuseNamedPostgresqlConfiguration($config);

            throw CalibrationSessionConnectionException::ambiguous();
        }

        if ($driver !== 'pgsql') {
            throw CalibrationSessionConnectionException::ambiguous();
        }

        if (! $this->isBlankUrl($config['url'] ?? null)) {
            $urlDatabase = DestructiveCommandGuard::databaseNameFromUrl($config['url']);

            if ($urlDatabase === self::PRODUCTION_DATABASE) {
                throw CalibrationSessionConnectionException::production();
            }

            throw CalibrationSessionConnectionException::ambiguous();
        }

        $database = DestructiveCommandGuard::databaseNameFromConnection($config);

        if ($database === self::PRODUCTION_DATABASE) {
            throw CalibrationSessionConnectionException::production();
        }

        if ($database !== self::STAGING_DATABASE) {
            throw CalibrationSessionConnectionException::stagingRequired();
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function assertNamedSqliteMemoryConfiguration(array $config): void
    {
        if (! DestructiveCommandGuard::connectionIsSqliteMemory($config)) {
            throw CalibrationSessionConnectionException::ambiguous();
        }

        if (! $this->isBlankUrl($config['url'] ?? null)) {
            throw CalibrationSessionConnectionException::ambiguous();
        }
    }

    /**
     * Reject production targets discovered on a non-allowlisted PostgreSQL name
     * before the generic refusal for that name.
     *
     * @param  array<string, mixed>  $config
     */
    private function refuseNamedPostgresqlConfiguration(array $config): void
    {
        $driver = $config['driver'] ?? null;

        if ($driver !== 'pgsql') {
            return;
        }

        $database = DestructiveCommandGuard::databaseNameFromConnection($config);

        if ($database === self::PRODUCTION_DATABASE) {
            throw CalibrationSessionConnectionException::production();
        }

        if (! $this->isBlankUrl($config['url'] ?? null)) {
            $urlDatabase = DestructiveCommandGuard::databaseNameFromUrl($config['url']);

            if ($urlDatabase === self::PRODUCTION_DATABASE) {
                throw CalibrationSessionConnectionException::production();
            }
        }
    }

    private function assertDefaultMayBeOpened(): void
    {
        $name = $this->config->get('database.default');

        if (! is_string($name) || $name === '') {
            throw CalibrationSessionConnectionException::ambiguous();
        }

        $connection = $this->config->get('database.connections.'.$name);

        if (! is_array($connection)) {
            throw CalibrationSessionConnectionException::ambiguous();
        }

        if (DestructiveCommandGuard::connectionIsSqliteMemory($connection)) {
            return;
        }

        $database = DestructiveCommandGuard::databaseNameFromConnection($connection);

        if ($database === self::PRODUCTION_DATABASE) {
            throw CalibrationSessionConnectionException::production();
        }

        throw CalibrationSessionConnectionException::unsafeDefault();
    }

    private function isSqliteMemory(Connection $connection): bool
    {
        $name = $connection->getName();

        if (is_string($name) && $name !== '') {
            $config = $this->config->get('database.connections.'.$name);

            if (is_array($config) && DestructiveCommandGuard::connectionIsSqliteMemory($config)) {
                return true;
            }
        }

        return $connection->getDatabaseName() === ':memory:';
    }

    private function configuredDatabaseName(Connection $connection): ?string
    {
        $name = $connection->getName();

        if (is_string($name) && $name !== '') {
            $config = $this->config->get('database.connections.'.$name);

            if (is_array($config)) {
                $fromConfig = DestructiveCommandGuard::databaseNameFromConnection($config);

                if ($fromConfig !== null) {
                    return $fromConfig;
                }
            }
        }

        $database = $connection->getDatabaseName();

        return is_string($database) && $database !== '' ? $database : null;
    }

    private function isBlankUrl(mixed $url): bool
    {
        return $url === null || $url === false || $url === '';
    }
}
