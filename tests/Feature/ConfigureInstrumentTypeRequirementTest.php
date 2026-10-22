<?php

use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\ConfigureInstrumentTypeRequirement;
use App\CalibrationSessions\ConfigureInstrumentTypeRequirementException;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use Tests\TestRefusingPostgresConnector;

const REQUIREMENT_WRITER_CONNECTION = 'requirement_sqlite';

function requirementWriterConnection(): Connection
{
    config([
        'database.connections.'.REQUIREMENT_WRITER_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(REQUIREMENT_WRITER_CONNECTION);

    $schema = Schema::connection(REQUIREMENT_WRITER_CONNECTION);

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
        $table->string('requirement_mode', 20)->default('not_configured');
        $table->timestamp('updated_at');
        $table->primary(['instrument_type_id', 'requirement_category']);
    });

    $schema->create('instrument_type_environment_parameter', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id');
        $table->unsignedBigInteger('parameter_id');
        $table->string('requirement_mode', 20);
        $table->integer('display_order');
        $table->primary(['instrument_type_id', 'parameter_id']);
    });

    $schema->create('calibration_requirement_snapshot', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_requirement_snapshot_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->string('requirement_category', 20);
        $table->string('frozen_mode', 20);
        $table->string('resolved_mode', 20)->nullable();
    });

    $schema->create('calibration_environment_parameter_snapshot', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('parameter_id');
        $table->string('requirement_mode', 20);
        $table->primary(['calibration_id', 'parameter_id']);
    });

    $connection = DB::connection(REQUIREMENT_WRITER_CONNECTION);
    $connection->table('instrument')->insert([
        'instrument_id' => 7,
        'instrument_name' => 'Balance',
    ]);
    $connection->table('instrument_type')->insert([
        'instrument_type_id' => 11,
        'instrument_id' => 7,
        'type_name' => 'Analytical',
    ]);

    return $connection;
}

function requirementWriter(): ConfigureInstrumentTypeRequirement
{
    return app(ConfigureInstrumentTypeRequirement::class);
}

function requirementMode(Connection $connection, string $category): ?string
{
    $mode = $connection->table('instrument_type_requirement')
        ->where('instrument_type_id', 11)
        ->where('requirement_category', $category)
        ->value('requirement_mode');

    return is_string($mode) ? $mode : null;
}

