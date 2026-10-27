-- Calibration Session ownership column foundation (2C.2B-21G).
-- PostgreSQL 18. Apply ONLY to calibration_staging_db when authorized.
--
-- NOT IDEMPOTENT. Run exactly once after calibration_session_crud_foundation.sql.
-- Does not UPDATE or backfill calibration rows.
-- Does not ALTER cancelled_by_user_id, cancelled_at, cancelled_reason, or
-- identity snapshot columns.
-- Does not modify trg_calibration_session_guard in this checkpoint.
-- Does not INSERT app_user or user_app_user_map rows.
--
-- Contract:
--   calibration.started_by_app_user_id bigint NULL
--   FK -> app_user(app_user_id) ON DELETE RESTRICT
--   Legacy rows remain NULL until a future Start checkpoint assigns an owner.
--   Ownership is intended to be immutable after assignment (service + future
--   trigger extension). This file only adds storage; it does not enforce
--   immutability yet.
--
-- Prerequisites (verified below; existence alone is not type/FK proof):
--   1. current_database() = calibration_staging_db
--   2. public.calibration exists with session_status (CRUD foundation applied)
--   3. public.app_user.app_user_id exists as bigint
--   4. started_by_app_user_id is absent (prevents duplicate / wrong-type ADD)
--
-- Idempotency strategy:
--   Refuse if the column name already exists. Do not use IF NOT EXISTS to
--   skip: that would hide a wrong type or missing FK from a partial apply.
--
-- Future immutability (NOT applied here):
--   Extend public.trg_calibration_session_guard() so that once
--   OLD.started_by_app_user_id IS NOT NULL, any distinct NEW value (including
--   NULL) raises. Initial assignment may occur on INSERT or on the first
--   UPDATE that sets NULL -> non-NULL from Start. Do not allow reassignment.
--
-- Do not execute on calibration_db.
-- Do not run via php artisan migrate against staging or production.

BEGIN;

DO $guard$
DECLARE
    app_user_id_type text;
BEGIN
    IF current_database() IS DISTINCT FROM 'calibration_staging_db' THEN
        RAISE EXCEPTION
            'refusing calibration_started_by_app_user.sql: current_database()=%',
            current_database()
            USING ERRCODE = '42501';
    END IF;

    IF to_regclass('public.calibration') IS NULL THEN
        RAISE EXCEPTION
            'calibration_started_by_app_user.sql requires public.calibration'
            USING ERRCODE = '42P01';
    END IF;

    IF to_regclass('public.app_user') IS NULL THEN
        RAISE EXCEPTION
            'calibration_started_by_app_user.sql requires public.app_user'
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

    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'calibration'
          AND column_name = 'started_by_app_user_id'
    ) THEN
        RAISE EXCEPTION
            'calibration_started_by_app_user.sql is not idempotent and started_by_app_user_id already exists'
            USING ERRCODE = '42710';
    END IF;

    SELECT data_type
    INTO app_user_id_type
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name = 'app_user'
      AND column_name = 'app_user_id';

    IF app_user_id_type IS DISTINCT FROM 'bigint' THEN
        RAISE EXCEPTION
            'refusing calibration_started_by_app_user.sql: app_user.app_user_id type is %; expected bigint',
            COALESCE(app_user_id_type, 'missing')
            USING ERRCODE = '42804';
    END IF;
END;
$guard$;

ALTER TABLE public.calibration
    ADD COLUMN started_by_app_user_id bigint;

ALTER TABLE public.calibration
    ADD CONSTRAINT fk_calibration_started_by
        FOREIGN KEY (started_by_app_user_id)
        REFERENCES public.app_user (app_user_id)
        ON DELETE RESTRICT;

COMMENT ON COLUMN public.calibration.started_by_app_user_id IS
$$Immutable session owner (app_user.app_user_id) assigned at Start.
NULL on legacy rows; no backfill. Enforced by future Start writer and a
future trg_calibration_session_guard extension — not by this DDL alone.$$;

COMMIT;
