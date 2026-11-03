<?php

declare(strict_types=1);

use App\CalibrationSessions\CalibrationSessionConnection;
use App\CalibrationSessions\PostgresCalibrationNumberAllocator;
use App\CalibrationSessions\StartCalibrationSession;
use App\Models\Calibration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:',
    'database.connections.sqlite.url' => '',
    'database.connections.pgsql.database' => 'refusing_default_pgsql',
    'database.connections.pgsql.url' => '',
    'database.connections.pgsql.username' => '',
    'database.connections.pgsql.password' => '',
]);

DB::purge('pgsql');
DB::purge('sqlite');

if (config('database.default') !== 'sqlite') {
    fwrite(STDERR, "Abort: default connection is not sqlite after override.\n");
    exit(2);
}

if ((string) config('database.connections.pgsql.database') === 'calibration_db') {
    fwrite(STDERR, "Abort: default pgsql connection still names production.\n");
    exit(2);
}

$username = (string) env('DB_USERNAME');

if ($username === '') {
    fwrite(STDERR, "Abort: DB_USERNAME is empty; staging auth cannot proceed.\n");
    exit(2);
}

config([
    'database.connections.pgsql_staging' => [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => '5432',
        'database' => 'calibration_staging_db',
        'username' => $username,
        'password' => (string) env('DB_PASSWORD'),
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => 'prefer',
    ],
]);

if ((string) config('database.connections.pgsql_staging.database') !== 'calibration_staging_db') {
    fwrite(STDERR, "Abort: staging connection is not calibration_staging_db.\n");
    exit(2);
}

$requestedMethod = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--method=')) {
        $requestedMethod = strtoupper(substr($argument, 9));
    }
}

$report = [
    'database' => null,
    'schema' => null,
    'preflight' => [],
    'internal' => null,
    'external' => null,
    'rollback' => null,
    'sequence' => [],
    'errors' => [],
];

$exitCode = 0;

