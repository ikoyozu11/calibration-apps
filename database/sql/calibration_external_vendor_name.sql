-- Optional EXTERNAL vendor snapshot (2C.2B-21Z-O1F-E1).
-- PostgreSQL 18. Apply ONLY to calibration_staging_db when authorized.
--
-- NOT IDEMPOTENT. Run exactly once after calibration_session_crud_foundation.sql.
-- Does not UPDATE or backfill calibration rows.
-- Does not ALTER calibration_provider_id, certificate_number, dates, or
-- external document columns.
-- Does not INSERT calibration_provider rows.
-- Does not add a foreign key or vendor master.
--
-- Contract:
--   calibration.external_vendor_name character varying(255) NULL
--   Session-owned snapshot for EXTERNAL calibrations.
--   Empty/null is valid. Not a Complete precondition.
--   Unchanged when an external PDF is replaced.
--
-- Prerequisites (verified below; existence alone is not type proof):
--   1. current_database() = calibration_staging_db
--   2. public.calibration exists with session_status and calibration_method
--   3. external_vendor_name is absent (prevents duplicate / wrong-type ADD)
--
-- Idempotency strategy:
--   Refuse if the column name already exists. Do not use IF NOT EXISTS to
--   skip: that would hide a wrong type from a partial apply.
--
-- Do not execute on calibration_db.
-- Do not run via php artisan migrate against staging or production.

BEGIN;

DO $guard$
BEGIN
    IF current_database() IS DISTINCT FROM 'calibration_staging_db' THEN
        RAISE EXCEPTION
            'refusing calibration_external_vendor_name.sql: current_database()=%',
            current_database()
            USING ERRCODE = '42501';
    END IF;

    IF to_regclass('public.calibration') IS NULL THEN
        RAISE EXCEPTION
            'calibration_external_vendor_name.sql requires public.calibration'
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

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'calibration'
          AND column_name = 'calibration_method'
    ) THEN
        RAISE EXCEPTION
            'calibration_external_vendor_name.sql requires calibration.calibration_method'
            USING ERRCODE = '55000';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'calibration'
          AND column_name = 'external_vendor_name'
    ) THEN
        RAISE EXCEPTION
            'calibration_external_vendor_name.sql is not idempotent and external_vendor_name already exists'
            USING ERRCODE = '42710';
    END IF;
END;
$guard$;

ALTER TABLE public.calibration
    ADD COLUMN external_vendor_name character varying(255);

COMMENT ON COLUMN public.calibration.external_vendor_name IS
$$Optional EXTERNAL vendor name snapshot for one calibration session.
NULL on existing and blank values. Not a provider FK. Not a Complete
precondition. Unchanged by PDF replacement. Enforced by
RecordExternalCalibrationVendor — not by this DDL alone.$$;

COMMIT;
