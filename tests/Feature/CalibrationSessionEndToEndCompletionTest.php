<?php

use App\CalibrationSessions\CalibrationNumberAllocator;
use App\CalibrationSessions\CompleteCalibrationSession;
use App\CalibrationSessions\CompleteCalibrationSessionException;
use App\CalibrationSessions\ConfirmEnvironmentConfiguration;
use App\CalibrationSessions\MaterializeCalibrationTestTemplateValues;
use App\CalibrationSessions\PinCalibrationTestTables;
use App\CalibrationSessions\PinCalibrationTestTablesException;
use App\CalibrationSessions\RecordCalibrationActivity;
use App\CalibrationSessions\RecordCalibrationEnvironmentMeasurement;
use App\CalibrationSessions\RecordCalibrationScopeRemarks;
use App\CalibrationSessions\RecordCalibrationScopeRemarksException;
use App\CalibrationSessions\RecordCalibrationStandardUsage;
use App\CalibrationSessions\RecordCalibrationStandardUsageException;
use App\CalibrationSessions\RecordCalibrationTestResult;
use App\CalibrationSessions\RecordCalibrationTestResultException;
use App\CalibrationSessions\StartCalibrationException;
use App\CalibrationSessions\StartCalibrationSession;
use App\CalibrationSessions\StoreExternalCalibrationDocument;
use App\CalibrationSessions\StoreExternalCalibrationDocumentException;
use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeCalibrationNumberAllocator;
use Tests\TestRefusingPostgresConnector;

const CALIBRATION_SESSION_E2E_CONNECTION = 'calibration_session_e2e_sqlite';

afterEach(function () {
    Carbon::setTestNow();
    app()->forgetInstance(CalibrationNumberAllocator::class);
    Storage::fake('calibration_documents');
    Storage::fake('public');
    Storage::fake('local');
});

