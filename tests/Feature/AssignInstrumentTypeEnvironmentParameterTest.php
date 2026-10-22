<?php

use App\CalibrationSessions\AssignInstrumentTypeEnvironmentParameter;
use App\CalibrationSessions\AssignInstrumentTypeEnvironmentParameterException;
use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use Tests\TestRefusingPostgresConnector;

const ENVIRONMENT_ASSIGNMENT_CONNECTION = 'environment_assignment_sqlite';

function environmentAssignmentConnection(): Connection
{
    config([
        'database.connections.'.ENVIRONMENT_ASSIGNMENT_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(ENVIRONMENT_ASSIGNMENT_CONNECTION);

    $schema = Schema::connection(ENVIRONMENT_ASSIGNMENT_CONNECTION);

    $schema->create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name', 150);
    });

    $schema->create('instrument_type', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name', 150);
    });

    $schema->create('instrument_type_requirement', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id');
        $table->string('requirement_category', 20);
        $table->string('requirement_mode', 20);
        $table->timestamp('updated_at');
        $table->primary(['instrument_type_id', 'requirement_category']);
    });

    $schema->create('environment_parameter', function (Blueprint $table): void {
        $table->unsignedBigInteger('parameter_id')->primary();
        $table->string('parameter_code', 50);
        $table->string('parameter_name', 150);
        $table->string('unit', 50);
        $table->string('data_type', 20);
        $table->boolean('is_system')->default(false);
    });

    $schema->create('instrument_type_environment_parameter', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id');
        $table->unsignedBigInteger('parameter_id');
        $table->string('requirement_mode', 20);
        $table->integer('display_order');
        $table->primary(['instrument_type_id', 'parameter_id']);
        $table->unique(['instrument_type_id', 'display_order']);
    });

    $schema->create('calibration_environment_parameter_snapshot', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('parameter_id');
        $table->string('parameter_code', 50);
        $table->string('requirement_mode', 20);
        $table->integer('display_order');
        $table->primary(['calibration_id', 'parameter_id']);
    });

    $schema->create('calibration_environment_value', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_environment_value_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('parameter_id');
        $table->decimal('numeric_value', 12, 4)->nullable();
    });

    $schema->create('calibration_requirement_snapshot', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_requirement_snapshot_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->string('requirement_category', 20);
        $table->string('frozen_mode', 20);
        $table->string('resolved_mode', 20)->nullable();
    });

    $connection = DB::connection(ENVIRONMENT_ASSIGNMENT_CONNECTION);
    $connection->table('instrument')->insert([
        'instrument_id' => 7,
        'instrument_name' => 'Balance',
    ]);
    $connection->table('instrument_type')->insert([
        'instrument_type_id' => 11,
        'instrument_id' => 7,
        'type_name' => 'Analytical',
    ]);
    $connection->table('environment_parameter')->insert([
        [
            'parameter_id' => 1,
            'parameter_code' => 'temperature',
            'parameter_name' => 'Temperature',
            'unit' => '°C',
            'data_type' => 'numeric',
            'is_system' => true,
        ],
        [
            'parameter_id' => 2,
            'parameter_code' => 'relative_humidity',
            'parameter_name' => 'Relative Humidity',
            'unit' => '%RH',
            'data_type' => 'numeric',
            'is_system' => true,
        ],
    ]);

    return $connection;
}

function environmentAssignmentWriter(): AssignInstrumentTypeEnvironmentParameter
{
    return app(AssignInstrumentTypeEnvironmentParameter::class);
}

function environmentAssignmentMode(Connection $connection, int $parameterId): ?string
{
    $mode = $connection->table('instrument_type_environment_parameter')
        ->where('instrument_type_id', 11)
        ->where('parameter_id', $parameterId)
        ->value('requirement_mode');

    return is_string($mode) ? $mode : null;
}

function environmentAssignmentOrder(Connection $connection, int $parameterId): ?int
{
    $order = $connection->table('instrument_type_environment_parameter')
        ->where('instrument_type_id', 11)
        ->where('parameter_id', $parameterId)
        ->value('display_order');

    return is_numeric($order) ? (int) $order : null;
}

function environmentCategory(Connection $connection, string $mode): void
{
    $connection->table('instrument_type_requirement')->insert([
        'instrument_type_id' => 11,
        'requirement_category' => InstrumentTypeRequirement::CATEGORY_ENVIRONMENT,
        'requirement_mode' => $mode,
        'updated_at' => '2020-01-01 00:00:00',
    ]);
}

