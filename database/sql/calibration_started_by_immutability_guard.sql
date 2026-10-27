-- Calibration Session ownership immutability guard (2C.2B-21J).
-- PostgreSQL 18. Apply ONLY to calibration_staging_db when authorized.
--
-- REPEATABLE for the function body: CREATE OR REPLACE FUNCTION installs the
-- complete trg_calibration_session_guard definition (existing session guards +
-- started_by_app_user_id immutability). Re-running is safe when prerequisites
-- still match. This file does NOT DROP or recreate the trigger object
-- (avoids duplicate / conflicting trg_calibration_session_guard triggers).
--
-- Does not execute ownership backfill.
-- Does not ALTER cancelled_by_user_id, cancelled_at, or cancelled_reason.
-- Does not INSERT calibration / app_user / user_app_user_map rows.
-- Does not modify production calibration_db.
--
-- Ownership contract (UPDATE path only; INSERT may assign owner):
--   123 -> 456  DENY
--   123 -> NULL DENY
--   NULL -> 123  DENY  (assignment is INSERT-only; no late claim)
--   NULL -> NULL ALLOW (legacy rows stay NULL; subject to other guards)
--   123 -> 123  ALLOW (subject to other guards)
-- Comparison uses IS DISTINCT FROM (NULL-safe).
--
-- Prerequisites (fail-closed; existence alone is not proof):
--   1. current_database() = calibration_staging_db
--   2. public.calibration exists with session_status
--   3. public.calibration.started_by_app_user_id exists as bigint
--   4. public.trg_calibration_session_guard() trigger function exists
--   5. trigger trg_calibration_session_guard exists on public.calibration
--
-- Deployment order (manual staging; do not assume already applied):
--   1. RBAC schema prerequisites (database/sql/roles.sql + Laravel users)
--   2. Ownership column + FK (database/sql/calibration_started_by_app_user.sql)
--   3. This ownership immutability guard
--   4. Application code deployment (Start/Cancel writers already treat owner
--      as INSERT-only / never rewritten)
--
-- Do not execute on calibration_db.
-- Do not run via php artisan migrate against staging or production.
-- Do not claim PostgreSQL runtime verification from SQLite Pest alone.

BEGIN;

DO $guard$
DECLARE
    owner_column_type text;
    trigger_count integer;
BEGIN
    IF current_database() IS DISTINCT FROM 'calibration_staging_db' THEN
        RAISE EXCEPTION
            'refusing calibration_started_by_immutability_guard.sql: current_database()=%',
            current_database()
            USING ERRCODE = '42501';
    END IF;

    IF to_regclass('public.calibration') IS NULL THEN
        RAISE EXCEPTION
            'calibration_started_by_immutability_guard.sql requires public.calibration'
            USING ERRCODE = '42P01';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'calibration'
          AND column_name = 'session_status'
    ) THEN
        RAISE EXCEPTION
            'calibration_session_foundation.sql / crud foundation must be applied first'
            USING ERRCODE = '55000';
    END IF;

    SELECT data_type
    INTO owner_column_type
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name = 'calibration'
      AND column_name = 'started_by_app_user_id';

    IF owner_column_type IS NULL THEN
        RAISE EXCEPTION
            'calibration_started_by_app_user.sql must be applied first (started_by_app_user_id missing)'
            USING ERRCODE = '42703';
    END IF;

    IF owner_column_type IS DISTINCT FROM 'bigint' THEN
        RAISE EXCEPTION
            'refusing calibration_started_by_immutability_guard.sql: started_by_app_user_id type is %; expected bigint',
            owner_column_type
            USING ERRCODE = '42804';
    END IF;

    IF to_regprocedure('public.trg_calibration_session_guard()') IS NULL THEN
        RAISE EXCEPTION
            'calibration_started_by_immutability_guard.sql requires public.trg_calibration_session_guard()'
            USING ERRCODE = '42883';
    END IF;

    SELECT count(*)::integer
    INTO trigger_count
    FROM pg_catalog.pg_trigger AS trigger_row
    INNER JOIN pg_catalog.pg_class AS relation
        ON relation.oid = trigger_row.tgrelid
    INNER JOIN pg_catalog.pg_namespace AS schema_row
        ON schema_row.oid = relation.relnamespace
    WHERE schema_row.nspname = 'public'
      AND relation.relname = 'calibration'
      AND trigger_row.tgname = 'trg_calibration_session_guard'
      AND NOT trigger_row.tgisinternal;

    IF trigger_count IS DISTINCT FROM 1 THEN
        RAISE EXCEPTION
            'refusing calibration_started_by_immutability_guard.sql: expected exactly one trg_calibration_session_guard on public.calibration; found %',
            COALESCE(trigger_count, 0)
            USING ERRCODE = '55000';
    END IF;
