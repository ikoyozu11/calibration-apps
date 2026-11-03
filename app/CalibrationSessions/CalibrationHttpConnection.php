<?php

namespace App\CalibrationSessions;

use App\Database\DestructiveCommandGuard;
use App\StagingRuntime\StagingRuntimeIsolation;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;

/**
 * Shared HTTP connection selection for calibration UI.
 *
 * Layer 2 staging HTTP access uses the named pgsql_staging connection only.
 * SQLite :memory: is resolved first so Pest keeps working after activation.
 * Default pgsql is never used. Staging requires isolation ON plus the named
 * pgsql_staging connection.
 */
final class CalibrationHttpConnection
{
    /**
     * Layer 2 switch. Not read from the environment.
     */
    public const STAGING_HTTP_ACTIVATION = true;

    /**
     * @var list<string>
     */
    private const DEFAULT_POSTGRES_NAMES = ['pgsql', 'pgsql_staging'];

    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
        private Repository $config,
    ) {}

    public function stagingHttpEnabled(): bool
    {
        return self::STAGING_HTTP_ACTIVATION;
    }

    public function resolve(): Connection
    {
        if ($this->sqliteMemoryAvailable()) {
            return $this->resolveSqliteMemory();
        }

        if ($this->stagingHttpEnabled()) {
            return $this->resolveAuthorizedStaging();
        }

        throw CalibrationHttpConnectionException::unavailable();
    }

    private function sqliteMemoryAvailable(): bool
    {
        return DestructiveCommandGuard::defaultConnectionIsSqliteMemory($this->config);
    }

    private function resolveSqliteMemory(): Connection
    {
        $default = $this->config->get('database.default');

        if (! is_string($default) || $default === '') {
            throw CalibrationHttpConnectionException::unavailable();
        }

        if (in_array($default, self::DEFAULT_POSTGRES_NAMES, true)) {
            throw CalibrationHttpConnectionException::unavailable();
        }

        $connection = $this->config->get('database.connections.'.$default);

        if (! is_array($connection) || ($connection['driver'] ?? null) === 'pgsql') {
            throw CalibrationHttpConnectionException::unavailable();
        }

        if (! DestructiveCommandGuard::connectionIsSqliteMemory($connection)) {
            throw CalibrationHttpConnectionException::unavailable();
        }

        return $this->sessionConnection->resolve(null);
    }

    /**
     * Named pgsql_staging only, after isolation is ON.
     * Never opens the default pgsql connection.
     */
    private function resolveAuthorizedStaging(): Connection
    {
        $isolation = app(StagingRuntimeIsolation::class);

        if (! $isolation->enabled($this->config)) {
            throw CalibrationHttpConnectionException::unavailable();
        }

        $isolation->assertSafe(
            $this->config,
            app()->configurationIsCached(),
            app()->getCachedConfigPath(),
        );

        return $this->sessionConnection->resolveNamed(CalibrationSessionConnection::STAGING_CONNECTION);
    }
}
