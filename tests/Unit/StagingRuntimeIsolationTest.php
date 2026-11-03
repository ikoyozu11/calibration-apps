<?php

/**
 * Offline config-only staging runtime isolation tests.
 * Does not open PostgreSQL. Mutates a dedicated Config repository where possible.
 */

use App\Http\Middleware\EnsureStagingRuntimeIsolation;
use App\StagingRuntime\StagingRuntimeIsolation;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\TestRefusingPostgresConnector;

uses(TestCase::class);

/**
 * @return array<string, mixed>
 */
function stagingIsolationConfigArray(array $overrides = []): array
{
    $base = [
        'database' => [
            'staging_runtime_isolation' => true,
            'default' => StagingRuntimeIsolation::REQUIRED_CONNECTION,
            'connections' => [
                'pgsql' => [
                    'driver' => 'pgsql',
                    'database' => StagingRuntimeIsolation::REQUIRED_DATABASE,
                    'host' => StagingRuntimeIsolation::REQUIRED_HOST,
                    'port' => StagingRuntimeIsolation::REQUIRED_PORT,
                    'url' => null,
                    'username' => 'unused',
                    'password' => 'unused-secret',
                ],
            ],
        ],
        'session' => [
            'driver' => 'file',
        ],
        'cache' => [
            'default' => 'file',
        ],
        'queue' => [
            'default' => 'sync',
        ],
    ];

    return array_replace_recursive($base, $overrides);
}

function stagingIsolationRepository(array $overrides = []): Repository
{
    return new Repository(stagingIsolationConfigArray($overrides));
}

function stagingIsolationMissingCachePath(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'staging-missing-config-cache-'.uniqid('', true).'.php';
}

beforeEach(function (): void {
    putenv('DB_URL=');
    putenv('DATABASE_URL=');
    $_ENV['DB_URL'] = '';
    $_ENV['DATABASE_URL'] = '';
    $_SERVER['DB_URL'] = '';
    $_SERVER['DATABASE_URL'] = '';
});

afterEach(function (): void {
    Config::set('database.staging_runtime_isolation', false);
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.pgsql.database', ':memory:');
    Config::set('database.connections.pgsql.url', '');
    Config::set('session.driver', 'array');
    Config::set('cache.default', 'array');
    Config::set('queue.default', 'sync');
});

