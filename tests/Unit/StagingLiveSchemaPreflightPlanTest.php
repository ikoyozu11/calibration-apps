<?php

use App\CalibrationSessions\CalibrationHttpConnection;
use App\StagingRuntime\StagingLiveSchemaPreflightPlan;
use App\StagingSqlDeployment\ApprovedStagingSqlArtifacts;
use Tests\TestCase;
use Tests\TestRefusingPostgresConnector;

uses(TestCase::class);

it('prepares a read-only staging inspect without executing postgresql', function () {
    TestRefusingPostgresConnector::$connectCalls = 0;

    $plan = app(StagingLiveSchemaPreflightPlan::class)->describe();

    expect($plan['executable'])->toBeFalse()
        ->and($plan['layer2_activation'])->toBeTrue()
        ->and($plan['target_database'])->toBe(ApprovedStagingSqlArtifacts::APPROVED_DATABASE)
        ->and($plan['forbidden_database'])->toBe(ApprovedStagingSqlArtifacts::FORBIDDEN_DATABASE_PRODUCTION)
        ->and($plan['current_database_assertion'])->toBe("current_database() = 'calibration_staging_db'")
        ->and($plan['planned_statements'])->toContain('select current_database() as name')
        ->and($plan['commands'])->not->toBeEmpty()
        ->and($plan['commands'][0])->toContain('-d calibration_staging_db')
        ->and($plan['commands'][0])->toContain('current_database()')
        ->and($plan['commands'])->not->toContain('-d calibration_db')
        ->and($plan['required_relations'])->toContain('public.user_app_user_map')
        ->and($plan['auth_mapping'])->toContain('never treat auth()->id() as app_user_id')
        ->and($plan['approved_sql'])->toContain('database/sql/roles.sql')
        ->and(collect($plan['pending_sql'])->pluck('relative_path'))
        ->toContain('database/sql/calibration_session_crud_foundation.sql')
        ->and(CalibrationHttpConnection::STAGING_HTTP_ACTIVATION)->toBeTrue()
        ->and(app(StagingLiveSchemaPreflightPlan::class)->executable())->toBeFalse()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('does not use the legacy staging preflight or open a connection', function () {
    $source = (string) file_get_contents(app_path('StagingRuntime/StagingLiveSchemaPreflightPlan.php'));

    expect($source)->toContain('Do not use the legacy staging preflight')
        ->and($source)->not->toContain('DB::')
        ->and($source)->not->toContain('Schema::')
        ->and($source)->not->toContain('Illuminate\\Support\\Facades\\DB')
        ->and($source)->toContain('current_database()');
});