it('assigns temperature as required on the supplied connection', function () {
    $connection = environmentAssignmentConnection();

    environmentAssignmentWriter()->assign(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1, $connection);

    expect(environmentAssignmentMode($connection, 1))->toBe('required')
        ->and(environmentAssignmentOrder($connection, 1))->toBe(1)
        ->and($connection->table('environment_parameter')->where('parameter_id', 1)->value('parameter_name'))->toBe('Temperature')
        ->and($connection->table('environment_parameter')->where('parameter_id', 1)->value('unit'))->toBe('°C');
    expect(Schema::connection('sqlite')->hasTable('instrument_type_environment_parameter'))->toBeFalse();
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('assigns relative humidity as optional', function () {
    $connection = environmentAssignmentConnection();

    environmentAssignmentWriter()->assign(11, 2, InstrumentTypeRequirement::MODE_OPTIONAL, 2, $connection);

    expect(environmentAssignmentMode($connection, 2))->toBe('optional');
});

it('rejects a duplicate assignment', function () {
    $connection = environmentAssignmentConnection();
    $writer = environmentAssignmentWriter();
    $writer->assign(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1, $connection);

    expect(fn () => $writer->assign(11, 1, InstrumentTypeRequirement::MODE_OPTIONAL, 2, $connection))
        ->toThrow(AssignInstrumentTypeEnvironmentParameterException::class, 'This environment parameter is already assigned to the instrument type.');
    expect($connection->table('instrument_type_environment_parameter')->count())->toBe(1);
});

it('changes an optional assignment to required', function () {
    $connection = environmentAssignmentConnection();
    $writer = environmentAssignmentWriter();
    $writer->assign(11, 2, InstrumentTypeRequirement::MODE_OPTIONAL, 1, $connection);

    $writer->changeMode(11, 2, InstrumentTypeRequirement::MODE_REQUIRED, $connection);

    expect(environmentAssignmentMode($connection, 2))->toBe('required');
});

it('rejects not configured as an assignment mode', function () {
    $connection = environmentAssignmentConnection();

    expect(fn () => environmentAssignmentWriter()->assign(11, 1, InstrumentTypeRequirement::MODE_NOT_CONFIGURED, 1, $connection))
        ->toThrow(AssignInstrumentTypeEnvironmentParameterException::class, 'The assignment mode is not supported.');
    expect($connection->table('instrument_type_environment_parameter')->count())->toBe(0);
});

it('rejects a missing instrument type', function () {
    $connection = environmentAssignmentConnection();

    expect(fn () => environmentAssignmentWriter()->assign(99, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1, $connection))
        ->toThrow(AssignInstrumentTypeEnvironmentParameterException::class, 'The instrument type does not exist.');
});

it('rejects a missing environment parameter', function () {
    $connection = environmentAssignmentConnection();

    expect(fn () => environmentAssignmentWriter()->assign(11, 99, InstrumentTypeRequirement::MODE_REQUIRED, 1, $connection))
        ->toThrow(AssignInstrumentTypeEnvironmentParameterException::class, 'The environment parameter does not exist.');
    expect($connection->table('environment_parameter')->count())->toBe(2);
});

it('removes an optional assignment', function () {
    $connection = environmentAssignmentConnection();
    $writer = environmentAssignmentWriter();
    $writer->assign(11, 2, InstrumentTypeRequirement::MODE_OPTIONAL, 1, $connection);

    $writer->remove(11, 2, $connection);

    expect($connection->table('instrument_type_environment_parameter')->count())->toBe(0);
});

it('rejects removal of the last required parameter when environment is required', function () {
    $connection = environmentAssignmentConnection();
    environmentCategory($connection, InstrumentTypeRequirement::MODE_REQUIRED);
    $writer = environmentAssignmentWriter();
    $writer->assign(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1, $connection);

    expect(fn () => $writer->remove(11, 1, $connection))
        ->toThrow(AssignInstrumentTypeEnvironmentParameterException::class, 'Environment required needs at least one required environment parameter.');
    expect(environmentAssignmentMode($connection, 1))->toBe('required');
});

it('rejects downgrading the last required parameter when environment is required', function () {
    $connection = environmentAssignmentConnection();
    environmentCategory($connection, InstrumentTypeRequirement::MODE_REQUIRED);
    $writer = environmentAssignmentWriter();
    $writer->assign(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1, $connection);

    expect(fn () => $writer->changeMode(11, 1, InstrumentTypeRequirement::MODE_OPTIONAL, $connection))
        ->toThrow(AssignInstrumentTypeEnvironmentParameterException::class, 'Environment required needs at least one required environment parameter.');
    expect(environmentAssignmentMode($connection, 1))->toBe('required');
});

it('removes a required parameter when another required parameter remains', function () {
    $connection = environmentAssignmentConnection();
    environmentCategory($connection, InstrumentTypeRequirement::MODE_REQUIRED);
    $writer = environmentAssignmentWriter();
    $writer->assign(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1, $connection);
    $writer->assign(11, 2, InstrumentTypeRequirement::MODE_REQUIRED, 2, $connection);

    $writer->remove(11, 2, $connection);

    expect(environmentAssignmentMode($connection, 1))->toBe('required')
        ->and(environmentAssignmentMode($connection, 2))->toBeNull();
});

it('removes the last required parameter when environment is optional', function () {
    $connection = environmentAssignmentConnection();
    environmentCategory($connection, InstrumentTypeRequirement::MODE_OPTIONAL);
    $writer = environmentAssignmentWriter();
    $writer->assign(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1, $connection);

    $writer->remove(11, 1, $connection);

    expect($connection->table('instrument_type_environment_parameter')->count())->toBe(0)
        ->and($connection->table('instrument_type_requirement')->value('requirement_mode'))->toBe('optional');
});

it('removes the last required parameter when environment is not configured', function () {
    $connection = environmentAssignmentConnection();
    environmentCategory($connection, InstrumentTypeRequirement::MODE_NOT_CONFIGURED);
    $writer = environmentAssignmentWriter();
    $writer->assign(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1, $connection);

    $writer->remove(11, 1, $connection);

    expect($connection->table('instrument_type_environment_parameter')->count())->toBe(0)
        ->and($connection->table('instrument_type_requirement')->value('requirement_mode'))->toBe('not_configured');
});

it('rejects a display order below 1', function () {
    $connection = environmentAssignmentConnection();

    expect(fn () => environmentAssignmentWriter()->assign(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 0, $connection))
        ->toThrow(AssignInstrumentTypeEnvironmentParameterException::class, 'Display order must be an integer from 1 through 2147483647.');
    expect($connection->table('instrument_type_environment_parameter')->count())->toBe(0);
});

it('reorders two assignments without duplicating display order', function () {
    $connection = environmentAssignmentConnection();
    $writer = environmentAssignmentWriter();
    $writer->assign(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1, $connection);
    $writer->assign(11, 2, InstrumentTypeRequirement::MODE_OPTIONAL, 2, $connection);

    $writer->reorder(11, [2, 1], $connection);

    expect(environmentAssignmentOrder($connection, 2))->toBe(1)
        ->and(environmentAssignmentOrder($connection, 1))->toBe(2)
        ->and($connection->table('instrument_type_environment_parameter')->count())->toBe(2);
});

it('reorders three assignments onto positions starting at 1', function () {
    $connection = environmentAssignmentConnection();
    $connection->table('environment_parameter')->insert([
        'parameter_id' => 3,
        'parameter_code' => 'pressure',
        'parameter_name' => 'Pressure',
        'unit' => 'hPa',
        'data_type' => 'numeric',
        'is_system' => false,
    ]);
    $writer = environmentAssignmentWriter();
    $writer->assign(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1, $connection);
    $writer->assign(11, 2, InstrumentTypeRequirement::MODE_OPTIONAL, 2, $connection);
    $writer->assign(11, 3, InstrumentTypeRequirement::MODE_OPTIONAL, 3, $connection);

    $writer->reorder(11, [3, 1, 2], $connection);

    expect(environmentAssignmentOrder($connection, 3))->toBe(1)
        ->and(environmentAssignmentOrder($connection, 1))->toBe(2)
        ->and(environmentAssignmentOrder($connection, 2))->toBe(3);
});

it('rejects a reorder list that omits an assignment', function () {
    $connection = environmentAssignmentConnection();
    $writer = environmentAssignmentWriter();
    $writer->assign(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1, $connection);
    $writer->assign(11, 2, InstrumentTypeRequirement::MODE_OPTIONAL, 2, $connection);

    expect(fn () => $writer->reorder(11, [1], $connection))
        ->toThrow(AssignInstrumentTypeEnvironmentParameterException::class, 'Reorder must include every current environment parameter assignment once.');
    expect(environmentAssignmentOrder($connection, 1))->toBe(1)
        ->and(environmentAssignmentOrder($connection, 2))->toBe(2);
});

it('rejects a reorder list that repeats a parameter', function () {
    $connection = environmentAssignmentConnection();
    $writer = environmentAssignmentWriter();
    $writer->assign(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1, $connection);
    $writer->assign(11, 2, InstrumentTypeRequirement::MODE_OPTIONAL, 2, $connection);

    expect(fn () => $writer->reorder(11, [1, 1], $connection))
        ->toThrow(AssignInstrumentTypeEnvironmentParameterException::class, 'Reorder contains a duplicate environment parameter.');
});

it('rejects a reorder list that names an unassigned parameter', function () {
    $connection = environmentAssignmentConnection();
    $writer = environmentAssignmentWriter();
    $writer->assign(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1, $connection);

    expect(fn () => $writer->reorder(11, [99], $connection))
        ->toThrow(AssignInstrumentTypeEnvironmentParameterException::class, 'Reorder contains an environment parameter that is not assigned.');
    expect(environmentAssignmentOrder($connection, 1))->toBe(1);
});

it('rolls back a reorder when a later display order update fails', function () {
    $connection = environmentAssignmentConnection();
    $writer = environmentAssignmentWriter();
    $writer->assign(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1, $connection);
    $writer->assign(11, 2, InstrumentTypeRequirement::MODE_OPTIONAL, 2, $connection);
    $connection->unprepared("
        CREATE TRIGGER abort_environment_reorder_to_one
        BEFORE UPDATE ON instrument_type_environment_parameter
        FOR EACH ROW
        WHEN NEW.display_order = 1 AND OLD.display_order <> 1
        BEGIN
            SELECT RAISE(ABORT, 'forced reorder failure');
        END
    ");

    expect(fn () => $writer->reorder(11, [2, 1], $connection))
        ->toThrow(QueryException::class);

    expect(environmentAssignmentOrder($connection, 1))->toBe(1)
        ->and(environmentAssignmentOrder($connection, 2))->toBe(2);
});

it('leaves session snapshots unchanged after assignment changes', function () {
    $connection = environmentAssignmentConnection();
    $connection->table('calibration_environment_parameter_snapshot')->insert([
        'calibration_id' => 40,
        'parameter_id' => 1,
        'parameter_code' => 'temperature',
        'requirement_mode' => 'required',
        'display_order' => 1,
    ]);
    $connection->table('calibration_environment_value')->insert([
        'calibration_environment_value_id' => 8,
        'calibration_id' => 40,
        'parameter_id' => 1,
        'numeric_value' => 21.5,
    ]);
    $connection->table('calibration_requirement_snapshot')->insert([
        'calibration_requirement_snapshot_id' => 4,
        'calibration_id' => 40,
        'requirement_category' => 'environment',
        'frozen_mode' => 'required',
        'resolved_mode' => null,
    ]);
    $writer = environmentAssignmentWriter();
    $writer->assign(11, 1, InstrumentTypeRequirement::MODE_OPTIONAL, 1, $connection);
    $writer->changeMode(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, $connection);
    $writer->assign(11, 2, InstrumentTypeRequirement::MODE_OPTIONAL, 2, $connection);
    $writer->reorder(11, [2, 1], $connection);
    $writer->remove(11, 2, $connection);

    expect($connection->table('calibration_environment_parameter_snapshot')->where('calibration_id', 40)->value('requirement_mode'))->toBe('required')
        ->and($connection->table('calibration_environment_parameter_snapshot')->where('calibration_id', 40)->value('display_order'))->toBe(1)
        ->and((float) $connection->table('calibration_environment_value')->where('calibration_id', 40)->value('numeric_value'))->toBe(21.5)
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('frozen_mode'))->toBe('required')
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('resolved_mode'))->toBeNull();
});

it('refuses an unsafe connection before any assignment write', function () {
    $connection = environmentAssignmentConnection();
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('transaction');
    $unsafe->shouldNotReceive('table');

    expect(fn () => environmentAssignmentWriter()->assign(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1, $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class);
    expect($connection->table('instrument_type_environment_parameter')->count())->toBe(0);
    expect($connection->table('environment_parameter')->count())->toBe(2);
    expect(config('database.default'))->toBe('sqlite');
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('locks and writes the assignment through the supplied connection when its name is empty or unregistered', function (string $connectionName) {
    $connection = environmentAssignmentConnection();
    $config = new ReflectionProperty($connection, 'config');
    $settings = $config->getValue($connection);
    $settings['name'] = $connectionName;
    $config->setValue($connection, $settings);

    $connection->enableQueryLog();
    $default = DB::connection();
    $default->enableQueryLog();

    environmentAssignmentWriter()->assign(11, 1, InstrumentTypeRequirement::MODE_REQUIRED, 1, $connection);

    $suppliedSql = implode("\n", array_column($connection->getQueryLog(), 'query'));
    $defaultSql = implode("\n", array_column($default->getQueryLog(), 'query'));

    expect($connection->getName())->toBe($connectionName)
        ->and(environmentAssignmentMode($connection, 1))->toBe('required')
        ->and(environmentAssignmentOrder($connection, 1))->toBe(1)
        ->and($suppliedSql)->toContain('from "instrument_type"')
        ->and($suppliedSql)->toContain('instrument_type_environment_parameter')
        ->and($defaultSql)->not->toContain('instrument_type')
        ->and(Schema::connection('sqlite')->hasTable('instrument_type'))->toBeFalse()
        ->and(config('database.default'))->toBe('sqlite')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty name' => '',
    'unregistered name' => 'unowned-assignment-lock',
]);
