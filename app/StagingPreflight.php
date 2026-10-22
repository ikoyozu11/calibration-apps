<?php

namespace App;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StagingPreflight
{
    public const REQUIRED_DATABASE = 'calibration_staging_db';

    /**
     * Read-only inspection of the active Laravel database.
     *
     * @return array{
     *     database: string,
     *     driver: string,
     *     required_database: string,
     *     database_ok: bool,
     *     schema_ok: bool,
     *     ok: bool,
     *     schema: array<string, bool>
     * }
     */
    public function inspect(): array
    {
        $database = $this->currentDatabase();
        $schema = $this->schema();
        $databaseOk = $database === self::REQUIRED_DATABASE;
        $schemaOk = ! in_array(false, $schema, true);

        return [
            'database' => $database,
            'driver' => DB::getDriverName(),
            'required_database' => self::REQUIRED_DATABASE,
            'database_ok' => $databaseOk,
            'schema_ok' => $schemaOk,
            'ok' => $databaseOk && $schemaOk,
            'schema' => $schema,
        ];
    }

    public function currentDatabase(): string
    {
        if (DB::getDriverName() === 'pgsql') {
            $name = DB::selectOne('select current_database() as name')?->name;

            return is_string($name) ? $name : '';
        }

        $connection = config('database.default');
        $database = config('database.connections.'.$connection.'.database');

        return is_string($database) ? $database : '';
    }

    /**
     * @return array<string, bool>
     */
    private function schema(): array
    {
        return [
            'calibration.session_status' => Schema::hasColumn('calibration', 'session_status'),
            'calibration.instrument_type_id' => Schema::hasColumn('calibration', 'instrument_type_id'),
            'instrument_type.calibration_interval_months' => Schema::hasColumn('instrument_type', 'calibration_interval_months'),
            'test_profile_version' => Schema::hasTable('test_profile_version'),
            'calibration_result_value' => Schema::hasTable('calibration_result_value'),
            'calibration_scope.test_table_snapshot' => Schema::hasColumn('calibration_scope', 'test_table_snapshot'),
            'instrument_type_standard_assignment' => Schema::hasTable('instrument_type_standard_assignment'),
            'instrument_type_standard_assignment.standard_display_name' => Schema::hasColumn('instrument_type_standard_assignment', 'standard_display_name'),
            'standard_master' => Schema::hasTable('standard_master'),
            'instrument_type_standard_assignment.standard_id' => Schema::hasColumn('instrument_type_standard_assignment', 'standard_id'),
            'calibration_activity_master' => Schema::hasTable('calibration_activity_master'),
        ];
    }
}
