<?php

namespace App\StagingRuntime;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Config-only staging browser runtime gate.
 *
 * Validates resolved Laravel configuration without opening a database
 * connection. Active only when the staging launcher enables isolation.
 */
final class StagingRuntimeIsolation
{
    public const REQUIRED_CONNECTION = 'pgsql';

    public const REQUIRED_DATABASE = 'calibration_staging_db';

    public const FORBIDDEN_PRODUCTION_DATABASE = 'calibration_db';

    public const FORBIDDEN_MAINTENANCE_DATABASE = 'postgres';

    public const REQUIRED_HOST = '127.0.0.1';

    public const REQUIRED_PORT = 5432;

    public const ENV_FLAG = 'STAGING_RUNTIME_ISOLATION';

    /**
     * @return list<string>
     */
    public function violations(Repository $config, bool $configurationIsCached, string $configCachePath): array
    {
        $violations = [];

        if ($configurationIsCached || is_file($configCachePath)) {
            $violations[] = 'Cached config is present; staging runtime refuses to launch with bootstrap/cache/config.php.';
        }

        if ($config->get('database.default') !== self::REQUIRED_CONNECTION) {
            $violations[] = 'Default database connection must be '.self::REQUIRED_CONNECTION.'.';
        }

        $pgsql = $config->get('database.connections.pgsql');
        if (! is_array($pgsql)) {
            $violations[] = 'pgsql connection configuration is missing.';

            return $violations;
        }

        $database = $pgsql['database'] ?? null;
        if ($database === self::FORBIDDEN_PRODUCTION_DATABASE) {
            $violations[] = 'Refusing production database '.self::FORBIDDEN_PRODUCTION_DATABASE.'.';
        } elseif ($database === self::FORBIDDEN_MAINTENANCE_DATABASE) {
            $violations[] = 'Refusing maintenance database '.self::FORBIDDEN_MAINTENANCE_DATABASE.'.';
        } elseif ($database !== self::REQUIRED_DATABASE) {
            $violations[] = 'Default pgsql database must be '.self::REQUIRED_DATABASE.'.';
        }

        $host = $pgsql['host'] ?? null;
        if ($host !== self::REQUIRED_HOST) {
            $violations[] = 'Default pgsql host must be '.self::REQUIRED_HOST.'.';
        }

        $port = $pgsql['port'] ?? null;
        if ((int) $port !== self::REQUIRED_PORT) {
            $violations[] = 'Default pgsql port must be '.self::REQUIRED_PORT.'.';
        }

        $url = $pgsql['url'] ?? null;
        if (! $this->isBlank($url)) {
            $violations[] = 'Default pgsql url override must be absent.';
        }

        if (! $this->isBlank(getenv('DB_URL') === false ? null : getenv('DB_URL'))) {
            $violations[] = 'Process DB_URL must be empty for staging runtime.';
        }

        if (! $this->isBlank(getenv('DATABASE_URL') === false ? null : getenv('DATABASE_URL'))) {
            $violations[] = 'Process DATABASE_URL must be empty for staging runtime.';
        }

        $sessionDriver = $config->get('session.driver');
        if ($sessionDriver !== 'file') {
            $violations[] = 'Session driver must be file (refusing database-backed sessions).';
        }

        $cacheStore = $config->get('cache.default');
        if (! in_array($cacheStore, ['file', 'array'], true)) {
            $violations[] = 'Cache store must be file or array (refusing database-backed cache).';
        }

        $queue = $config->get('queue.default');
        if ($queue !== 'sync') {
            $violations[] = 'Queue connection must be sync (refusing database-backed queue).';
        }

        return $violations;
    }

    /**
     * @throws RuntimeException
     */
    public function assertSafe(Repository $config, bool $configurationIsCached, string $configCachePath): void
    {
        $violations = $this->violations($config, $configurationIsCached, $configCachePath);

        if ($violations === []) {
            return;
        }

        throw new RuntimeException(
            'Staging runtime isolation refused unsafe configuration: '.implode(' ', $violations),
        );
    }

    public function enabled(Repository $config): bool
    {
        return (bool) $config->get('database.staging_runtime_isolation', false);
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === false || $value === '';
    }
}
