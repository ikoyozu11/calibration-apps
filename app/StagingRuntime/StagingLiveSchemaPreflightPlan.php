<?php

namespace App\StagingRuntime;

use App\CalibrationSessions\CalibrationHttpConnection;
use App\StagingSqlDeployment\ApprovedStagingSqlArtifacts;

/**
 * Prepared read-only live inspect for calibration_staging_db.
 *
 * This class only describes the future inspect. It never opens a live
 * connection, never runs current_database(), and must not be executed
 * while Layer 2 is off.
 * Do not use the legacy staging preflight for PostgreSQL.
 */
final class StagingLiveSchemaPreflightPlan
{
    public const TARGET_DATABASE = ApprovedStagingSqlArtifacts::APPROVED_DATABASE;

    public const FORBIDDEN_DATABASE = ApprovedStagingSqlArtifacts::FORBIDDEN_DATABASE_PRODUCTION;

    /**
     * Planned read-only statements. Not executed by this class.
     *
     * @return list<string>
     */
    public static function plannedStatements(): array
    {
        return [
            'select current_database() as name',
            "select current_database() = '".self::TARGET_DATABASE."' as target_ok",
            "select current_database() = '".self::FORBIDDEN_DATABASE."' as production_hit",
            "select to_regclass('public.users') is not null as has_users",
            "select to_regclass('public.roles') is not null as has_roles",
            "select to_regclass('public.user_roles') is not null as has_user_roles",
            "select to_regclass('public.app_user') is not null as has_app_user",
            "select to_regclass('public.user_app_user_map') is not null as has_user_app_user_map",
            "select to_regclass('public.calibration') is not null as has_calibration",
            "select to_regclass('public.standard_master') is not null as has_standard_master",
            "select to_regclass('public.calibration_activity_master') is not null as has_activity_master",
            "select to_regclass('public.test_point') is not null as has_test_point",
            "select to_regclass('public.certificate') is not null as has_certificate",
            "select exists (select 1 from information_schema.columns where table_schema = 'public' and table_name = 'calibration' and column_name = 'started_by_app_user_id') as has_started_by",
            "select exists (select 1 from information_schema.columns where table_schema = 'public' and table_name = 'user_app_user_map' and column_name = 'laravel_user_id') as has_map_laravel_user_id",
            "select exists (select 1 from information_schema.columns where table_schema = 'public' and table_name = 'app_user' and column_name = 'app_user_id') as has_app_user_id",
        ];
    }

    /**
     * Exact read-only inspect sequence. Do not run until Layer 2 is approved.
     *
     * @return list<string>
     */
    public static function commands(): array
    {
        return [
            'psql -h 127.0.0.1 -p 5432 -d calibration_staging_db --set=ON_ERROR_STOP=1 -c "select current_database() as name"',
            "psql -h 127.0.0.1 -p 5432 -d calibration_staging_db --set=ON_ERROR_STOP=1 -c \"select current_database() = 'calibration_staging_db' as target_ok\"",
            "psql -h 127.0.0.1 -p 5432 -d calibration_staging_db --set=ON_ERROR_STOP=1 -c \"select current_database() = 'calibration_db' as production_hit\"",
            "psql -h 127.0.0.1 -p 5432 -d calibration_staging_db --set=ON_ERROR_STOP=1 -c \"select to_regclass('public.users') is not null as has_users, to_regclass('public.roles') is not null as has_roles, to_regclass('public.user_roles') is not null as has_user_roles, to_regclass('public.app_user') is not null as has_app_user, to_regclass('public.user_app_user_map') is not null as has_map\"",
            "psql -h 127.0.0.1 -p 5432 -d calibration_staging_db --set=ON_ERROR_STOP=1 -c \"select to_regclass('public.calibration') is not null as has_calibration, to_regclass('public.standard_master') is not null as has_standard_master, to_regclass('public.calibration_activity_master') is not null as has_activity_master, to_regclass('public.test_point') is not null as has_test_point, to_regclass('public.certificate') is not null as has_certificate\"",
            "psql -h 127.0.0.1 -p 5432 -d calibration_staging_db --set=ON_ERROR_STOP=1 -c \"select exists (select 1 from information_schema.columns where table_schema = 'public' and table_name = 'calibration' and column_name = 'started_by_app_user_id') as has_started_by\"",
            "psql -h 127.0.0.1 -p 5432 -d calibration_staging_db --set=ON_ERROR_STOP=1 -c \"select exists (select 1 from information_schema.columns where table_schema = 'public' and table_name = 'user_app_user_map' and column_name = 'laravel_user_id') as has_map_laravel_user_id\"",
        ];
    }

    /**
     * @return array{
     *     executable: false,
     *     layer2_activation: bool,
     *     target_database: string,
     *     forbidden_database: string,
     *     current_database_assertion: string,
     *     required_relations: list<string>,
     *     auth_mapping: list<string>,
     *     approved_sql: list<string>,
     *     pending_sql: list<array{relative_path: string, classification: string, reason: string}>,
     *     planned_statements: list<string>,
     *     commands: list<string>
     * }
     */
    public function describe(): array
    {
        return [
            'executable' => false,
            'layer2_activation' => CalibrationHttpConnection::STAGING_HTTP_ACTIVATION,
            'target_database' => self::TARGET_DATABASE,
            'forbidden_database' => self::FORBIDDEN_DATABASE,
            'current_database_assertion' => "current_database() = '".self::TARGET_DATABASE."'",
            'required_relations' => StagingActivationPreflight::requiredLiveRelations(),
            'auth_mapping' => [
                'users.id is Laravel identity',
                'app_user.app_user_id is domain actor',
                'user_app_user_map.laravel_user_id -> app_user_id',
                'never treat auth()->id() as app_user_id',
            ],
            'approved_sql' => array_column(ApprovedStagingSqlArtifacts::ordered(), 'relative_path'),
            'pending_sql' => StagingActivationPreflight::pendingSql(),
            'planned_statements' => self::plannedStatements(),
            'commands' => self::commands(),
        ];
    }

    public function executable(): bool
    {
        return false;
    }
}