it('accepts a fully isolated staging configuration without connecting to PostgreSQL', function () {
    $isolation = new StagingRuntimeIsolation;

    expect($isolation->violations(stagingIsolationRepository(), false, stagingIsolationMissingCachePath()))->toBe([])
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('refuses when configuration cache is present', function () {
    $isolation = new StagingRuntimeIsolation;
    $path = stagingIsolationMissingCachePath();
    file_put_contents($path, '<?php return [];');

    try {
        expect($isolation->violations(stagingIsolationRepository(), false, $path))
            ->toContain('Cached config is present; staging runtime refuses to launch with bootstrap/cache/config.php.');
    } finally {
        @unlink($path);
    }
});

it('refuses an unsafe default connection name', function () {
    $config = stagingIsolationRepository([
        'database' => ['default' => 'sqlite'],
    ]);

    expect((new StagingRuntimeIsolation)->violations($config, false, stagingIsolationMissingCachePath()))
        ->toContain('Default database connection must be pgsql.');
});

it('refuses the wrong database name and production database', function () {
    $isolation = new StagingRuntimeIsolation;

    expect($isolation->violations(stagingIsolationRepository([
        'database' => ['connections' => ['pgsql' => ['database' => 'other_db']]],
    ]), false, stagingIsolationMissingCachePath()))
        ->toContain('Default pgsql database must be calibration_staging_db.');

    expect($isolation->violations(stagingIsolationRepository([
        'database' => ['connections' => ['pgsql' => ['database' => StagingRuntimeIsolation::FORBIDDEN_PRODUCTION_DATABASE]]],
    ]), false, stagingIsolationMissingCachePath()))
        ->toContain('Refusing production database calibration_db.');
});

it('refuses the wrong host and port', function () {
    $isolation = new StagingRuntimeIsolation;

    expect($isolation->violations(stagingIsolationRepository([
        'database' => ['connections' => ['pgsql' => ['host' => '10.0.0.1']]],
    ]), false, stagingIsolationMissingCachePath()))
        ->toContain('Default pgsql host must be 127.0.0.1.');

    expect($isolation->violations(stagingIsolationRepository([
        'database' => ['connections' => ['pgsql' => ['port' => 5433]]],
    ]), false, stagingIsolationMissingCachePath()))
        ->toContain('Default pgsql port must be 5432.');
});

it('refuses database session, cache, and queue drivers', function () {
    $isolation = new StagingRuntimeIsolation;

    expect($isolation->violations(stagingIsolationRepository([
        'session' => ['driver' => 'database'],
    ]), false, stagingIsolationMissingCachePath()))
        ->toContain('Session driver must be file (refusing database-backed sessions).');

    expect($isolation->violations(stagingIsolationRepository([
        'cache' => ['default' => 'database'],
    ]), false, stagingIsolationMissingCachePath()))
        ->toContain('Cache store must be file or array (refusing database-backed cache).');

    expect($isolation->violations(stagingIsolationRepository([
        'queue' => ['default' => 'database'],
    ]), false, stagingIsolationMissingCachePath()))
        ->toContain('Queue connection must be sync (refusing database-backed queue).');
});

it('allows array cache as a safe non-database store', function () {
    expect((new StagingRuntimeIsolation)->violations(stagingIsolationRepository([
        'cache' => ['default' => 'array'],
    ]), false, stagingIsolationMissingCachePath()))->toBe([]);
});

it('is inactive when the staging isolation flag is off', function () {
    Config::set('database.staging_runtime_isolation', false);
    Config::set('session.driver', 'database');

    $response = app(EnsureStagingRuntimeIsolation::class)->handle(
        Request::create('/login', 'GET'),
        fn () => response('ok', 200),
    );

    expect($response->getContent())->toBe('ok')
        ->and($response->getStatusCode())->toBe(200)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('aborts with 503 when isolation is enabled and config is unsafe', function () {
    Config::set('database.staging_runtime_isolation', true);
    Config::set('session.driver', 'database');

    try {
        app(EnsureStagingRuntimeIsolation::class)->handle(
            Request::create('/login', 'GET'),
            fn () => response('ok', 200),
        );
        expect(false)->toBeTrue();
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(503)
            ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
    }
});

it('passes when isolation is enabled and app config is made staging-safe without PostgreSQL', function () {
    Config::set('database.staging_runtime_isolation', true);
    Config::set('database.default', StagingRuntimeIsolation::REQUIRED_CONNECTION);
    Config::set('database.connections.pgsql.database', StagingRuntimeIsolation::REQUIRED_DATABASE);
    Config::set('database.connections.pgsql.host', StagingRuntimeIsolation::REQUIRED_HOST);
    Config::set('database.connections.pgsql.port', StagingRuntimeIsolation::REQUIRED_PORT);
    Config::set('database.connections.pgsql.url', null);
    Config::set('session.driver', 'file');
    Config::set('cache.default', 'file');
    Config::set('queue.default', 'sync');

    $response = app(EnsureStagingRuntimeIsolation::class)->handle(
        Request::create('/login', 'GET'),
        fn () => response('ok', 200),
    );

    expect($response->getContent())->toBe('ok')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('keeps EnsureStagingRuntimeIsolation ahead of StartSession and Authenticate in bootstrap priority', function () {
    $source = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php');

    expect($source)->toContain('EnsureStagingRuntimeIsolation::class')
        ->and($source)->toContain('$middleware->prepend(EnsureStagingRuntimeIsolation::class)');

    preg_match('/\$middleware->priority\(\[(.*?)\]\);/s', $source, $matches);
    expect($matches[1] ?? null)->toBeString();

    $priority = $matches[1];
    $isolationPos = strpos($priority, 'EnsureStagingRuntimeIsolation::class');
    $sessionPos = strpos($priority, 'StartSession::class');
    $authPos = strpos($priority, 'Authenticate::class');

    expect($isolationPos)->toBeInt()
        ->and($sessionPos)->toBeInt()
        ->and($authPos)->toBeInt()
        ->and($isolationPos)->toBeLessThan($sessionPos)
        ->and($isolationPos)->toBeLessThan($authPos);
});

it('always refuses a production database name from configuration metadata', function () {
    $isolation = new StagingRuntimeIsolation;
    $config = stagingIsolationRepository([
        'database' => [
            'staging_runtime_isolation' => false,
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => [
                    'driver' => 'pgsql',
                    'database' => StagingRuntimeIsolation::FORBIDDEN_PRODUCTION_DATABASE,
                    'url' => null,
                ],
            ],
        ],
    ]);

    expect($isolation->productionTargetViolations($config))
        ->toContain('Refusing production database calibration_db.')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('refuses production even when staging isolation is off', function () {
    Config::set('database.staging_runtime_isolation', false);
    Config::set('database.default', 'pgsql');
    Config::set('database.connections.pgsql.database', StagingRuntimeIsolation::FORBIDDEN_PRODUCTION_DATABASE);
    Config::set('database.connections.pgsql.url', null);
    Config::set('session.driver', 'database');

    try {
        app(EnsureStagingRuntimeIsolation::class)->handle(
            Request::create('/login', 'GET'),
            fn () => response('ok', 200),
        );
        expect(false)->toBeTrue();
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(503)
            ->and($exception->getMessage())->toContain('calibration_db')
            ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
    }
});

it('refuses a postgresql url that points at production', function () {
    $config = stagingIsolationRepository([
        'database' => [
            'staging_runtime_isolation' => false,
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => [
                    'driver' => 'pgsql',
                    'database' => 'laravel',
                    'url' => 'pgsql://user:secret@127.0.0.1:5432/calibration_db',
                ],
            ],
        ],
    ]);

    expect((new StagingRuntimeIsolation)->productionTargetViolations($config))
        ->toContain('Refusing production database calibration_db.');
});

it('fails closed when a postgresql connection name cannot be resolved', function () {
    $config = stagingIsolationRepository([
        'database' => [
            'staging_runtime_isolation' => false,
            'default' => 'pgsql',
            'connections' => [
                'pgsql' => [
                    'driver' => 'pgsql',
                    'database' => null,
                    'url' => null,
                ],
            ],
        ],
    ]);

    expect((new StagingRuntimeIsolation)->productionTargetViolations($config))
        ->toContain('Database connection configuration is ambiguous.');
});

it('accepts sqlite memory without treating it as production', function () {
    $config = new Repository([
        'database' => [
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                    'url' => null,
                ],
            ],
        ],
    ]);

    expect((new StagingRuntimeIsolation)->productionTargetViolations($config))->toBe([]);

    $response = app(EnsureStagingRuntimeIsolation::class)->handle(
        Request::create('/login', 'GET'),
        fn () => response('ok', 200),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('does not leak credentials in isolation refusal messages', function () {
    $message = implode(' ', (new StagingRuntimeIsolation)->violations(stagingIsolationRepository([
        'session' => ['driver' => 'database'],
    ]), false, stagingIsolationMissingCachePath()));

    expect($message)->not->toContain('unused-secret')
        ->and($message)->not->toMatch('/password\s*=/i');
});
