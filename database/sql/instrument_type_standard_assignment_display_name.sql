-- Additive Standard Display Name for Standard(s) Used configuration.
-- PostgreSQL 18. Apply ONLY to calibration_staging_db.
--
-- NOT IDEMPOTENT. Run exactly once after instrument_type_standard_assignment.sql.
-- Existing assignment rows remain valid; the new column is nullable.
-- Does not INSERT assignment, instrument, or calibration rows.
-- Does not ALTER calibration_standard_usage.
-- Does not modify instrument.instrument_name.
--
-- Do not execute on calibration_db until staging tests pass.

BEGIN;

DO $guard$
BEGIN
    IF current_database() IS DISTINCT FROM 'calibration_staging_db' THEN
        RAISE EXCEPTION
            'refusing instrument_type_standard_assignment_display_name.sql: current_database()=%',
            current_database()
            USING ERRCODE = '42501';
    END IF;

    IF to_regclass('public.instrument_type_standard_assignment') IS NULL THEN
        RAISE EXCEPTION
            'instrument_type_standard_assignment must exist before adding standard_display_name'
            USING ERRCODE = '42P01';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
            AND table_name = 'instrument_type_standard_assignment'
            AND column_name = 'standard_display_name'
    ) THEN
        RAISE EXCEPTION
            'instrument_type_standard_assignment_display_name.sql is not idempotent and has already been applied'
            USING ERRCODE = '42710';
    END IF;
END;
$guard$;

ALTER TABLE public.instrument_type_standard_assignment
    ADD COLUMN standard_display_name character varying(255);

COMMIT;