function calibrationSessionE2eConnection(): Connection
{
    Carbon::setTestNow('2026-10-22 08:15:00');

    config([
        'database.connections.'.CALIBRATION_SESSION_E2E_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge(CALIBRATION_SESSION_E2E_CONNECTION);

    $connection = DB::connection(CALIBRATION_SESSION_E2E_CONNECTION);
    $connection->unprepared('
        CREATE TABLE location (
            location_id INTEGER PRIMARY KEY,
            location_code VARCHAR(50) NOT NULL,
            location_name VARCHAR(100) NOT NULL,
            created_at TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE detail_location (
            detail_location_id INTEGER PRIMARY KEY,
            location_id INTEGER NOT NULL,
            detail_location_code VARCHAR(50) NOT NULL,
            detail_location_name VARCHAR(100) NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (location_id) REFERENCES location (location_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE instrument (
            instrument_id INTEGER PRIMARY KEY,
            instrument_name VARCHAR(150) NOT NULL,
            serial_number VARCHAR(255) NULL,
            detail_location_id INTEGER NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (detail_location_id) REFERENCES detail_location (detail_location_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE instrument_type (
            instrument_type_id INTEGER PRIMARY KEY,
            instrument_id INTEGER NOT NULL,
            type_name VARCHAR(150) NOT NULL,
            brand VARCHAR(150) NULL,
            model VARCHAR(150) NULL,
            asset_number VARCHAR(50) NULL,
            max_capacity TEXT NULL,
            max_capacity_unit VARCHAR(20) NULL,
            resolution TEXT NULL,
            resolution_unit VARCHAR(20) NULL,
            calibration_interval_months INTEGER NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (instrument_id) REFERENCES instrument (instrument_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_provider (
            calibration_provider_id INTEGER PRIMARY KEY,
            provider_code VARCHAR(50) NOT NULL UNIQUE,
            provider_name VARCHAR(150) NOT NULL,
            provider_type VARCHAR(20) NOT NULL,
            created_at TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration (
            calibration_id INTEGER PRIMARY KEY AUTOINCREMENT,
            instrument_id INTEGER NOT NULL,
            instrument_type_id INTEGER NOT NULL,
            calibration_provider_id INTEGER NULL,
            calibration_number VARCHAR NOT NULL UNIQUE,
            calibration_date TEXT NULL,
            calibration_due TEXT NULL,
            calibration_method VARCHAR NOT NULL,
            session_status VARCHAR NOT NULL,
            completed_at TEXT NULL,
            cancelled_at TEXT NULL,
            cancelled_reason TEXT NULL,
            cancelled_by_user_id INTEGER NULL,
            snapshot_instrument_name VARCHAR NULL,
            snapshot_instrument_type_name VARCHAR NULL,
            snapshot_brand VARCHAR NULL,
            snapshot_model VARCHAR NULL,
            snapshot_serial_number VARCHAR NULL,
            snapshot_asset_number VARCHAR NULL,
            snapshot_max_capacity TEXT NULL,
            snapshot_max_capacity_unit VARCHAR NULL,
            snapshot_resolution TEXT NULL,
            snapshot_resolution_unit VARCHAR NULL,
            snapshot_location_id INTEGER NULL,
            snapshot_detail_location_id INTEGER NULL,
            snapshot_location_code VARCHAR NULL,
            snapshot_location_name VARCHAR NULL,
            snapshot_detail_location_code VARCHAR NULL,
            snapshot_detail_location_name VARCHAR NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (instrument_id) REFERENCES instrument (instrument_id),
            FOREIGN KEY (instrument_type_id) REFERENCES instrument_type (instrument_type_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE instrument_type_requirement (
            instrument_type_id INTEGER NOT NULL,
            requirement_category VARCHAR(20) NOT NULL,
            requirement_mode VARCHAR(20) NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (instrument_type_id, requirement_category),
            FOREIGN KEY (instrument_type_id) REFERENCES instrument_type (instrument_type_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_requirement_snapshot (
            calibration_requirement_snapshot_id INTEGER PRIMARY KEY AUTOINCREMENT,
            calibration_id INTEGER NOT NULL,
            requirement_category VARCHAR(20) NOT NULL,
            frozen_mode VARCHAR(20) NOT NULL,
            resolved_mode VARCHAR(20) NULL,
            resolved_at TEXT NULL,
            resolved_reason TEXT NULL,
            resolved_by_user_id INTEGER NULL,
            configuration_confirmed_at TEXT NULL,
            configuration_confirmed_reason TEXT NULL,
            configuration_confirmed_by_user_id INTEGER NULL,
            UNIQUE (calibration_id, requirement_category),
            FOREIGN KEY (calibration_id) REFERENCES calibration (calibration_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE environment_parameter (
            parameter_id INTEGER PRIMARY KEY,
            parameter_code VARCHAR(50) NOT NULL UNIQUE,
            parameter_name VARCHAR(150) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            data_type VARCHAR(20) NOT NULL,
            is_system INTEGER NOT NULL,
            created_at TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE instrument_type_environment_parameter (
            instrument_type_id INTEGER NOT NULL,
            parameter_id INTEGER NOT NULL,
            requirement_mode VARCHAR(20) NOT NULL,
            display_order INTEGER NOT NULL,
            PRIMARY KEY (instrument_type_id, parameter_id),
            FOREIGN KEY (instrument_type_id) REFERENCES instrument_type (instrument_type_id),
            FOREIGN KEY (parameter_id) REFERENCES environment_parameter (parameter_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_environment_parameter_snapshot (
            calibration_id INTEGER NOT NULL,
            parameter_id INTEGER NOT NULL,
            parameter_code VARCHAR(50) NOT NULL,
            parameter_name VARCHAR(150) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            data_type VARCHAR(20) NOT NULL,
            requirement_mode VARCHAR(20) NOT NULL,
            display_order INTEGER NOT NULL,
            included_at TEXT NOT NULL,
            inclusion_confirmed INTEGER NOT NULL,
            inclusion_reason TEXT NULL,
            PRIMARY KEY (calibration_id, parameter_id),
            UNIQUE (calibration_id, parameter_code),
            UNIQUE (calibration_id, display_order),
            FOREIGN KEY (calibration_id) REFERENCES calibration (calibration_id),
            CHECK (inclusion_confirmed OR inclusion_reason IS NOT NULL)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_environment_value (
            calibration_environment_value_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            parameter_id INTEGER NOT NULL,
            numeric_value TEXT NULL,
            text_value TEXT NULL,
            recorded_at TEXT NOT NULL,
            UNIQUE (calibration_id, parameter_id),
            FOREIGN KEY (calibration_id, parameter_id)
                REFERENCES calibration_environment_parameter_snapshot (calibration_id, parameter_id),
            CHECK (numeric_value IS NULL OR text_value IS NULL)
        )
    ');
    $connection->unprepared('
        CREATE TABLE standard_master (
            standard_id INTEGER PRIMARY KEY,
            standard_code VARCHAR(20) NOT NULL UNIQUE,
            instrument_name VARCHAR(150) NOT NULL,
            type_specification VARCHAR(150) NULL,
            serial_number VARCHAR(150) NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE instrument_type_standard_assignment (
            instrument_type_standard_assignment_id INTEGER PRIMARY KEY,
            instrument_type_id INTEGER NOT NULL,
            standard_id INTEGER NOT NULL,
            standard_instrument_id INTEGER NULL,
            assignment_order INTEGER NOT NULL,
            standard_display_name VARCHAR(255) NULL,
            default_range VARCHAR(150) NULL,
            default_unit VARCHAR(50) NULL,
            UNIQUE (instrument_type_id, standard_id),
            UNIQUE (instrument_type_id, assignment_order),
            FOREIGN KEY (instrument_type_id) REFERENCES instrument_type (instrument_type_id),
            FOREIGN KEY (standard_id) REFERENCES standard_master (standard_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_standard_usage (
            calibration_standard_usage_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            standard_instrument_id INTEGER NULL,
            standard_id INTEGER NULL,
            usage_order INTEGER NOT NULL,
            usage_purpose VARCHAR(150) NULL,
            remarks TEXT NULL,
            created_at TEXT NOT NULL,
            standard_range VARCHAR(150) NULL,
            standard_unit VARCHAR(50) NULL,
            snapshot_standard_code VARCHAR(20) NULL,
            snapshot_instrument_name VARCHAR(150) NULL,
            snapshot_type_specification VARCHAR(150) NULL,
            snapshot_serial_number VARCHAR(150) NULL,
            FOREIGN KEY (calibration_id) REFERENCES calibration (calibration_id),
            FOREIGN KEY (standard_id) REFERENCES standard_master (standard_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_activity_master (
            activity_id INTEGER PRIMARY KEY,
            activity_code VARCHAR(30) NOT NULL UNIQUE,
            activity_name VARCHAR(255) NOT NULL,
            is_active INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_activity (
            calibration_activity_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            activity_type TEXT NULL,
            activity_id INTEGER NULL,
            activity_name VARCHAR(255) NULL,
            activity_description TEXT NULL,
            activity_result TEXT NULL,
            activity_order INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (calibration_id) REFERENCES calibration (calibration_id),
            FOREIGN KEY (activity_id) REFERENCES calibration_activity_master (activity_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE test_profile (
            test_profile_id INTEGER PRIMARY KEY,
            instrument_type_id INTEGER NOT NULL,
            profile_name VARCHAR(150) NOT NULL,
            display_title VARCHAR(150) NULL,
            description TEXT NULL,
            archived_at TEXT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (instrument_type_id) REFERENCES instrument_type (instrument_type_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE test_profile_version (
            test_profile_version_id INTEGER PRIMARY KEY,
            test_profile_id INTEGER NOT NULL,
            version_number INTEGER NOT NULL,
            status VARCHAR(20) NOT NULL,
            profile_name VARCHAR(150) NOT NULL,
            display_title VARCHAR(150) NULL,
            description TEXT NULL,
            published_at TEXT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (test_profile_id) REFERENCES test_profile (test_profile_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE test_column (
            test_column_id INTEGER PRIMARY KEY,
            test_profile_id INTEGER NOT NULL,
            test_profile_version_id INTEGER NOT NULL,
            column_key VARCHAR(63) NOT NULL,
            column_label VARCHAR(150) NOT NULL,
            column_order INTEGER NOT NULL,
            data_type VARCHAR(20) NOT NULL,
            value_source VARCHAR(20) NOT NULL,
            unit VARCHAR(20) NULL,
            display_scale INTEGER NULL,
            formula_code VARCHAR(40) NULL,
            formula_inputs TEXT NULL,
            completion_rule VARCHAR(20) NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (test_profile_version_id) REFERENCES test_profile_version (test_profile_version_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE test_point (
            test_point_id INTEGER PRIMARY KEY,
            test_profile_id INTEGER NOT NULL,
            test_profile_version_id INTEGER NOT NULL,
            point_order INTEGER NOT NULL,
            nominal_value TEXT NULL,
            unit VARCHAR(20) NULL,
            tolerance_plus TEXT NULL,
            tolerance_minus TEXT NULL,
            description TEXT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (test_profile_version_id) REFERENCES test_profile_version (test_profile_version_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE test_point_value (
            test_point_value_id INTEGER PRIMARY KEY,
            test_profile_id INTEGER NOT NULL,
            test_profile_version_id INTEGER NOT NULL,
            test_point_id INTEGER NOT NULL,
            test_column_id INTEGER NOT NULL,
            numeric_value TEXT NULL,
            text_value TEXT NULL,
            unit VARCHAR(20) NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (test_point_id) REFERENCES test_point (test_point_id),
            FOREIGN KEY (test_column_id) REFERENCES test_column (test_column_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_scope (
            calibration_scope_id INTEGER PRIMARY KEY,
            calibration_id INTEGER NOT NULL,
            instrument_type_id INTEGER NOT NULL,
            test_profile_id INTEGER NULL,
            test_profile_version_id INTEGER NULL,
            test_table_snapshot TEXT NULL,
            scope_order INTEGER NOT NULL,
            remarks TEXT NULL,
            created_at TEXT NULL,
            FOREIGN KEY (calibration_id) REFERENCES calibration (calibration_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_test_result (
            calibration_test_result_id INTEGER PRIMARY KEY,
            calibration_scope_id INTEGER NOT NULL,
            test_point_id INTEGER NOT NULL,
            result_status TEXT NULL,
            remarks TEXT NULL,
            created_at TEXT NOT NULL,
            standard_value TEXT NULL,
            average_value TEXT NULL,
            correction_value TEXT NULL,
            FOREIGN KEY (calibration_scope_id) REFERENCES calibration_scope (calibration_scope_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_result_value (
            calibration_result_value_id INTEGER PRIMARY KEY,
            calibration_test_result_id INTEGER NOT NULL,
            column_key VARCHAR(63) NOT NULL,
            value_source TEXT NOT NULL,
            data_type TEXT NOT NULL,
            numeric_value TEXT NULL,
            text_value TEXT NULL,
            unit VARCHAR(50) NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (calibration_test_result_id) REFERENCES calibration_test_result (calibration_test_result_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE users (
            id INTEGER PRIMARY KEY
        )
    ');
    $connection->unprepared('
        CREATE TABLE app_user (
            app_user_id INTEGER PRIMARY KEY,
            is_active INTEGER NOT NULL
        )
    ');
    $connection->unprepared('
        CREATE TABLE user_app_user_map (
            user_app_user_map_id INTEGER PRIMARY KEY,
            laravel_user_id INTEGER NOT NULL UNIQUE,
            app_user_id INTEGER NOT NULL UNIQUE,
            status VARCHAR(20) NOT NULL,
            created_at TEXT NOT NULL,
            inactivated_at TEXT NULL,
            FOREIGN KEY (laravel_user_id) REFERENCES users (id),
            FOREIGN KEY (app_user_id) REFERENCES app_user (app_user_id)
        )
    ');
    $connection->unprepared('
        CREATE TABLE calibration_external_document (
            document_id INTEGER PRIMARY KEY AUTOINCREMENT,
            calibration_id INTEGER NOT NULL,
            storage_uuid TEXT NOT NULL UNIQUE,
            storage_path TEXT NOT NULL,
            original_filename TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            byte_size INTEGER NOT NULL,
            sha256 TEXT NOT NULL,
            is_active INTEGER NOT NULL,
            uploaded_at TEXT NOT NULL,
            superseded_at TEXT NULL,
            uploaded_by_user_id INTEGER NULL,
            FOREIGN KEY (calibration_id) REFERENCES calibration (calibration_id)
        )
    ');

    Storage::fake('calibration_documents');
    Storage::fake('public');
    Storage::fake('local');

    return $connection;
}

function calibrationSessionE2eStart(): StartCalibrationSession
{
    app()->instance(CalibrationNumberAllocator::class, new FakeCalibrationNumberAllocator);

    return app(StartCalibrationSession::class);
}

function calibrationSessionE2eActor(Connection $connection): void
{
    $connection->table('users')->insert(['id' => 5]);
    $connection->table('app_user')->insert([
        'app_user_id' => 90,
        'is_active' => 1,
    ]);
    $connection->table('user_app_user_map')->insert([
        'user_app_user_map_id' => 1,
        'laravel_user_id' => 5,
        'app_user_id' => 90,
        'status' => 'active',
        'created_at' => '2026-10-22 07:00:00',
        'inactivated_at' => null,
    ]);
}

function calibrationSessionE2eProvider(Connection $connection): void
{
    $connection->table('calibration_provider')->insert([
        'calibration_provider_id' => 1,
        'provider_code' => 'INTERNAL_TEAM',
        'provider_name' => 'Internal Calibration Team',
        'provider_type' => 'internal',
        'created_at' => '2026-01-01 00:00:00',
    ]);
}

/**
 * @param  array<string, string>  $modes
 */
function calibrationSessionE2eInstrument(
    Connection $connection,
    bool $withLocation,
    array $modes,
    ?string $environmentMode,
): int {
    if ($withLocation) {
        $connection->table('location')->insert([
            'location_id' => 1,
            'location_code' => 'MLG',
            'location_name' => 'Malang',
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $connection->table('detail_location')->insert([
            'detail_location_id' => 1,
            'location_id' => 1,
            'detail_location_code' => 'LAB',
            'detail_location_name' => 'Laboratory',
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }

    $connection->table('instrument')->insert([
        'instrument_id' => 1,
        'instrument_name' => 'Gauge A',
        'serial_number' => 'SN-1',
        'detail_location_id' => $withLocation ? 1 : null,
        'created_at' => '2026-01-01 00:00:00',
    ]);
    $connection->table('instrument_type')->insert([
        'instrument_type_id' => 11,
        'instrument_id' => 1,
        'type_name' => 'Pressure Gauge',
        'brand' => 'Acme',
        'model' => 'G-1',
        'asset_number' => 'A-1',
        'max_capacity' => '100',
        'max_capacity_unit' => 'kPa',
        'resolution' => '0.1',
        'resolution_unit' => 'kPa',
        'calibration_interval_months' => 12,
        'created_at' => '2026-01-01 00:00:00',
    ]);

    foreach ($modes as $category => $mode) {
        $connection->table('instrument_type_requirement')->insert([
            'instrument_type_id' => 11,
            'requirement_category' => $category,
            'requirement_mode' => $mode,
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    if ($environmentMode !== null) {
        $connection->table('environment_parameter')->insert([
            'parameter_id' => 1,
            'parameter_code' => 'TEMP',
            'parameter_name' => 'Temperature',
            'unit' => 'C',
            'data_type' => 'numeric',
            'is_system' => 1,
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $connection->table('instrument_type_environment_parameter')->insert([
            'instrument_type_id' => 11,
            'parameter_id' => 1,
            'requirement_mode' => $environmentMode,
            'display_order' => 1,
        ]);
    }

    return 11;
}

function calibrationSessionE2eStandard(Connection $connection): void
{
    $connection->table('standard_master')->insert([
        'standard_id' => 1,
        'standard_code' => 'STD-1',
        'instrument_name' => 'Reference Mass',
        'type_specification' => 'Class F',
        'serial_number' => 'RM-1',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
    $connection->table('instrument_type_standard_assignment')->insert([
        'instrument_type_standard_assignment_id' => 1,
        'instrument_type_id' => 11,
        'standard_id' => 1,
        'standard_instrument_id' => null,
        'assignment_order' => 1,
        'standard_display_name' => 'Reference Mass',
        'default_range' => '0-100',
        'default_unit' => 'g',
    ]);
}

function calibrationSessionE2eActivity(Connection $connection): void
{
    $connection->table('calibration_activity_master')->insert([
        'activity_id' => 1,
        'activity_code' => 'ADJ',
        'activity_name' => 'Adjustment',
        'is_active' => 1,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

/**
 * @param  list<array<string, mixed>>  $columns
 * @param  list<array<string, mixed>>  $points
 * @param  list<array<string, mixed>>  $values
 */
function calibrationSessionE2eTable(
    Connection $connection,
    array $columns,
    array $points,
    array $values,
): void {
    $connection->table('test_profile')->insert([
        'test_profile_id' => 5,
        'instrument_type_id' => 11,
        'profile_name' => 'Mass',
        'display_title' => 'Mass Table',
        'description' => 'Frozen mass table',
        'archived_at' => null,
        'created_at' => '2026-01-01 00:00:00',
    ]);
    $connection->table('test_profile_version')->insert([
        'test_profile_version_id' => 7,
        'test_profile_id' => 5,
        'version_number' => 3,
        'status' => 'active',
        'profile_name' => 'Mass',
        'display_title' => 'Mass Table',
        'description' => 'Frozen mass table',
        'published_at' => '2026-01-01 00:00:00',
        'created_at' => '2026-01-01 00:00:00',
    ]);
    $connection->table('test_column')->insert($columns);
    $connection->table('test_point')->insert($points);

    if ($values !== []) {
        $connection->table('test_point_value')->insert($values);
    }
}

/**
 * @return array<string, mixed>
 */
function calibrationSessionE2eColumn(
    int $id,
    string $key,
    string $label,
    int $order,
    string $source,
    string $rule,
    string $dataType = 'numeric',
    ?string $unit = 'g',
    ?string $formulaCode = null,
    string $formulaInputs = '[]',
): array {
    return [
        'test_column_id' => $id,
        'test_profile_id' => 5,
        'test_profile_version_id' => 7,
        'column_key' => $key,
        'column_label' => $label,
        'column_order' => $order,
        'data_type' => $dataType,
        'value_source' => $source,
        'unit' => $unit,
        'display_scale' => 1,
        'formula_code' => $formulaCode,
        'formula_inputs' => $formulaInputs,
        'completion_rule' => $rule,
        'created_at' => '2026-01-01 00:00:00',
    ];
}

/**
 * @return array<string, mixed>
 */
function calibrationSessionE2ePoint(int $id, int $order, string $nominal): array
{
    return [
        'test_point_id' => $id,
        'test_profile_id' => 5,
        'test_profile_version_id' => 7,
        'point_order' => $order,
        'nominal_value' => $nominal,
        'unit' => 'g',
        'tolerance_plus' => '0.1',
        'tolerance_minus' => '0.1',
        'description' => 'Point '.$order,
        'created_at' => '2026-01-01 00:00:00',
    ];
}

/**
 * @return array<string, string>
 */
function calibrationSessionE2eRequiredModes(): array
{
    return [
        InstrumentTypeRequirement::CATEGORY_STANDARDS => InstrumentTypeRequirement::MODE_REQUIRED,
        InstrumentTypeRequirement::CATEGORY_ACTIVITY => InstrumentTypeRequirement::MODE_REQUIRED,
        InstrumentTypeRequirement::CATEGORY_ENVIRONMENT => InstrumentTypeRequirement::MODE_REQUIRED,
        InstrumentTypeRequirement::CATEGORY_TEST_PROFILE => InstrumentTypeRequirement::MODE_REQUIRED,
    ];
}

function calibrationSessionE2eFullTable(Connection $connection): void
{
    calibrationSessionE2eTable($connection, [
        calibrationSessionE2eColumn(1, 'nominal', 'Nominal', 1, 'template', 'required'),
        calibrationSessionE2eColumn(2, 'reading', 'Reading', 2, 'engineer', 'required'),
        calibrationSessionE2eColumn(3, 'deviation', 'Deviation', 3, 'calculated', 'required', formulaCode: 'deviation', formulaInputs: '["reading"]'),
        calibrationSessionE2eColumn(4, 'comment', 'Comment', 4, 'engineer', 'optional', 'text', null),
    ], [
        calibrationSessionE2ePoint(11, 1, '100.5'),
        calibrationSessionE2ePoint(12, 2, '200.5'),
    ], [
        [
            'test_point_value_id' => 1,
            'test_profile_id' => 5,
            'test_profile_version_id' => 7,
            'test_point_id' => 11,
            'test_column_id' => 1,
            'numeric_value' => '100.5',
            'text_value' => null,
            'unit' => 'g',
            'created_at' => '2026-01-01 00:00:00',
        ],
        [
            'test_point_value_id' => 2,
            'test_profile_id' => 5,
            'test_profile_version_id' => 7,
            'test_point_id' => 12,
            'test_column_id' => 1,
            'numeric_value' => '200.5',
            'text_value' => null,
            'unit' => 'g',
            'created_at' => '2026-01-01 00:00:00',
        ],
    ]);
}

function calibrationSessionE2eSupport(Connection $connection, int $calibrationId, bool $recordEnvironment): void
{
    app(RecordCalibrationStandardUsage::class)->add($calibrationId, 1, null, null, 'working standard', null, $connection);
    app(RecordCalibrationActivity::class)->add($calibrationId, 1, 'Adjusted zero', 'pass', $connection);
    app(ConfirmEnvironmentConfiguration::class)->confirm($calibrationId, 'environment checked', 5, $connection);

    if ($recordEnvironment) {
        app(RecordCalibrationEnvironmentMeasurement::class)->record($calibrationId, 1, '23.5', $connection);
    }
}

/**
 * @return array<string, mixed>
 */
function calibrationSessionE2eRow(Connection $connection, int $calibrationId): array
{
    return (array) $connection->table('calibration')->where('calibration_id', $calibrationId)->first();
}

/**
 * @return array<string, mixed>
 */
function calibrationSessionE2eFrozenSnapshot(Connection $connection, int $calibrationId): array
{
    $raw = $connection->table('calibration_scope')->where('calibration_id', $calibrationId)->value('test_table_snapshot');
    $decoded = json_decode((string) $raw, true);

    expect($decoded)->toBeArray();

    return $decoded;
}

/**
 * @return list<array<string, mixed>>
 */
function calibrationSessionE2eCells(Connection $connection): array
{
    return $connection->table('calibration_result_value')
        ->orderBy('calibration_result_value_id')
        ->get()
        ->map(fn (object $row): array => (array) $row)
        ->all();
}

function calibrationSessionE2eActive(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1';
}

it('completes a fully configured internal session through the existing services', function () {
    $connection = calibrationSessionE2eConnection();
    calibrationSessionE2eProvider($connection);
    calibrationSessionE2eActor($connection);
    calibrationSessionE2eInstrument($connection, true, calibrationSessionE2eRequiredModes(), InstrumentTypeRequirement::MODE_REQUIRED);
    calibrationSessionE2eStandard($connection);
    calibrationSessionE2eActivity($connection);
    calibrationSessionE2eFullTable($connection);

    $session = calibrationSessionE2eStart()->start(11, Calibration::METHOD_INTERNAL, $connection);
    $calibrationId = (int) $session->calibration_id;

    expect($session->session_status)->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($session->snapshot_instrument_name)->toBe('Gauge A')
        ->and($session->snapshot_instrument_type_name)->toBe('Pressure Gauge')
        ->and($session->snapshot_location_id)->toBe(1)
        ->and($session->snapshot_detail_location_id)->toBe(1)
        ->and($session->snapshot_location_code)->toBe('MLG')
        ->and($session->snapshot_location_name)->toBe('Malang')
        ->and($session->snapshot_detail_location_code)->toBe('LAB')
        ->and($session->snapshot_detail_location_name)->toBe('Laboratory')
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', $calibrationId)->count())->toBe(4)
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', $calibrationId)->pluck('frozen_mode')->unique()->all())->toBe([InstrumentTypeRequirement::MODE_REQUIRED])
        ->and($connection->table('calibration_requirement_snapshot')->whereNotNull('resolved_mode')->count())->toBe(0);

    calibrationSessionE2eSupport($connection, $calibrationId, true);

    expect($connection->table('calibration_standard_usage')->where('calibration_id', $calibrationId)->first())
        ->toMatchArray([
            'standard_id' => 1,
            'standard_range' => '0-100',
            'standard_unit' => 'g',
            'snapshot_standard_code' => 'STD-1',
            'snapshot_instrument_name' => 'Reference Mass',
            'snapshot_type_specification' => 'Class F',
            'snapshot_serial_number' => 'RM-1',
        ])
        ->and($connection->table('calibration_activity')->where('calibration_id', $calibrationId)->first())
        ->toMatchArray([
            'activity_id' => 1,
            'activity_name' => 'Adjustment',
            'activity_type' => 'Adjustment',
        ])
        ->and($connection->table('calibration_environment_value')->where('calibration_id', $calibrationId)->first())
        ->toMatchArray([
            'parameter_id' => 1,
            'numeric_value' => '23.5',
            'text_value' => null,
        ]);

    app(PinCalibrationTestTables::class)->pin($calibrationId, $connection);
    $snapshot = calibrationSessionE2eFrozenSnapshot($connection, $calibrationId);
    $scopeId = (int) $connection->table('calibration_scope')->where('calibration_id', $calibrationId)->value('calibration_scope_id');

    expect($connection->table('calibration_scope')->where('calibration_id', $calibrationId)->count())->toBe(1)
        ->and($snapshot['schema_version'])->toBe(2)
        ->and($snapshot['test_profile_id'])->toBe(5)
        ->and($snapshot['test_profile_version_id'])->toBe(7)
        ->and($snapshot['rows'])->toHaveCount(2)
        ->and($snapshot['columns'])->toHaveCount(4)
        ->and($snapshot['rows'][0]['template_values']['nominal']['numeric_value'])->toBe('100.5')
        ->and($snapshot['rows'][1]['template_values']['nominal']['numeric_value'])->toBe('200.5');

    app(MaterializeCalibrationTestTemplateValues::class)->materialize($calibrationId, $connection);
    $parentsAfterTemplate = $connection->table('calibration_test_result')->count();

    expect($connection->table('calibration_result_value')->where('column_key', 'nominal')->orderBy('calibration_result_value_id')->get())
        ->toHaveCount(2)
        ->and((array) $connection->table('calibration_result_value')->where('column_key', 'nominal')->orderBy('calibration_result_value_id')->first())
        ->toMatchArray([
            'value_source' => 'template',
            'numeric_value' => '100.5',
            'unit' => 'g',
        ])
        ->and($parentsAfterTemplate)->toBe(2);

    $results = app(RecordCalibrationTestResult::class);

    foreach ([11, 12] as $pointId) {
        $results->record($calibrationId, $scopeId, $pointId, 'reading', '10', $connection);
        $results->record($calibrationId, $scopeId, $pointId, 'deviation', '77', $connection);
    }

    expect($connection->table('calibration_test_result')->count())->toBe($parentsAfterTemplate)
        ->and($connection->table('calibration_result_value')->where('column_key', 'reading')->pluck('value_source')->unique()->all())->toBe(['engineer'])
        ->and($connection->table('calibration_result_value')->where('column_key', 'deviation')->pluck('numeric_value')->unique()->all())->toBe(['77'])
        ->and($connection->table('calibration_result_value')->where('column_key', 'deviation')->pluck('value_source')->unique()->all())->toBe(['engineer'])
        ->and($connection->table('calibration_result_value')->where('column_key', 'nominal')->pluck('numeric_value')->all())->toBe(['100.5', '200.5'])
        ->and($connection->table('calibration_result_value')->where('column_key', 'nominal')->pluck('value_source')->unique()->all())->toBe(['template'])
        ->and($connection->table('calibration_result_value')->where('column_key', 'comment')->count())->toBe(0)
        ->and($connection->table('calibration_test_result')->whereNotNull('standard_value')->count())->toBe(0)
        ->and($connection->table('calibration_test_result')->whereNotNull('average_value')->count())->toBe(0)
        ->and($connection->table('calibration_test_result')->whereNotNull('correction_value')->count())->toBe(0);

    app(RecordCalibrationScopeRemarks::class)->record($calibrationId, $scopeId, 'bench note', $connection);

    expect($connection->table('calibration_scope')->where('calibration_scope_id', $scopeId)->value('remarks'))->toBe('bench note')
        ->and($connection->table('calibration_test_result')->whereNotNull('remarks')->count())->toBe(0)
        ->and($connection->table('calibration_test_result')->count())->toBe(2);

    $cells = calibrationSessionE2eCells($connection);
    $identity = calibrationSessionE2eRow($connection, $calibrationId);
    $frozenSnapshot = (string) $connection->table('calibration_scope')->where('calibration_scope_id', $scopeId)->value('test_table_snapshot');

    app(CompleteCalibrationSession::class)->complete($calibrationId, '2026-10-22', '2027-10-22', $connection);

    $completed = calibrationSessionE2eRow($connection, $calibrationId);

    expect($completed['session_status'])->toBe(Calibration::STATUS_COMPLETED)
        ->and($completed['completed_at'])->not->toBeNull()
        ->and($completed['calibration_date'])->toBe('2026-10-22')
        ->and($completed['calibration_due'])->toBe('2027-10-22')
        ->and(calibrationSessionE2eCells($connection))->toBe($cells)
        ->and($connection->table('calibration_scope')->where('calibration_scope_id', $scopeId)->value('remarks'))->toBe('bench note')
        ->and((string) $connection->table('calibration_scope')->where('calibration_scope_id', $scopeId)->value('test_table_snapshot'))->toBe($frozenSnapshot)
        ->and($completed['snapshot_instrument_name'])->toBe($identity['snapshot_instrument_name'])
        ->and($completed['snapshot_instrument_type_name'])->toBe($identity['snapshot_instrument_type_name'])
        ->and($completed['snapshot_location_id'])->toBe($identity['snapshot_location_id'])
        ->and($completed['snapshot_detail_location_id'])->toBe($identity['snapshot_detail_location_id'])
        ->and($completed['snapshot_location_code'])->toBe('MLG')
        ->and($completed['snapshot_location_name'])->toBe('Malang')
        ->and($completed['snapshot_detail_location_code'])->toBe('LAB')
        ->and($completed['snapshot_detail_location_name'])->toBe('Laboratory')
        ->and($connection->table('calibration_test_result')->whereNotNull('standard_value')->count())->toBe(0)
        ->and($connection->table('calibration_test_result')->whereNotNull('average_value')->count())->toBe(0)
        ->and($connection->table('calibration_test_result')->whereNotNull('correction_value')->count())->toBe(0)
        ->and($connection->getName())->toBe(CALIBRATION_SESSION_E2E_CONNECTION)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);

    expect(fn () => app(RecordCalibrationScopeRemarks::class)->record($calibrationId, $scopeId, 'later', $connection))
        ->toThrow(RecordCalibrationScopeRemarksException::class, 'Only an in-progress calibration session can record scope remarks.');
    expect(fn () => app(RecordCalibrationTestResult::class)->record($calibrationId, $scopeId, 11, 'reading', '11', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'Only an in-progress calibration session can record test results.');

    expect($connection->table('calibration_scope')->where('calibration_scope_id', $scopeId)->value('remarks'))->toBe('bench note')
        ->and(calibrationSessionE2eCells($connection))->toBe($cells)
        ->and(Storage::disk('public')->allFiles())->toBeEmpty()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('completes an external session after replacing the vendor PDF', function () {
    $connection = calibrationSessionE2eConnection();
    calibrationSessionE2eProvider($connection);
    calibrationSessionE2eActor($connection);
    calibrationSessionE2eInstrument($connection, true, [], null);

    $session = calibrationSessionE2eStart()->start(11, Calibration::METHOD_EXTERNAL, $connection);
    $calibrationId = (int) $session->calibration_id;

    expect($session->session_status)->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($session->calibration_method)->toBe(Calibration::METHOD_EXTERNAL)
        ->and($session->calibration_provider_id)->toBeNull()
        ->and($session->snapshot_instrument_name)->toBe('Gauge A')
        ->and($session->snapshot_location_code)->toBe('MLG')
        ->and($session->snapshot_detail_location_code)->toBe('LAB')
        ->and($connection->table('calibration_standard_usage')->count())->toBe(0)
        ->and($connection->table('calibration_activity')->count())->toBe(0)
        ->and($connection->table('calibration_scope')->count())->toBe(0)
        ->and($connection->table('calibration_requirement_snapshot')->whereNotNull('configuration_confirmed_at')->count())->toBe(0);

    expect(fn () => app(PinCalibrationTestTables::class)->pin($calibrationId, $connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'Test tables can be pinned only for an internal calibration session.');
    expect(fn () => app(RecordCalibrationStandardUsage::class)->add($calibrationId, 1, null, null, null, null, $connection))
        ->toThrow(RecordCalibrationStandardUsageException::class, 'Standard usage can be recorded only for an internal calibration session.');
    expect(fn () => app(RecordCalibrationTestResult::class)->record($calibrationId, 1, 11, 'reading', '10', $connection))
        ->toThrow(RecordCalibrationTestResultException::class, 'Test results can be recorded only for an internal calibration session.');

    $documents = app(StoreExternalCalibrationDocument::class);
    $documents->store($calibrationId, 'vendor-a.pdf', "%PDF-1.4\nfirst\n", 5, $connection);
    $documents->store($calibrationId, 'vendor-b.pdf', "%PDF-1.4\nsecond\n", 5, $connection);

    $rows = $connection->table('calibration_external_document')->orderBy('document_id')->get();
    $active = $rows->filter(fn (object $row): bool => calibrationSessionE2eActive($row->is_active));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->superseded_at)->not->toBeNull()
        ->and(calibrationSessionE2eActive($rows[0]->is_active))->toBeFalse()
        ->and($active)->toHaveCount(1)
        ->and($active->first()->original_filename)->toBe('vendor-b.pdf')
        ->and(Storage::disk('calibration_documents')->allFiles())->toHaveCount(2);

    app(CompleteCalibrationSession::class)->complete($calibrationId, '2026-10-22', '2027-10-22', $connection);

    $completed = calibrationSessionE2eRow($connection, $calibrationId);
    $remaining = $connection->table('calibration_external_document')->orderBy('document_id')->get();

    expect($completed['session_status'])->toBe(Calibration::STATUS_COMPLETED)
        ->and($completed['completed_at'])->not->toBeNull()
        ->and($completed['calibration_date'])->toBe('2026-10-22')
        ->and($completed['calibration_due'])->toBe('2027-10-22')
        ->and($completed['snapshot_location_id'])->toBe(1)
        ->and($completed['snapshot_detail_location_id'])->toBe(1)
        ->and($completed['snapshot_location_code'])->toBe('MLG')
        ->and($completed['snapshot_location_name'])->toBe('Malang')
        ->and($completed['snapshot_detail_location_code'])->toBe('LAB')
        ->and($completed['snapshot_detail_location_name'])->toBe('Laboratory')
        ->and($remaining->filter(fn (object $row): bool => calibrationSessionE2eActive($row->is_active)))->toHaveCount(1)
        ->and($connection->table('calibration_standard_usage')->count())->toBe(0)
        ->and($connection->table('calibration_activity')->count())->toBe(0)
        ->and($connection->table('calibration_scope')->count())->toBe(0)
        ->and($connection->table('calibration_environment_value')->count())->toBe(0)
        ->and($connection->getName())->toBe(CALIBRATION_SESSION_E2E_CONNECTION)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBeEmpty()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();

    expect(fn () => app(StoreExternalCalibrationDocument::class)->store($calibrationId, 'vendor-c.pdf', "%PDF-1.4\nthird\n", 5, $connection))
        ->toThrow(StoreExternalCalibrationDocumentException::class, 'Only an in-progress calibration session can store a vendor PDF.');

    $documents = $connection->table('calibration_external_document')->orderBy('document_id')->get();

    expect($connection->table('calibration')->where('calibration_id', $calibrationId)->value('session_status'))->toBe(Calibration::STATUS_COMPLETED)
        ->and($documents)->toHaveCount(2)
        ->and($documents->filter(fn (object $row): bool => $row->original_filename === 'vendor-b.pdf' && calibrationSessionE2eActive($row->is_active)))->toHaveCount(1)
        ->and(Storage::disk('calibration_documents')->allFiles())->toHaveCount(2)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects a start when the instrument has no location', function () {
    $connection = calibrationSessionE2eConnection();
    calibrationSessionE2eInstrument($connection, false, calibrationSessionE2eRequiredModes(), InstrumentTypeRequirement::MODE_REQUIRED);
    $allocator = new FakeCalibrationNumberAllocator;
    app()->instance(CalibrationNumberAllocator::class, $allocator);

    expect(fn () => app(StartCalibrationSession::class)->start(11, Calibration::METHOD_INTERNAL, $connection))
        ->toThrow(StartCalibrationException::class, 'The instrument location is incomplete.');

    expect($connection->table('calibration')->count())->toBe(0)
        ->and($connection->table('calibration_requirement_snapshot')->count())->toBe(0)
        ->and($connection->table('calibration_environment_parameter_snapshot')->count())->toBe(0)
        ->and($connection->table('calibration_scope')->count())->toBe(0)
        ->and($connection->table('calibration_result_value')->count())->toBe(0)
        ->and($allocator->calls)->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects pinning when a frozen column is not configured', function () {
    $connection = calibrationSessionE2eConnection();
    calibrationSessionE2eProvider($connection);
    calibrationSessionE2eActor($connection);
    calibrationSessionE2eInstrument($connection, true, calibrationSessionE2eRequiredModes(), InstrumentTypeRequirement::MODE_REQUIRED);
    calibrationSessionE2eStandard($connection);
    calibrationSessionE2eActivity($connection);
    calibrationSessionE2eTable($connection, [
        calibrationSessionE2eColumn(1, 'reading', 'Reading', 1, 'engineer', 'required'),
        calibrationSessionE2eColumn(2, 'spare', 'Spare', 2, 'engineer', 'not_configured'),
    ], [
        calibrationSessionE2ePoint(11, 1, '100'),
    ], []);

    $session = calibrationSessionE2eStart()->start(11, Calibration::METHOD_INTERNAL, $connection);
    $calibrationId = (int) $session->calibration_id;
    calibrationSessionE2eSupport($connection, $calibrationId, true);

    expect(fn () => app(PinCalibrationTestTables::class)->pin($calibrationId, $connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'A not-configured test component cannot be pinned.');

    $after = calibrationSessionE2eRow($connection, $calibrationId);

    expect($after['session_status'])->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($after['calibration_date'])->toBeNull()
        ->and($after['calibration_due'])->toBeNull()
        ->and($after['completed_at'])->toBeNull()
        ->and($connection->table('calibration_scope')->where('calibration_id', $calibrationId)->count())->toBe(0)
        ->and($connection->table('calibration_test_result')->count())->toBe(0)
        ->and($connection->table('calibration_result_value')->count())->toBe(0)
        ->and($connection->table('test_column')->where('column_key', 'spare')->value('completion_rule'))->toBe('not_configured')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects pinning when a required template value is missing', function () {
    $connection = calibrationSessionE2eConnection();
    calibrationSessionE2eProvider($connection);
    calibrationSessionE2eActor($connection);
    calibrationSessionE2eInstrument($connection, true, calibrationSessionE2eRequiredModes(), InstrumentTypeRequirement::MODE_REQUIRED);
    calibrationSessionE2eStandard($connection);
    calibrationSessionE2eActivity($connection);
    calibrationSessionE2eTable($connection, [
        calibrationSessionE2eColumn(1, 'nominal', 'Nominal', 1, 'template', 'required'),
        calibrationSessionE2eColumn(2, 'reading', 'Reading', 2, 'engineer', 'required'),
    ], [
        calibrationSessionE2ePoint(11, 1, '100'),
    ], []);

    $session = calibrationSessionE2eStart()->start(11, Calibration::METHOD_INTERNAL, $connection);
    $calibrationId = (int) $session->calibration_id;
    calibrationSessionE2eSupport($connection, $calibrationId, true);

    expect(fn () => app(PinCalibrationTestTables::class)->pin($calibrationId, $connection))
        ->toThrow(PinCalibrationTestTablesException::class, 'A required template value is missing.');

    $after = calibrationSessionE2eRow($connection, $calibrationId);

    expect($after['session_status'])->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($after['calibration_date'])->toBeNull()
        ->and($after['calibration_due'])->toBeNull()
        ->and($after['completed_at'])->toBeNull()
        ->and($connection->table('calibration_scope')->where('calibration_id', $calibrationId)->count())->toBe(0)
        ->and($connection->table('calibration_test_result')->count())->toBe(0)
        ->and($connection->table('calibration_result_value')->count())->toBe(0)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('keeps recorded evidence when complete is rejected for an unpinned test profile', function () {
    $connection = calibrationSessionE2eConnection();
    calibrationSessionE2eProvider($connection);
    calibrationSessionE2eActor($connection);
    calibrationSessionE2eInstrument($connection, true, calibrationSessionE2eRequiredModes(), InstrumentTypeRequirement::MODE_REQUIRED);
    calibrationSessionE2eStandard($connection);
    calibrationSessionE2eActivity($connection);
    calibrationSessionE2eFullTable($connection);

    $session = calibrationSessionE2eStart()->start(11, Calibration::METHOD_INTERNAL, $connection);
    $calibrationId = (int) $session->calibration_id;
    calibrationSessionE2eSupport($connection, $calibrationId, true);

    expect(fn () => app(CompleteCalibrationSession::class)->complete($calibrationId, '2026-10-22', '2027-10-22', $connection))
        ->toThrow(CompleteCalibrationSessionException::class, 'Required test profile must be pinned.');

    $after = calibrationSessionE2eRow($connection, $calibrationId);

    expect($after['session_status'])->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and($after['calibration_date'])->toBeNull()
        ->and($after['calibration_due'])->toBeNull()
        ->and($after['completed_at'])->toBeNull()
        ->and($after['snapshot_location_code'])->toBe('MLG')
        ->and($after['snapshot_detail_location_name'])->toBe('Laboratory')
        ->and($connection->table('calibration_standard_usage')->where('calibration_id', $calibrationId)->count())->toBe(1)
        ->and($connection->table('calibration_activity')->where('calibration_id', $calibrationId)->count())->toBe(1)
        ->and($connection->table('calibration_environment_value')->where('calibration_id', $calibrationId)->count())->toBe(1)
        ->and($connection->table('calibration_scope')->count())->toBe(0)
        ->and($connection->table('calibration_test_result')->count())->toBe(0)
        ->and($connection->table('calibration_result_value')->count())->toBe(0)
        ->and($connection->table('test_point_value')->orderBy('test_point_id')->pluck('numeric_value')->all())->toBe(['100.5', '200.5'])
        ->and($connection->table('test_profile_version')->where('test_profile_version_id', 7)->value('status'))->toBe('active')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('completes optional categories without standards, activities, or test tables', function () {
    $connection = calibrationSessionE2eConnection();
    calibrationSessionE2eProvider($connection);
    calibrationSessionE2eActor($connection);
    $optional = [
        InstrumentTypeRequirement::CATEGORY_STANDARDS => InstrumentTypeRequirement::MODE_OPTIONAL,
        InstrumentTypeRequirement::CATEGORY_ACTIVITY => InstrumentTypeRequirement::MODE_OPTIONAL,
        InstrumentTypeRequirement::CATEGORY_ENVIRONMENT => InstrumentTypeRequirement::MODE_OPTIONAL,
        InstrumentTypeRequirement::CATEGORY_TEST_PROFILE => InstrumentTypeRequirement::MODE_OPTIONAL,
    ];
    calibrationSessionE2eInstrument($connection, true, $optional, InstrumentTypeRequirement::MODE_OPTIONAL);

    $session = calibrationSessionE2eStart()->start(11, Calibration::METHOD_INTERNAL, $connection);
    $calibrationId = (int) $session->calibration_id;

    expect($connection->table('calibration_requirement_snapshot')->where('calibration_id', $calibrationId)->pluck('frozen_mode')->unique()->all())->toBe([InstrumentTypeRequirement::MODE_OPTIONAL]);

    app(ConfirmEnvironmentConfiguration::class)->confirm($calibrationId, 'optional environment checked', 5, $connection);
    app(CompleteCalibrationSession::class)->complete($calibrationId, '2026-10-22', '2027-10-22', $connection);

    $completed = calibrationSessionE2eRow($connection, $calibrationId);

    expect($completed['session_status'])->toBe(Calibration::STATUS_COMPLETED)
        ->and($completed['calibration_date'])->toBe('2026-10-22')
        ->and($completed['calibration_due'])->toBe('2027-10-22')
        ->and($connection->table('calibration_standard_usage')->count())->toBe(0)
        ->and($connection->table('calibration_activity')->count())->toBe(0)
        ->and($connection->table('calibration_scope')->count())->toBe(0)
        ->and($connection->table('calibration_environment_value')->count())->toBe(0)
        ->and($connection->table('calibration_requirement_snapshot')->where('calibration_id', $calibrationId)->pluck('frozen_mode')->unique()->all())->toBe([InstrumentTypeRequirement::MODE_OPTIONAL])
        ->and($connection->table('calibration_requirement_snapshot')->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)->value('configuration_confirmed_at'))->not->toBeNull()
        ->and($connection->getName())->toBe(CALIBRATION_SESSION_E2E_CONNECTION)
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});