END;
$guard$;

-- Replace the trigger function body only. Preserve the existing trigger object.
-- Full existing guard logic from calibration_session_crud_foundation.sql is
-- retained; ownership immutability is appended to the UPDATE path.

CREATE OR REPLACE FUNCTION public.trg_calibration_session_guard()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'calibration sessions cannot be deleted'
            USING ERRCODE = '25006';
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF NEW.calibration_method IS NULL THEN
            RAISE EXCEPTION 'calibration method is required at start'
                USING ERRCODE = '23514';
        END IF;

        RETURN NEW;
    END IF;

    IF OLD.session_status IN ('completed', 'cancelled') THEN
        RAISE EXCEPTION
            'calibration session % is terminal (%) and cannot be changed',
            OLD.calibration_id,
            OLD.session_status
            USING ERRCODE = '25006';
    END IF;

    IF NEW.calibration_method IS DISTINCT FROM OLD.calibration_method THEN
        RAISE EXCEPTION 'calibration method is immutable after start'
            USING ERRCODE = '25006';
    END IF;

    IF NEW.instrument_id IS DISTINCT FROM OLD.instrument_id
       OR NEW.instrument_type_id IS DISTINCT FROM OLD.instrument_type_id THEN
        RAISE EXCEPTION 'calibration session instrument type is immutable after start'
            USING ERRCODE = '25006';
    END IF;

    IF NEW.calibration_number IS DISTINCT FROM OLD.calibration_number THEN
        RAISE EXCEPTION 'calibration number cannot be changed'
            USING ERRCODE = '25006';
    END IF;

    -- Ownership: INSERT-only assignment. Any UPDATE change is denied,
    -- including NULL -> non-NULL and non-NULL -> NULL (IS DISTINCT FROM).
    IF NEW.started_by_app_user_id IS DISTINCT FROM OLD.started_by_app_user_id THEN
        RAISE EXCEPTION 'calibration session owner is immutable after insert'
            USING ERRCODE = '25006';
    END IF;

    IF OLD.session_status = 'in_progress'
       AND NEW.session_status = 'completed' THEN
        PERFORM public.calibration_session_assert_complete(NEW);
    ELSIF OLD.session_status = 'in_progress'
       AND NEW.session_status = 'cancelled' THEN
        IF NEW.cancelled_at IS NULL
           OR NEW.cancelled_reason IS NULL
           OR length(btrim(NEW.cancelled_reason)) = 0 THEN
            RAISE EXCEPTION 'cancellation requires a reason and timestamp'
                USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.session_status IS DISTINCT FROM OLD.session_status THEN
        RAISE EXCEPTION 'unsupported session status transition % -> %',
            OLD.session_status,
            NEW.session_status
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

COMMENT ON COLUMN public.calibration.started_by_app_user_id IS
$$Immutable session owner (app_user.app_user_id) assigned at INSERT/Start only.
NULL on legacy rows; no backfill; no UPDATE reassignment.
Enforced by trg_calibration_session_guard (IS DISTINCT FROM).$$;

COMMENT ON FUNCTION public.trg_calibration_session_guard() IS
$$BEFORE INSERT/UPDATE/DELETE guard for public.calibration.
Blocks deletes, terminal mutation, method/instrument/number changes,
owner reassignment (started_by_app_user_id), and invalid status transitions.$$;

COMMIT;
