<?php

namespace Tests;

use App\Database\DestructiveCommandGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Input\InputInterface;

final class TestDatabaseGuard
{
    /**
     * CommandStarting inspections recorded for the current application.
     *
     * @var list<array{command: string, database: ?string}>
     */
    public static array $inspectedCommands = [];

    /**
     * @var list<string>
     */
    public const POSTGRES_DRIVERS = [
        'pgsql',
        'postgres',
        'postgresql',
    ];

    /**
     * @var list<string>
     */
    public const POSTGRES_CONNECTION_NAMES = [
        'pgsql',
        'pgsql_staging',
    ];

    /**
     * Artisan commands that can mutate or inspect a named database.
     *
     * @var list<string>
     */
    public const DATABASE_MUTATING_COMMANDS = [
        'migrate',
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
        'migrate:install',
        'db:wipe',
        'db:seed',
        'schema:dump',
        'schema:load',
    ];

    public static function ensureProcessEnvironment(): void
    {
        $connection = getenv('DB_CONNECTION');
        $database = getenv('DB_DATABASE');
        $url = getenv('DB_URL');

        self::abort(self::environmentViolations(
            $connection === false ? null : $connection,
            $database === false ? null : $database,
            $url,
        ));
    }

    public static function ensure(Repository $config): void
    {
        self::abort(self::configViolations($config));
    }

    /**
     * Replace Laravel's database manager and factory so PostgreSQL cannot be
     * resolved after bootstrap, including post-bootstrap default retargeting.
     */
    public static function protectApplication(Application $app): void
    {
        if ($app->bound('tests.database_guard.installed')) {
            return;
        }

        TestSafeConnectionFactory::$refusedMakes = [];
        TestRefusingPostgresConnector::$connectCalls = 0;
        self::$inspectedCommands = [];

        $factory = new TestSafeConnectionFactory($app);
        $manager = new TestSafeDatabaseManager($app, $factory);

        $app->forgetInstance('migrator');
        $app->forgetInstance('migration.repository');
        $app->instance('db.factory', $factory);
        $app->instance('db', $manager);
        $app->alias('db', ConnectionResolverInterface::class);
        $app->instance('db.connector.pgsql', new TestRefusingPostgresConnector);
        $app->bind('db.connection', fn (Application $container) => $container['db']->connection());
        $app->bind('db.schema', fn (Application $container) => $container['db']->connection()->getSchemaBuilder());

        Facade::clearResolvedInstance('db');
        Facade::clearResolvedInstance('db.connection');
        Facade::clearResolvedInstance('db.schema');

        Model::setConnectionResolver($manager);

        $kernel = $app->make(ConsoleKernel::class);

        if (method_exists($kernel, 'rerouteSymfonyCommandEvents')) {
            $kernel->rerouteSymfonyCommandEvents();
        }

        if (method_exists($kernel, 'setArtisan')) {
            $kernel->setArtisan(null);
        }

        $app['events']->listen(
            CommandStarting::class,
            function (CommandStarting $event) use ($app): void {
                self::assertCommandMayRun($app->make('config'), $event->command, $event->input);
            },
        );

        $app->instance('tests.database_guard.installed', true);
    }

