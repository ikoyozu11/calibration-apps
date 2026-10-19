-- Additive Calibration Session foundation (step 30I-D.4B).
-- PostgreSQL 18. Apply ONLY to calibration_staging_db.
--
-- NOT IDEMPOTENT. Run exactly once.
-- Refuses to run when current_database() is not calibration_staging_db.
-- Does not UPDATE instrument, instrument_type identity, snapshot, or versioning rows.
-- Does not INSERT calibration rows.
-- Historical calibration rows, if any, receive session_status = completed via DEFAULT.
-- calibration_interval_months stays NULL; no 12-month default.
--
-- Do not execute on calibration_db until staging tests pass.

BEGIN;

DO $guard$
BEGIN
    IF current_database() IS DISTINCT FROM 'calibration_staging_db' THEN
        RAISE EXCEPTION
            'refusing calibration_session_foundation.sql: current_database()=%',
            current_database()
            USING ERRCODE = '42501';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'calibration'
          AND column_name = 'session_status'
    ) THEN
        RAISE EXCEPTION
            'calibration_session_foundation.sql is not idempotent and has already been applied'
            USING ERRCODE = '42710';
    END IF;
END;
$guard$;

-- ---------------------------------------------------------------------------
-- 1. Instrument Type interval (NULL until a value is approved)
-- ---------------------------------------------------------------------------

ALTER TABLE public.instrument_type
    ADD COLUMN calibration_interval_months integer;

ALTER TABLE public.instrument_type
    ADD CONSTRAINT chk_instrument_type_interval
    CHECK (
        calibration_interval_months IS NULL
        OR calibration_interval_months > 0
    );

ALTER TABLE public.instrument_type
    ADD CONSTRAINT uq_instrument_type_id_instrument
    UNIQUE (instrument_type_id, instrument_id);

-- ---------------------------------------------------------------------------
-- 2. Calibration session lifecycle and type binding
-- ---------------------------------------------------------------------------

ALTER TABLE public.calibration
    ADD COLUMN session_status character varying(20) NOT NULL DEFAULT 'completed';

ALTER TABLE public.calibration
    ADD COLUMN instrument_type_id bigint;

ALTER TABLE public.calibration
    ADD CONSTRAINT chk_calibration_session_status
    CHECK (session_status IN ('in_progress', 'completed', 'cancelled'));

ALTER TABLE public.calibration
    ADD CONSTRAINT chk_calibration_in_progress_type
    CHECK (
        session_status <> 'in_progress'
        OR instrument_type_id IS NOT NULL
    );

ALTER TABLE public.calibration
    ADD CONSTRAINT fk_calibration_instrument_type_pair
    FOREIGN KEY (instrument_type_id, instrument_id)
    REFERENCES public.instrument_type (instrument_type_id, instrument_id);

CREATE UNIQUE INDEX uq_calibration_in_progress_per_type
    ON public.calibration (instrument_type_id)
    WHERE session_status = 'in_progress';

CREATE INDEX ix_calibration_completed_type
    ON public.calibration (instrument_type_id, calibration_date DESC, calibration_id DESC)
    WHERE session_status = 'completed';

COMMIT;