it('stores standards as required on the supplied connection', function () {
    $connection = requirementWriterConnection();

    requirementWriter()->configure(11, InstrumentTypeRequirement::CATEGORY_STANDARDS, InstrumentTypeRequirement::MODE_REQUIRED, $connection);

    expect(requirementMode($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS))->toBe('required')
        ->and($connection->table('instrument_type_requirement')->count())->toBe(1);
    expect(Schema::connection('sqlite')->hasTable('instrument_type_requirement'))->toBeFalse();
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('stores activity as optional', function () {
    $connection = requirementWriterConnection();

    requirementWriter()->configure(11, InstrumentTypeRequirement::CATEGORY_ACTIVITY, InstrumentTypeRequirement::MODE_OPTIONAL, $connection);

    expect(requirementMode($connection, InstrumentTypeRequirement::CATEGORY_ACTIVITY))->toBe('optional');
});

it('stores test profile as not configured', function () {
    $connection = requirementWriterConnection();

    requirementWriter()->configure(11, InstrumentTypeRequirement::CATEGORY_TEST_PROFILE, InstrumentTypeRequirement::MODE_NOT_CONFIGURED, $connection);

    expect(requirementMode($connection, InstrumentTypeRequirement::CATEGORY_TEST_PROFILE))->toBe('not_configured');
});

it('updates an existing category without adding a second row', function () {
    $connection = requirementWriterConnection();
    $connection->table('instrument_type_requirement')->insert([
        'instrument_type_id' => 11,
        'requirement_category' => InstrumentTypeRequirement::CATEGORY_STANDARDS,
        'requirement_mode' => InstrumentTypeRequirement::MODE_OPTIONAL,
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    requirementWriter()->configure(11, InstrumentTypeRequirement::CATEGORY_STANDARDS, InstrumentTypeRequirement::MODE_REQUIRED, $connection);

    expect(requirementMode($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS))->toBe('required')
        ->and($connection->table('instrument_type_requirement')->count())->toBe(1);
});

it('keeps a single row when the same mode is written again', function () {
    $connection = requirementWriterConnection();
    $service = requirementWriter();

    $service->configure(11, InstrumentTypeRequirement::CATEGORY_ACTIVITY, InstrumentTypeRequirement::MODE_OPTIONAL, $connection);
    $service->configure(11, InstrumentTypeRequirement::CATEGORY_ACTIVITY, InstrumentTypeRequirement::MODE_OPTIONAL, $connection);

    expect($connection->table('instrument_type_requirement')->count())->toBe(1)
        ->and(requirementMode($connection, InstrumentTypeRequirement::CATEGORY_ACTIVITY))->toBe('optional');
});

it('rejects an unknown requirement category before writing', function () {
    $connection = requirementWriterConnection();

    expect(fn () => requirementWriter()->configure(11, 'certificate', InstrumentTypeRequirement::MODE_REQUIRED, $connection))
        ->toThrow(ConfigureInstrumentTypeRequirementException::class, 'The requirement category is not supported.');
    expect($connection->table('instrument_type_requirement')->count())->toBe(0);
});

it('rejects an unknown requirement mode before writing', function () {
    $connection = requirementWriterConnection();

    expect(fn () => requirementWriter()->configure(11, InstrumentTypeRequirement::CATEGORY_STANDARDS, 'maybe', $connection))
        ->toThrow(ConfigureInstrumentTypeRequirementException::class, 'The requirement mode is not supported.');
    expect($connection->table('instrument_type_requirement')->count())->toBe(0);
});

it('rejects a missing instrument type', function () {
    $connection = requirementWriterConnection();

    expect(fn () => requirementWriter()->configure(99, InstrumentTypeRequirement::CATEGORY_STANDARDS, InstrumentTypeRequirement::MODE_REQUIRED, $connection))
        ->toThrow(ConfigureInstrumentTypeRequirementException::class, 'The instrument type does not exist.');
    expect($connection->table('instrument_type_requirement')->count())->toBe(0);
});

it('rejects environment required when no required parameter exists', function () {
    $connection = requirementWriterConnection();

    expect(fn () => requirementWriter()->configure(11, InstrumentTypeRequirement::CATEGORY_ENVIRONMENT, InstrumentTypeRequirement::MODE_REQUIRED, $connection))
        ->toThrow(ConfigureInstrumentTypeRequirementException::class, 'Environment required needs at least one required environment parameter.');
    expect($connection->table('instrument_type_requirement')->count())->toBe(0);
    expect($connection->table('instrument_type_environment_parameter')->count())->toBe(0);
});

it('stores environment required when a required parameter already exists', function () {
    $connection = requirementWriterConnection();
    $connection->table('instrument_type_environment_parameter')->insert([
        'instrument_type_id' => 11,
        'parameter_id' => 3,
        'requirement_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
        'display_order' => 1,
    ]);

    requirementWriter()->configure(11, InstrumentTypeRequirement::CATEGORY_ENVIRONMENT, InstrumentTypeRequirement::MODE_REQUIRED, $connection);

    expect(requirementMode($connection, InstrumentTypeRequirement::CATEGORY_ENVIRONMENT))->toBe('required')
        ->and($connection->table('instrument_type_environment_parameter')->count())->toBe(1);
});

it('stores environment optional when the type has no parameters', function () {
    $connection = requirementWriterConnection();

    requirementWriter()->configure(11, InstrumentTypeRequirement::CATEGORY_ENVIRONMENT, InstrumentTypeRequirement::MODE_OPTIONAL, $connection);

    expect(requirementMode($connection, InstrumentTypeRequirement::CATEGORY_ENVIRONMENT))->toBe('optional');
});

it('rolls back when the requirement write fails', function () {
    $connection = requirementWriterConnection();
    $connection->table('instrument_type_requirement')->insert([
        'instrument_type_id' => 11,
        'requirement_category' => InstrumentTypeRequirement::CATEGORY_STANDARDS,
        'requirement_mode' => InstrumentTypeRequirement::MODE_OPTIONAL,
        'updated_at' => '2020-01-01 00:00:00',
    ]);
    $connection->unprepared("
        CREATE TRIGGER abort_activity_requirement_insert
        BEFORE INSERT ON instrument_type_requirement
        FOR EACH ROW
        WHEN NEW.requirement_category = 'activity'
        BEGIN
            SELECT RAISE(ABORT, 'forced requirement failure');
        END
    ");

    expect(fn () => requirementWriter()->configure(11, InstrumentTypeRequirement::CATEGORY_ACTIVITY, InstrumentTypeRequirement::MODE_OPTIONAL, $connection))
        ->toThrow(QueryException::class);

    expect(requirementMode($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS))->toBe('optional')
        ->and($connection->table('instrument_type_requirement')->where('requirement_category', 'activity')->count())->toBe(0);
});

it('leaves existing session snapshots unchanged after a master mode change', function () {
    $connection = requirementWriterConnection();
    $connection->table('instrument_type_requirement')->insert([
        'instrument_type_id' => 11,
        'requirement_category' => InstrumentTypeRequirement::CATEGORY_STANDARDS,
        'requirement_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
        'updated_at' => '2020-01-01 00:00:00',
    ]);
    $connection->table('calibration_requirement_snapshot')->insert([
        'calibration_requirement_snapshot_id' => 1,
        'calibration_id' => 40,
        'requirement_category' => InstrumentTypeRequirement::CATEGORY_STANDARDS,
        'frozen_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
        'resolved_mode' => null,
    ]);
    $connection->table('calibration_environment_parameter_snapshot')->insert([
        'calibration_id' => 40,
        'parameter_id' => 3,
        'requirement_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
    ]);

    requirementWriter()->configure(11, InstrumentTypeRequirement::CATEGORY_STANDARDS, InstrumentTypeRequirement::MODE_OPTIONAL, $connection);

    expect(requirementMode($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS))->toBe('optional')
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('frozen_mode'))->toBe('required')
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', 40)->value('resolved_mode'))->toBeNull()
        ->and($connection->table('calibration_environment_parameter_snapshot')->count())->toBe(1);
});

it('refuses an unsafe connection before any requirement write', function () {
    $connection = requirementWriterConnection();
    $unsafe = Mockery::mock(Connection::class);
    $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
    $unsafe->shouldNotReceive('transaction');
    $unsafe->shouldNotReceive('table');

    expect(fn () => requirementWriter()->configure(11, InstrumentTypeRequirement::CATEGORY_STANDARDS, InstrumentTypeRequirement::MODE_REQUIRED, $unsafe))
        ->toThrow(CalibrationSessionConnectionException::class);
    expect($connection->table('instrument_type_requirement')->count())->toBe(0);
    expect(config('database.default'))->toBe('sqlite');
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('locks and writes the requirement through the supplied connection when its name is empty or unregistered', function (string $connectionName) {
    $connection = requirementWriterConnection();
    $config = new ReflectionProperty($connection, 'config');
    $settings = $config->getValue($connection);
    $settings['name'] = $connectionName;
    $config->setValue($connection, $settings);

    $connection->enableQueryLog();
    $default = DB::connection();
    $default->enableQueryLog();

    requirementWriter()->configure(11, InstrumentTypeRequirement::CATEGORY_STANDARDS, InstrumentTypeRequirement::MODE_REQUIRED, $connection);

    $suppliedSql = implode("\n", array_column($connection->getQueryLog(), 'query'));
    $defaultSql = implode("\n", array_column($default->getQueryLog(), 'query'));

    expect($connection->getName())->toBe($connectionName)
        ->and(requirementMode($connection, InstrumentTypeRequirement::CATEGORY_STANDARDS))->toBe('required')
        ->and($suppliedSql)->toContain('from "instrument_type"')
        ->and($suppliedSql)->toContain('instrument_type_requirement')
        ->and($defaultSql)->not->toContain('instrument_type')
        ->and(Schema::connection('sqlite')->hasTable('instrument_type'))->toBeFalse()
        ->and(config('database.default'))->toBe('sqlite')
        ->and(DB::getDefaultConnection())->toBe('sqlite')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
})->with([
    'empty name' => '',
    'unregistered name' => 'unowned-requirement-lock',
]);
