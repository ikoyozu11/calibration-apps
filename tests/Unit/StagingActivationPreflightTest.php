<?php

use App\CalibrationSessions\CalibrationHttpConnection;
use App\StagingRuntime\StagingActivationPreflight;
use App\StagingRuntime\StagingRuntimeIsolation;
use Illuminate\Config\Repository;
use Tests\TestCase;
use Tests\TestRefusingPostgresConnector;

uses(TestCase::class);

it('inspects activation readiness without opening postgresql', function () {
    TestRefusingPostgresConnector::$connectCalls = 0;

    $report = app(StagingActivationPreflight::class)->inspect(config());

    expect($report['postgres_commands_executed'])->toBeFalse()
        ->and($report['layer2_activation'])->toBeTrue()
        ->and($report['sqlite_memory_available'])->toBeTrue()
        ->and($report['browser_smoke'])->toBe(StagingActivationPreflight::BROWSER_SMOKE_STEPS)
        ->and($report['browser_smoke'])->toBe([
            'Login',
            'Instruments',
            'Start',
            'Record',
            'Complete',
            'History',
            'Certificate/PDF',
        ])
        ->and($report['approved_sql'])->toHaveCount(3)
        ->and($report['auth_contract']['resolve_domain_actor'])->toBeTrue()
        ->and($report['auth_contract']['start_uses_domain_actor'])->toBeTrue()
        ->and($report['ok'])->toBeTrue()
        ->and(CalibrationHttpConnection::STAGING_HTTP_ACTIVATION)->toBeTrue()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('refuses a production database name from config only', function () {
    TestRefusingPostgresConnector::$connectCalls = 0;

    $report = app(StagingActivationPreflight::class)->inspect(new Repository([
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
    ]));

    expect($report['ok'])->toBeFalse()
        ->and($report['production_target_ok'])->toBeFalse()
        ->and($report['postgres_commands_executed'])->toBeFalse()
        ->and($report['sqlite_memory_available'])->toBeFalse()
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('keeps unapproved sql out of the approved package', function () {
    $pending = collect(StagingActivationPreflight::pendingSql())->pluck('relative_path');
    $approved = collect([
        'database/sql/roles.sql',
        'database/sql/calibration_started_by_app_user.sql',
        'database/sql/calibration_started_by_immutability_guard.sql',
    ]);

    expect($pending->intersect($approved)->all())->toBe([])
        ->and($pending)->toContain('database/sql/calibration_session_crud_foundation.sql')
        ->and($pending)->toContain('database/sql/laravel_users_staging.sql')
        ->and(StagingActivationPreflight::requiredLiveRelations())->toContain('public.user_app_user_map');
});
