-- Retarget Standard(s) Used assignments to standard_master.
-- PostgreSQL 18. Apply ONLY to calibration_staging_db.
--
-- Additive. Does not drop standard_instrument_id or its foreign key.
-- Makes standard_instrument_id nullable so new assignments can reference
-- standard_master only. Existing assignment rows are preserved.
--
-- NOT IDEMPOTENT. Run exactly once after standard_master.sql.
-- Does not ALTER calibration_standard_usage.
--
-- Do not execute on calibration_db until staging tests pass.

BEGIN;

DO $guard$
BEGIN
    IF current_database() IS DISTINCT FROM 'calibration_staging_db' THEN
        RAISE EXCEPTION
            'refusing instrument_type_standard_assignment_standard_master.sql: current_database()=%',
            current_database()
            USING ERRCODE = '42501';
    END IF;

    IF to_regclass('public.standard_master') IS NULL THEN
        RAISE EXCEPTION
            'standard_master must exist before retargeting assignments'
            USING ERRCODE = '42P01';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
            AND table_name = 'instrument_type_standard_assignment'
            AND column_name = 'standard_id'
    ) THEN
        RAISE EXCEPTION
            'instrument_type_standard_assignment_standard_master.sql is not idempotent and has already been applied'
            USING ERRCODE = '42710';
    END IF;
END;
$guard$;

ALTER TABLE public.instrument_type_standard_assignment
    ADD COLUMN standard_id bigint;

ALTER TABLE public.instrument_type_standard_assignment
    ALTER COLUMN standard_instrument_id DROP NOT NULL;

ALTER TABLE public.instrument_type_standard_assignment
    ADD CONSTRAINT fk_instrument_type_standard_assignment_master
        FOREIGN KEY (standard_id)
        REFERENCES public.standard_master (standard_id);

ALTER TABLE public.instrument_type_standard_assignment
    ADD CONSTRAINT uq_instrument_type_standard_assignment_master
        UNIQUE (instrument_type_id, standard_id);

CREATE INDEX ix_instrument_type_standard_assignment_master
    ON public.instrument_type_standard_assignment (standard_id);

COMMIT;
