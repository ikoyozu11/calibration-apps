<?php

/**
 * Static contract tests for database/sql/calibration_started_by_immutability_guard.sql.
 *
 * These tests do NOT execute PostgreSQL and do NOT prove trigger runtime behavior.
 * They verify the reviewable SQL artifact contains the ownership immutability
 * contract and preserves the existing session guard surface.
 */

use Tests\TestRefusingPostgresConnector;

function ownershipImmutabilityGuardSql(): string
{
    $sql = file_get_contents(base_path('database/sql/calibration_started_by_immutability_guard.sql'));

    expect($sql)->toBeString()->not->toBe('');

    return $sql;
}

function ownershipColumnFoundationSql(): string
{
    $sql = file_get_contents(base_path('database/sql/calibration_started_by_app_user.sql'));

    expect($sql)->toBeString()->not->toBe('');

    return $sql;
}

function sessionGuardFoundationSql(): string
{
    $sql = file_get_contents(base_path('database/sql/calibration_session_crud_foundation.sql'));

    expect($sql)->toBeString()->not->toBe('');

    return $sql;
}

it('guards staging database name and refuses other databases in the ownership immutability script', function () {
    $sql = ownershipImmutabilityGuardSql();

    expect($sql)->toContain('BEGIN;')
        ->and($sql)->toContain('COMMIT;')
        ->and($sql)->toContain("current_database() IS DISTINCT FROM 'calibration_staging_db'")
        ->and($sql)->toContain('refusing calibration_started_by_immutability_guard.sql')
        ->and($sql)->toContain('Do not execute on calibration_db')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('requires ownership column, bigint type, session guard function, and exactly one trigger', function () {
    $sql = ownershipImmutabilityGuardSql();

    expect($sql)->toContain("to_regclass('public.calibration')")
        ->and($sql)->toContain("column_name = 'session_status'")
        ->and($sql)->toContain("column_name = 'started_by_app_user_id'")
        ->and($sql)->toContain("owner_column_type IS DISTINCT FROM 'bigint'")
        ->and($sql)->toContain("to_regprocedure('public.trg_calibration_session_guard()')")
        ->and($sql)->toContain("trigger_row.tgname = 'trg_calibration_session_guard'")
        ->and($sql)->toContain('expected exactly one trg_calibration_session_guard')
        ->and($sql)->toContain('calibration_started_by_app_user.sql must be applied first')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('replaces the session guard function without dropping or duplicating the trigger', function () {
    $sql = ownershipImmutabilityGuardSql();

    expect($sql)->toContain('CREATE OR REPLACE FUNCTION public.trg_calibration_session_guard()')
        ->and($sql)->not->toContain('DROP TRIGGER')
        ->and($sql)->not->toContain('DROP FUNCTION')
        ->and($sql)->not->toContain('CREATE TRIGGER trg_calibration_session_guard')
        ->and($sql)->toContain('REPEATABLE')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('rejects owner changes with NULL-safe IS DISTINCT FROM comparison', function () {
    $sql = ownershipImmutabilityGuardSql();

    expect($sql)->toContain('NEW.started_by_app_user_id IS DISTINCT FROM OLD.started_by_app_user_id')
        ->and($sql)->toContain('calibration session owner is immutable after insert')
        ->and($sql)->toContain('NULL -> 123')
        ->and($sql)->toContain('123 -> NULL')
        ->and($sql)->toContain('123 -> 456')
        ->and($sql)->toContain('NULL -> NULL ALLOW')
        ->and($sql)->toContain('123 -> 123')
        ->and($sql)->toContain('INSERT-only')
        ->and($sql)->toContain('Does not ALTER cancelled_by_user_id')
        ->and($sql)->not->toContain('NEW.cancelled_by_user_id')
        ->and($sql)->not->toContain('OLD.cancelled_by_user_id')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('preserves existing session guard protections from the CRUD foundation', function () {
    $sql = ownershipImmutabilityGuardSql();
    $foundation = sessionGuardFoundationSql();

    expect($foundation)->toContain('CREATE FUNCTION public.trg_calibration_session_guard()')
        ->and($sql)->toContain('calibration sessions cannot be deleted')
        ->and($sql)->toContain('calibration method is required at start')
        ->and($sql)->toContain("OLD.session_status IN ('completed', 'cancelled')")
        ->and($sql)->toContain('calibration method is immutable after start')
        ->and($sql)->toContain('calibration session instrument type is immutable after start')
        ->and($sql)->toContain('calibration number cannot be changed')
        ->and($sql)->toContain('cancellation requires a reason and timestamp')
        ->and($sql)->toContain('unsupported session status transition')
        ->and($sql)->toContain('PERFORM public.calibration_session_assert_complete(NEW)')
        ->and($sql)->toContain('NEW.calibration_method IS DISTINCT FROM OLD.calibration_method')
        ->and($sql)->toContain('NEW.instrument_id IS DISTINCT FROM OLD.instrument_id')
        ->and($sql)->toContain('NEW.instrument_type_id IS DISTINCT FROM OLD.instrument_type_id')
        ->and($sql)->toContain('NEW.calibration_number IS DISTINCT FROM OLD.calibration_number')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('documents deployment order after RBAC and ownership column prerequisites', function () {
    $sql = ownershipImmutabilityGuardSql();
    $columnSql = ownershipColumnFoundationSql();

    expect($sql)->toContain('roles.sql')
        ->and($sql)->toContain('calibration_started_by_app_user.sql')
        ->and($sql)->toContain('Application code deployment')
        ->and($columnSql)->toContain('ADD COLUMN started_by_app_user_id bigint')
        ->and($columnSql)->toContain('fk_calibration_started_by')
        ->and(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});

it('does not open postgresql while validating the ownership immutability sql artifact', function () {
    ownershipImmutabilityGuardSql();

    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});