    /**
     * After the process environment is proven safe, copy it into the readers
     * Laravel uses. `php artisan test` boots an immutable Env repository before
     * PHPUnit can force sqlite, so without this reset createApplication() would
     * still resolve PostgreSQL.
     */
    public static function synchronizeLaravelEnvironment(): void
    {
        foreach (['APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'DB_URL'] as $name) {
            $value = getenv($name);

            if ($value === false) {
                continue;
            }

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        Env::enablePutenv();
    }

    /**
     * @return list<string>
     */
    public static function environmentViolations(?string $connection, mixed $database, mixed $url): array
    {
        $violations = [];

        if ($connection !== 'sqlite') {
            $violations[] = 'DB_CONNECTION must be sqlite, got '.self::describe($connection);
        }

        if ($database !== ':memory:') {
            $violations[] = 'DB_DATABASE must be :memory:, got '.self::describe($database);
        }

        if (! self::isBlankUrl($url)) {
            $violations[] = 'DB_URL must be empty';
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    public static function configViolations(Repository $config): array
    {
        $default = $config->get('database.default');
        $connection = $config->get('database.connections.'.(is_string($default) ? $default : ''), []);

        if (! is_array($connection)) {
            $connection = [];
        }

        $violations = [];

        if ($default !== 'sqlite') {
            $violations[] = 'database.default must be sqlite, got '.self::describe($default);
        }

        $driver = $connection['driver'] ?? null;
        if ($driver !== 'sqlite') {
            $violations[] = 'default connection driver must be sqlite, got '.self::describe($driver);
        }

        $database = $connection['database'] ?? null;
        if ($database !== ':memory:') {
            $violations[] = 'SQLite database must be :memory:, got '.self::describe($database);
        }

        if (! self::isBlankUrl($connection['url'] ?? null)) {
            $violations[] = 'database connection URL must be empty';
        }

        return $violations;
    }

    public static function assertNamedConnectionMayBeResolved(Repository $config, string $name): void
    {
        [$name] = explode('::', $name, 2);

        if ($name === '') {
            self::abort(['A database connection name is required.']);
        }

        if (in_array($name, self::POSTGRES_CONNECTION_NAMES, true)) {
            self::abort(["Refusing PostgreSQL connection [{$name}] during tests."]);
        }

        $connection = $config->get('database.connections.'.$name);

        if (! is_array($connection)) {
            self::abort(["Database connection [{$name}] is not configured for tests."]);
        }

        self::assertConfigIsSafe($connection, $name);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function assertConfigIsSafe(array $config, ?string $name = null): void
    {
        self::abort(self::connectionSafetyViolations($config, $name));
    }

    public static function assertCommandMayRun(Repository $config, string $command, InputInterface $input): void
    {
        $explicit = self::commandDatabaseOption($input);
        $isMutating = in_array($command, self::DATABASE_MUTATING_COMMANDS, true);

        if (! $isMutating && $explicit === null) {
            return;
        }

        $name = $explicit ?? $config->get('database.default');
        $resolvedName = is_string($name) && $name !== '' ? $name : null;

        self::$inspectedCommands[] = [
            'command' => $command,
            'database' => $resolvedName,
        ];

        if ($resolvedName === null) {
            self::abort(["Artisan [{$command}] is missing a safe test database connection."]);
        }

        self::assertNamedConnectionMayBeResolved($config, $resolvedName);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function connectionSafetyViolations(array $config, ?string $name = null): array
    {
        $label = $name ?: 'connection';
        $violations = [];

        if (is_string($name) && in_array($name, self::POSTGRES_CONNECTION_NAMES, true)) {
            $violations[] = "Refusing PostgreSQL connection [{$name}] during tests.";
        }

        if (! self::isBlankUrl($config['url'] ?? null)) {
            $urlDatabase = DestructiveCommandGuard::databaseNameFromUrl($config['url']);

            if (in_array($urlDatabase, DestructiveCommandGuard::PROTECTED_DATABASE_NAMES, true)) {
                $violations[] = "Refusing protected database [{$urlDatabase}] supplied by a connection URL.";
            }
        }

        $parsed = (new ConfigurationUrlParser)->parseConfiguration($config);
        $violations = array_merge($violations, self::targetViolations($parsed, $label));

        foreach (['read', 'write'] as $role) {
            if (! isset($config[$role]) || ! is_array($config[$role])) {
                continue;
            }

            $roleConfig = $config[$role][0] ?? $config[$role];

            if (! is_array($roleConfig)) {
                $violations[] = "Refusing opaque {$role} database override on [{$label}] during tests.";

                continue;
            }

            $violations = array_merge(
                $violations,
                self::targetViolations(array_merge($parsed, $roleConfig), $label.'.'.$role),
            );
        }

        return array_values(array_unique($violations));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function targetViolations(array $config, string $label): array
    {
        $violations = [];
        $driver = $config['driver'] ?? null;
        $database = DestructiveCommandGuard::databaseNameFromConnection($config);

        if (in_array($driver, self::POSTGRES_DRIVERS, true)) {
            $violations[] = "Refusing PostgreSQL driver [{$label}] during tests.";
        }

        if (in_array($database, DestructiveCommandGuard::PROTECTED_DATABASE_NAMES, true)) {
            $violations[] = "Refusing protected database [{$database}] during tests.";
        }

        if ($driver !== 'sqlite') {
            if ($violations === []) {
                $violations[] = 'Refusing non-sqlite driver '.self::describe($driver).' during tests.';
            }

            return $violations;
        }

        if ($database !== ':memory:') {
            $violations[] = 'SQLite tests must use :memory:, got '.self::describe($database);
        }

        return $violations;
    }

    private static function commandDatabaseOption(InputInterface $input): ?string
    {
        if ($input->hasParameterOption('--database', true)) {
            $value = $input->getParameterOption('--database', null, true);

            return is_string($value) && $value !== '' ? $value : null;
        }

        if (! $input->hasOption('database')) {
            return null;
        }

        try {
            $value = $input->getOption('database');
        } catch (\Exception) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  list<string>  $violations
     */
    private static function abort(array $violations): void
    {
        if ($violations === []) {
            return;
        }

        throw new UnsafeTestDatabaseException(
            "Unsafe test database configuration. Automated tests must use SQLite :memory: with an empty DB_URL.\n"
            .implode("\n", $violations)."\n"
            .'Refusing to continue before RefreshDatabase or migrations can run.'
        );
    }

    private static function isBlankUrl(mixed $url): bool
    {
        return $url === null || $url === false || $url === '';
    }

    private static function describe(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if ($value === false) {
            return 'unset';
        }

        if (! is_string($value)) {
            return get_debug_type($value);
        }

        return $value === '' ? '(empty string)' : $value;
    }
}