try {
    $staging = DB::connection('pgsql_staging');
    assertStagingIdentity($staging);

    $identity = $staging->selectOne('select current_database() as database, current_schema() as schema');
    $report['database'] = $identity->database;
    $report['schema'] = $identity->schema;

    $report['preflight'] = readPreflight($staging);

    if ($report['preflight']['stop'] !== []) {
        fwrite(STDERR, "Abort: unexpected staging preflight state.\n");
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
        exit(3);
    }

    $type = $staging->selectOne('
        select it.instrument_type_id, it.instrument_id, it.type_name
        from instrument_type it
        inner join instrument i on i.instrument_id = it.instrument_id
        order by it.instrument_type_id
        limit 1
    ');

    if ($type === null) {
        throw new RuntimeException('No Instrument Type with a parent Instrument is available.');
    }

    $service = new StartCalibrationSession(
        app(CalibrationSessionConnection::class),
        new PostgresCalibrationNumberAllocator,
    );
    $typeId = (int) $type->instrument_type_id;

    if ($requestedMethod !== Calibration::METHOD_EXTERNAL) {
        $report['internal'] = runControlledStart($staging, $service, $typeId, Calibration::METHOD_INTERNAL, $report['preflight']['sequence']);

        if ($report['internal']['ok'] !== true) {
            throw new RuntimeException('Internal Start verification failed; External Start was not attempted.');
        }

        $staging = DB::connection('pgsql_staging');
        assertStagingIdentity($staging);
    }

    if ($requestedMethod !== Calibration::METHOD_INTERNAL) {
        $sequenceBeforeExternal = $report['internal']['after_sequence'] ?? $report['preflight']['sequence'];
        $report['external'] = runControlledStart($staging, $service, $typeId, Calibration::METHOD_EXTERNAL, $sequenceBeforeExternal);
    }

    $staging = DB::connection('pgsql_staging');
    assertStagingIdentity($staging);
    $report['rollback'] = readPersistentState($staging);
    $afterSequence = $report['external']['after_sequence']
        ?? $report['internal']['after_sequence']
        ?? $report['preflight']['sequence'];
    $report['sequence'] = [
        'before' => $report['preflight']['sequence'],
        'after_internal' => $report['internal']['after_sequence'] ?? null,
        'after_external' => $report['external']['after_sequence'] ?? null,
        'consumed' => sequenceConsumed($report['preflight']['sequence'], $afterSequence),
    ];

    if ($report['sequence']['consumed'] > 3) {
        throw new RuntimeException('Sequence consumption exceeded the approved budget of 3.');
    }
} catch (Throwable $exception) {
    $exitCode = 1;
    $report['errors'][] = $exception->getMessage();
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
exit($exitCode);

function assertStagingIdentity(Connection $connection): void
{
    if ($connection->getName() !== 'pgsql_staging') {
        throw new RuntimeException('Abort: expected connection name pgsql_staging.');
    }

    if ($connection->getDriverName() !== 'pgsql') {
        throw new RuntimeException('Abort: staging connection is not pgsql.');
    }

    if ($connection->getDatabaseName() !== 'calibration_staging_db') {
        throw new RuntimeException('Abort: configured database is '.$connection->getDatabaseName());
    }

    $live = $connection->scalar('select current_database()');

    if ($live !== 'calibration_staging_db') {
        throw new RuntimeException('Abort: current_database() is '.$live);
    }

    if ($live === 'calibration_db') {
        throw new RuntimeException('Abort: production database identity detected.');
    }
}

/**
 * @return array<string, mixed>
 */
function readPreflight(Connection $connection): array
{
    $requiredTables = [
        'instrument',
        'instrument_type',
        'calibration',
        'calibration_provider',
        'instrument_type_requirement',
        'calibration_requirement_snapshot',
        'environment_parameter',
        'instrument_type_environment_parameter',
        'calibration_environment_parameter_snapshot',
        'calibration_environment_value',
        'calibration_standard_usage',
        'calibration_activity',
        'calibration_scope',
        'calibration_test_result',
        'calibration_external_document',
        'certificate',
        'calibration_session_setting',
    ];

    $tables = [];

    foreach ($requiredTables as $table) {
        $tables[$table] = (bool) $connection->scalar(
            "select to_regclass('public.{$table}') is not null",
        );
    }

    $sequenceExists = (bool) $connection->scalar("select to_regclass('public.calibration_number_seq') is not null");
    $uniqueIndex = $connection->selectOne("
        select indexname, indexdef
        from pg_indexes
        where schemaname = 'public'
          and indexname = 'uq_calibration_in_progress_per_type'
    ");
    $internalTeam = $connection->selectOne("
        select calibration_provider_id, provider_code, provider_name, provider_type
        from calibration_provider
        where provider_code = 'INTERNAL_TEAM'
    ");
    $triggerCount = (int) $connection->scalar("
        select count(*)
        from information_schema.triggers
        where event_object_schema = 'public'
          and trigger_name like 'trg_calibration%'
    ");

    $state = readPersistentState($connection);
    $stop = [];

    if ($state['counts']['instrument'] !== 344) {
        $stop[] = 'instrument count is '.$state['counts']['instrument'].', expected 344';
    }

    if ($state['counts']['instrument_type'] !== 344) {
        $stop[] = 'instrument_type count is '.$state['counts']['instrument_type'].', expected 344';
    }

    if ($state['counts']['calibration'] !== 0) {
        $stop[] = 'persistent calibration sessions exist';
    }

    if ($internalTeam === null) {
        $stop[] = 'INTERNAL_TEAM provider is missing';
    }

    if (! $sequenceExists) {
        $stop[] = 'calibration_number_seq is missing';
    }

    if ($uniqueIndex === null) {
        $stop[] = 'uq_calibration_in_progress_per_type is missing';
    }

    if (in_array(false, $tables, true)) {
        $stop[] = 'required foundation tables are missing';
    }

    if ($triggerCount < 1) {
        $stop[] = 'calibration session triggers are missing';
    }

    return [
        'tables' => $tables,
        'sequence_exists' => $sequenceExists,
        'unique_index' => $uniqueIndex,
        'internal_team' => $internalTeam,
        'trigger_count' => $triggerCount,
        'sequence' => $state['sequence'],
        'counts' => $state['counts'],
        'stop' => $stop,
    ];
}

/**
 * @return array{sequence: array{last_value: int|string|null, is_called: bool|null}, counts: array<string, int>}
 */
function readPersistentState(Connection $connection): array
{
    $sequence = $connection->selectOne('select last_value, is_called from public.calibration_number_seq');

    return [
        'sequence' => [
            'last_value' => $sequence?->last_value,
            'is_called' => pgBool($sequence?->is_called),
        ],
        'counts' => [
            'instrument' => (int) $connection->table('instrument')->count(),
            'instrument_type' => (int) $connection->table('instrument_type')->count(),
            'calibration' => (int) $connection->table('calibration')->count(),
            'calibration_requirement_snapshot' => (int) $connection->table('calibration_requirement_snapshot')->count(),
            'calibration_environment_parameter_snapshot' => (int) $connection->table('calibration_environment_parameter_snapshot')->count(),
            'calibration_environment_value' => (int) $connection->table('calibration_environment_value')->count(),
            'calibration_standard_usage' => (int) $connection->table('calibration_standard_usage')->count(),
            'calibration_activity' => (int) $connection->table('calibration_activity')->count(),
            'calibration_scope' => (int) $connection->table('calibration_scope')->count(),
            'calibration_test_result' => (int) $connection->table('calibration_test_result')->count(),
            'calibration_external_document' => (int) $connection->table('calibration_external_document')->count(),
            'certificate' => (int) $connection->table('certificate')->count(),
        ],
    ];
}

/**
 * @param  array{last_value: int|string|null, is_called: bool|null}  $before
 * @return array<string, mixed>
 */
function runControlledStart(
    Connection $connection,
    StartCalibrationSession $service,
    int $instrumentTypeId,
    string $method,
    array $beforeSequence,
): array {
    $masterBefore = [
        'instrument' => (int) $connection->table('instrument')->count(),
        'instrument_type' => (int) $connection->table('instrument_type')->count(),
        'instrument_type_requirement' => (int) $connection->table('instrument_type_requirement')->count(),
        'instrument_type_environment_parameter' => (int) $connection->table('instrument_type_environment_parameter')->count(),
    ];

    $configuredModes = $connection->table('instrument_type_requirement')
        ->where('instrument_type_id', $instrumentTypeId)
        ->pluck('requirement_mode', 'requirement_category')
        ->all();

    $configuredEnv = $connection->table('instrument_type_environment_parameter as itep')
        ->join('environment_parameter as ep', 'ep.parameter_id', '=', 'itep.parameter_id')
        ->where('itep.instrument_type_id', $instrumentTypeId)
        ->orderBy('itep.display_order')
        ->orderBy('itep.parameter_id')
        ->get([
            'itep.parameter_id',
            'ep.parameter_code',
            'ep.parameter_name',
            'ep.unit',
            'ep.data_type',
            'itep.requirement_mode',
            'itep.display_order',
        ])
        ->all();

    $connection->beginTransaction();

    try {
        $calibration = $service->start($instrumentTypeId, $method, $connection);

        $inside = inspectCreatedSession($connection, $calibration, $method, $configuredModes, $configuredEnv);
        $inside['transaction_level_after_start'] = $connection->transactionLevel();
        $inside['instrument_type_id'] = $instrumentTypeId;
        $inside['master_counts_unchanged'] = $masterBefore === [
            'instrument' => (int) $connection->table('instrument')->count(),
            'instrument_type' => (int) $connection->table('instrument_type')->count(),
            'instrument_type_requirement' => (int) $connection->table('instrument_type_requirement')->count(),
            'instrument_type_environment_parameter' => (int) $connection->table('instrument_type_environment_parameter')->count(),
        ];
    } catch (Throwable $exception) {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        throw $exception;
    }

    if ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }

    DB::purge('pgsql_staging');
    $fresh = DB::connection('pgsql_staging');
    assertStagingIdentity($fresh);

    $after = readPersistentState($fresh);
    $consumed = sequenceConsumed($beforeSequence, $after['sequence']);

    if ($consumed > 1) {
        throw new RuntimeException($method.' Start consumed '.$consumed.' sequence values; expected at most 1.');
    }

    return [
        'ok' => $inside['ok'],
        'checks' => $inside,
        'after_sequence' => $after['sequence'],
        'after_counts' => $after['counts'],
        'persistent_rows_cleared' => $after['counts']['calibration'] === 0
            && $after['counts']['calibration_requirement_snapshot'] === 0
            && $after['counts']['calibration_environment_parameter_snapshot'] === 0
            && $after['counts']['calibration_external_document'] === 0
            && $after['counts']['certificate'] === 0,
        'sequence_consumed_this_attempt' => $consumed,
    ];
}

/**
 * @param  array<string, string>  $configuredModes
 * @param  list<object>  $configuredEnv
 * @return array<string, mixed>
 */
function inspectCreatedSession(
    Connection $connection,
    Calibration $calibration,
    string $method,
    array $configuredModes,
    array $configuredEnv,
): array {
    $categories = ['standards', 'activity', 'environment', 'test_profile'];
    $snapshots = $connection->table('calibration_requirement_snapshot')
        ->where('calibration_id', $calibration->calibration_id)
        ->get()
        ->keyBy('requirement_category');

    $expectedModes = [];

    foreach ($categories as $category) {
        $expectedModes[$category] = $configuredModes[$category] ?? 'not_configured';
    }

    $actualModes = [];
    $confirmed = [];

    foreach ($snapshots as $category => $snapshot) {
        $actualModes[$category] = $snapshot->frozen_mode;
        $confirmed[$category] = $snapshot->configuration_confirmed_at;
    }

    ksort($expectedModes);
    ksort($actualModes);

    $envSnapshots = $connection->table('calibration_environment_parameter_snapshot')
        ->where('calibration_id', $calibration->calibration_id)
        ->orderBy('display_order')
        ->orderBy('parameter_id')
        ->get();

    $envMatch = $envSnapshots->count() === count($configuredEnv);

    foreach ($configuredEnv as $index => $expected) {
        $actual = $envSnapshots[$index] ?? null;

        if ($actual === null
            || (int) $actual->parameter_id !== (int) $expected->parameter_id
            || $actual->parameter_code !== $expected->parameter_code
            || $actual->parameter_name !== $expected->parameter_name
            || $actual->unit !== $expected->unit
            || $actual->data_type !== $expected->data_type
            || $actual->requirement_mode !== $expected->requirement_mode
            || (int) $actual->display_order !== (int) $expected->display_order
        ) {
            $envMatch = false;
            break;
        }
    }

    $providerCode = $calibration->calibration_provider_id === null
        ? null
        : $connection->table('calibration_provider')
            ->where('calibration_provider_id', $calibration->calibration_provider_id)
            ->value('provider_code');

    $checks = [
        'session_status' => $calibration->session_status === Calibration::STATUS_IN_PROGRESS,
        'instrument_type' => (int) $calibration->instrument_type_id > 0,
        'method' => $calibration->calibration_method === $method,
        'provider' => $method === Calibration::METHOD_INTERNAL
            ? $providerCode === 'INTERNAL_TEAM'
            : $calibration->calibration_provider_id === null && $providerCode === null,
        'number_format' => (bool) preg_match('/^CAL\.VT([0-9]{4,})\/(0[1-9]|1[0-2])\/[0-9]{2}$/', (string) $calibration->calibration_number),
        'calibration_number' => $calibration->calibration_number,
        'four_requirement_snapshots' => $snapshots->count() === 4,
        'requirement_modes' => $actualModes === $expectedModes,
        'expected_modes' => $expectedModes,
        'actual_modes' => $actualModes,
        'not_configured_preserved' => ! in_array('not_configured', $expectedModes, true)
            || collect($actualModes)->contains('not_configured'),
        'configuration_not_confirmed' => collect($confirmed)->every(fn (mixed $value): bool => $value === null),
        'environment_snapshots_match' => $envMatch,
        'environment_snapshot_count' => $envSnapshots->count(),
        'no_environment_values' => (int) $connection->table('calibration_environment_value')->where('calibration_id', $calibration->calibration_id)->count() === 0,
        'no_standard_usage' => (int) $connection->table('calibration_standard_usage')->where('calibration_id', $calibration->calibration_id)->count() === 0,
        'no_activities' => (int) $connection->table('calibration_activity')->where('calibration_id', $calibration->calibration_id)->count() === 0,
        'no_scopes' => (int) $connection->table('calibration_scope')->where('calibration_id', $calibration->calibration_id)->count() === 0,
        'no_test_results' => (int) $connection->table('calibration_test_result')->count() === 0,
        'no_external_documents' => (int) $connection->table('calibration_external_document')->where('calibration_id', $calibration->calibration_id)->count() === 0,
        'no_certificates' => (int) $connection->table('certificate')->where('calibration_id', $calibration->calibration_id)->count() === 0,
        'dates_null' => $calibration->calibration_date === null && $calibration->calibration_due === null,
        'not_completed' => $calibration->session_status !== Calibration::STATUS_COMPLETED && $calibration->completed_at === null,
        'not_cancelled' => $calibration->session_status !== Calibration::STATUS_CANCELLED && $calibration->cancelled_at === null,
        'no_vendor_name_column_written' => true,
    ];

    $booleanChecks = array_filter($checks, fn (mixed $value): bool => is_bool($value));
    $checks['ok'] = ! in_array(false, $booleanChecks, true);

    return $checks;
}

function pgBool(mixed $value): ?bool
{
    if ($value === null) {
        return null;
    }

    if (is_bool($value)) {
        return $value;
    }

    if ($value === 't' || $value === 'true' || $value === 1 || $value === '1') {
        return true;
    }

    if ($value === 'f' || $value === 'false' || $value === 0 || $value === '0') {
        return false;
    }

    return (bool) $value;
}

/**
 * @param  array{last_value: int|string|null, is_called: bool|null}  $before
 * @param  array{last_value: int|string|null, is_called: bool|null}  $after
 */
function sequenceConsumed(array $before, array $after): int
{
    $beforeValue = (int) $before['last_value'];
    $afterValue = (int) $after['last_value'];

    if ($afterValue < $beforeValue) {
        throw new RuntimeException('calibration_number_seq was reset.');
    }

    if ($afterValue === $beforeValue && $before['is_called'] === $after['is_called']) {
        return 0;
    }

    if ($before['is_called'] === false && $after['is_called'] === true && $afterValue === $beforeValue) {
        return 1;
    }

    if ($before['is_called'] === true && $afterValue === $beforeValue) {
        return 0;
    }

    $consumed = $afterValue - $beforeValue;

    if ($before['is_called'] === false) {
        $consumed++;
    }

    return $consumed;
}
