<?php

namespace App\StagingRuntime;

use App\CalibrationSessions\CalibrationHttpConnection;
use App\Database\DestructiveCommandGuard;
use App\StagingSqlDeployment\ApprovedStagingSqlArtifacts;
use Illuminate\Contracts\Config\Repository;

/**
 * Read-only activation readiness inspect.
 *
 * Uses configuration metadata and on-disk SQL files only. Never opens PDO,
 * never calls current_database(), and never deploys SQL.
 */
final class StagingActivationPreflight
{
    /**
     * @var list<string>
     */
    public const BROWSER_SMOKE_STEPS = [
        'Login',
        'Instruments',
        'Start',
        'Record',
        'Complete',
        'History',
        'Certificate/PDF',
    ];

    /**
     * SQL that still needs a separate approval before any staging apply.
     *
     * @return list<array{relative_path: string, classification: string, reason: string}>
     */
    public static function pendingSql(): array
    {
        return [
            [
                'relative_path' => 'database/sql/laravel_users_staging.sql',
                'classification' => 'baseline_or_approval',
                'reason' => 'Create public.users only if absent. Do not rerun.',
            ],
            [
                'relative_path' => 'database/sql/user_app_user_map.sql',
                'classification' => 'baseline_or_approval',
                'reason' => 'Map users.id to app_user.app_user_id if absent. Do not rerun.',
            ],
            [
                'relative_path' => 'database/sql/calibration_session_foundation.sql',
                'classification' => 'requires_approval',
                'reason' => 'Session foundation tables if missing from staging.',
            ],
            [
                'relative_path' => 'database/sql/calibration_session_crud_foundation.sql',
                'classification' => 'requires_approval',
                'reason' => 'CRUD, environment, T/RH dual-write. K-01 G-01 trigger still missing.',
            ],
            [
                'relative_path' => 'database/sql/standard_master.sql',
                'classification' => 'requires_approval',
                'reason' => 'Standard master for Instruments and session pinning.',
            ],
            [
                'relative_path' => 'database/sql/calibration_activity_master.sql',
                'classification' => 'requires_approval',
                'reason' => 'Activity master for session record/complete.',
            ],
            [
                'relative_path' => 'database/sql/instrument_type_standard_assignment.sql',
                'classification' => 'requires_approval',
                'reason' => 'Type-to-standard assignment.',
            ],
            [
                'relative_path' => 'database/sql/instrument_type_standard_assignment_standard_master.sql',
                'classification' => 'requires_approval',
                'reason' => 'Assignment FK to standard_master.',
            ],
            [
                'relative_path' => 'database/sql/instrument_type_standard_assignment_display_name.sql',
                'classification' => 'requires_approval',
                'reason' => 'Assignment display name column.',
            ],
            [
                'relative_path' => 'database/sql/calibration_external_vendor_name.sql',
                'classification' => 'requires_approval',
                'reason' => 'Optional EXTERNAL vendor snapshot.',
            ],
            [
                'relative_path' => 'database/sql/dynamic_test_table_schema.sql',
                'classification' => 'requires_approval',
                'reason' => 'Test component tables for Record/Certificate.',
            ],
        ];
    }

    /**
     * Live relations a later read-only schema inspect must confirm.
     * This method does not query PostgreSQL.
     *
     * @return list<string>
     */
    public static function requiredLiveRelations(): array
    {
        return [
            'public.users',
            'public.roles',
            'public.user_roles',
            'public.app_user',
            'public.user_app_user_map',
            'public.calibration',
            'public.calibration.started_by_app_user_id',
            'public.instrument',
            'public.instrument_type',
            'public.certificate',
            'public.test_point',
            'public.standard_master',
            'public.calibration_activity_master',
        ];
    }

    /**
     * @return array{
     *     ok: bool,
     *     layer2_activation: bool,
     *     postgres_commands_executed: false,
     *     production_target_ok: bool,
     *     sqlite_memory_available: bool,
     *     isolation_enabled: bool,
     *     approved_sql: list<array{relative_path: string, present: bool, hash_ok: bool}>,
     *     pending_sql: list<array{relative_path: string, classification: string, reason: string}>,
     *     auth_contract: array<string, bool>,
     *     browser_smoke: list<string>,
     *     required_live_relations: list<string>,
     *     violations: list<string>
     * }
     */
    public function inspect(Repository $config): array
    {
        $isolation = app(StagingRuntimeIsolation::class);
        $productionViolations = $isolation->productionTargetViolations($config);
        $approvedSql = $this->approvedSqlOnDisk();
        $authContract = $this->authContract();
        $violations = $productionViolations;

        foreach ($approvedSql as $artifact) {
            if (! $artifact['present'] || ! $artifact['hash_ok']) {
                $violations[] = 'Approved SQL artifact is not ready: '.$artifact['relative_path'];
            }
        }

        foreach ($authContract as $check => $ready) {
            if (! $ready) {
                $violations[] = 'Auth contract missing: '.$check;
            }
        }

        return [
            'ok' => $violations === [],
            'layer2_activation' => CalibrationHttpConnection::STAGING_HTTP_ACTIVATION,
            'postgres_commands_executed' => false,
            'production_target_ok' => $productionViolations === [],
            'sqlite_memory_available' => DestructiveCommandGuard::defaultConnectionIsSqliteMemory($config),
            'isolation_enabled' => $isolation->enabled($config),
            'approved_sql' => $approvedSql,
            'pending_sql' => self::pendingSql(),
            'auth_contract' => $authContract,
            'browser_smoke' => self::BROWSER_SMOKE_STEPS,
            'required_live_relations' => self::requiredLiveRelations(),
            'violations' => $violations,
        ];
    }

    /**
     * @return list<array{relative_path: string, present: bool, hash_ok: bool}>
     */
    private function approvedSqlOnDisk(): array
    {
        $rows = [];

        foreach (ApprovedStagingSqlArtifacts::ordered() as $artifact) {
            $path = base_path($artifact['relative_path']);
            $present = is_file($path);
            $hashOk = $present && hash_file('sha256', $path) === $artifact['sha256'];

            $rows[] = [
                'relative_path' => $artifact['relative_path'],
                'present' => $present,
                'hash_ok' => $hashOk,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, bool>
     */
    private function authContract(): array
    {
        $actor = (string) file_get_contents(app_path('CalibrationSessions/ResolveDomainActor.php'));
        $start = (string) file_get_contents(app_path('CalibrationSessions/StartCalibrationSession.php'));
        $login = (string) file_get_contents(app_path('Authorization/ResolveLoginEmail.php'));

        return [
            'resolve_domain_actor' => str_contains($actor, 'user_app_user_map') && str_contains($actor, 'laravel_user_id'),
            'start_uses_domain_actor' => str_contains($start, 'ResolveDomainActor') && str_contains($start, 'started_by_app_user_id'),
            'login_username_uses_map' => str_contains($login, 'app_user') && str_contains($login, 'user_app_user_map'),
        ];
    }
}
