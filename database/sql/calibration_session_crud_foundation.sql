-- Calibration Session CRUD database foundation (Phase 1B).
-- PostgreSQL 18. Apply ONLY to calibration_staging_db.
--
-- NOT IDEMPOTENT except Internal Team provider seed and session settings upsert.
-- Run exactly once after Checkpoint 2 approval. Checkpoint 3 executes this file.
--
-- Refuses to run when current_database() is not calibration_staging_db.
-- Does not UPDATE instrument, instrument_type, test_profile, or published
-- test_column rows except ADD COLUMN completion_rule DEFAULT not_configured.
-- Does not INSERT calibration session rows.
-- Does not attach triggers to certificate or notification_log.
--
-- ROLLBACK: the entire script is one transaction. An error rolls back every
-- change. After COMMIT, reverse by restoring the Checkpoint 1 custom dump.
-- There is no down() script; dropping the new objects while sessions exist
-- would destroy audit data.
--
-- Environment synchronization (canonical -> legacy certificate columns):
--   Canonical source for new sessions: calibration_environment_value.
--   Forward: trg_calibration_environment_value_sync calls
--   calibration_environment_project_legacy, which SET LOCAL
--   calibration.project_legacy = 1, writes the projection, then resets the
--   GUC to 0. EXCEPTION handlers also reset the GUC so it cannot stick after
--   an error. Units come from the frozen parameter snapshot.
--   Reverse: trg_calibration_environment_legacy_sync permits Temperature/RH
--   writes only when that GUC is 1 (authorized projection).
--   pg_trigger_depth() is not used as authorization; unrelated nested
--   triggers cannot bypass the restriction.
--   Direct legacy Temperature/RH changes are rejected when the session has a
--   Temperature or RH snapshot. Sessions without those snapshots may still
--   write legacy columns (certificate compatibility).
--   Legacy updates never copy back into canonical values.
--   Locking order: BEFORE child triggers lock public.calibration FOR UPDATE,
--   then the canonical value is written, then the authorized projection
--   updates the legacy row. FK reassignment nulls the old parent's
--   projection then writes the new parent.
--   Remaining limitation: a superuser or raw SQL in the same transaction can
--   SET LOCAL the same GUC. Triggers cannot prevent that class of bypass.
--   Validation: Internal Complete reads canonical required values. Required
--   and Optional environment both need explicit configuration confirmation.
--   Optional environment may be empty after confirmation, but any Required
--   parameters on that session must still have values. Unconfirmed parameter
--   inclusions block Complete.
--   pressure_* on calibration_environment is unused by the new parameter set
--   and is left untouched so v_certificate_environment stays valid.
--
-- External PDF replacement:
--   BEFORE INSERT/UPDATE, lock existing active rows FOR UPDATE, then mark
--   them inactive with superseded_at, then allow the new active row.
--   Unique partial index uq_calibration_external_document_active remains as
--   the concurrency backstop. AFTER triggers are not used because unique
--   indexes are checked before AFTER triggers fire.
--   Terminal sessions are rejected by the child mutability trigger before
--   supersession. A failed INSERT rolls back the BEFORE UPDATE, leaving the
--   original active document unchanged.
--
-- External PDF file/database consistency:
--   Write the UUID file on disk calibration_documents first, then INSERT the
--   metadata row. On INSERT failure, delete the new file. Orphan UUID files
--   (disk yes, row no) are cleanup candidates. Superseded files are retained.
--
-- Do not execute on calibration_db until staging tests pass.

BEGIN;

DO $guard$
BEGIN
    IF current_database() IS DISTINCT FROM 'calibration_staging_db' THEN
        RAISE EXCEPTION
            'refusing calibration_session_crud_foundation.sql: current_database()=%',
            current_database()
            USING ERRCODE = '42501';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'calibration'
          AND column_name = 'session_status'
    ) THEN
        RAISE EXCEPTION
            'calibration_session_foundation.sql must be applied first'
            USING ERRCODE = '55000';
    END IF;

    IF to_regclass('public.standard_master') IS NULL
       OR to_regclass('public.calibration_activity_master') IS NULL THEN
        RAISE EXCEPTION
            'standard_master and calibration_activity_master must exist'
            USING ERRCODE = '55000';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'calibration'
          AND column_name = 'completed_at'
    ) THEN
        RAISE EXCEPTION
            'calibration_session_crud_foundation.sql is not idempotent and has already been applied'
            USING ERRCODE = '42710';
    END IF;

    IF to_regclass('public.calibration_number_seq') IS NOT NULL THEN
        RAISE EXCEPTION
            'refusing to reset existing calibration_number_seq'
            USING ERRCODE = '42710';
    END IF;
END;
$guard$;

-- ---------------------------------------------------------------------------
-- 1. Draft nullability, identity snapshot, cancellation, completion timestamp
-- ---------------------------------------------------------------------------

ALTER TABLE public.calibration
    ALTER COLUMN calibration_date DROP NOT NULL,
    ALTER COLUMN calibration_due DROP NOT NULL,
    ALTER COLUMN calibration_provider_id DROP NOT NULL;

ALTER TABLE public.calibration
    DROP CONSTRAINT chk_calibration_due;

ALTER TABLE public.calibration
    ADD CONSTRAINT chk_calibration_due
    CHECK (
        calibration_date IS NULL
        OR calibration_due IS NULL
        OR calibration_due >= calibration_date
    );

ALTER TABLE public.calibration
    ADD COLUMN completed_at timestamp with time zone,
    ADD COLUMN cancelled_at timestamp with time zone,
    ADD COLUMN cancelled_reason text,
    ADD COLUMN cancelled_by_user_id bigint,
    ADD COLUMN snapshot_instrument_name character varying(150),
    ADD COLUMN snapshot_instrument_type_name character varying(150),
    ADD COLUMN snapshot_brand character varying,
    ADD COLUMN snapshot_model character varying,
    ADD COLUMN snapshot_serial_number character varying(255),
    ADD COLUMN snapshot_asset_number character varying(50),
    ADD COLUMN snapshot_max_capacity numeric,
    ADD COLUMN snapshot_max_capacity_unit character varying,
    ADD COLUMN snapshot_resolution numeric,
    ADD COLUMN snapshot_resolution_unit character varying,
    ADD COLUMN snapshot_location_id bigint,
    ADD COLUMN snapshot_detail_location_id bigint,
    ADD COLUMN snapshot_location_code character varying(50),
    ADD COLUMN snapshot_location_name character varying(100),
    ADD COLUMN snapshot_detail_location_code character varying(50),
    ADD COLUMN snapshot_detail_location_name character varying(100);

ALTER TABLE public.calibration
    ADD CONSTRAINT fk_calibration_cancelled_by
        FOREIGN KEY (cancelled_by_user_id)
        REFERENCES public.app_user (app_user_id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_calibration_snapshot_location
        FOREIGN KEY (snapshot_location_id)
        REFERENCES public.location (location_id),
    ADD CONSTRAINT fk_calibration_snapshot_detail_location
        FOREIGN KEY (snapshot_detail_location_id)
        REFERENCES public.detail_location (detail_location_id),
    ADD CONSTRAINT chk_calibration_cancelled_audit
        CHECK (
            (
                session_status = 'cancelled'
                AND cancelled_at IS NOT NULL
                AND cancelled_reason IS NOT NULL
                AND length(btrim(cancelled_reason)) > 0
            )
            OR (
                session_status <> 'cancelled'
                AND cancelled_at IS NULL
                AND cancelled_reason IS NULL
                AND cancelled_by_user_id IS NULL
            )
        ),
    ADD CONSTRAINT chk_calibration_completed_at
        CHECK (
            (session_status = 'completed' AND completed_at IS NOT NULL)
            OR (session_status <> 'completed' AND completed_at IS NULL)
        );

-- ---------------------------------------------------------------------------
-- 2. Global Calibration Number sequence
--    Staging currently has zero matching numbers, so START WITH 1.
--    Matching numbers are parsed with a CTE (not a set-returning function
--    inside an aggregate). Malformed numbers are ignored. Oversized digits
--    abort initialization instead of wrapping.
-- ---------------------------------------------------------------------------

DO $sequence$
DECLARE
    max_seq numeric := 0;
    next_start bigint;
    overflow_count integer := 0;
BEGIN
    WITH parsed AS (
        SELECT (regexp_match(
            calibration_number,
            '^CAL\.VT([0-9]{4,})/(0[1-9]|1[0-2])/[0-9]{2}$'
        ))[1] AS seq_text
        FROM public.calibration
    )
    SELECT
        COUNT(*) FILTER (
            WHERE seq_text IS NOT NULL
              AND (
                  char_length(seq_text) > 19
                  OR (
                      char_length(seq_text) = 19
                      AND seq_text::numeric > 9223372036854775807
                  )
              )
        ),
        COALESCE(MAX(seq_text::numeric) FILTER (
            WHERE seq_text IS NOT NULL
              AND (
                  char_length(seq_text) <= 18
                  OR (
                      char_length(seq_text) = 19
                      AND seq_text::numeric <= 9223372036854775807
                  )
              )
        ), 0)
    INTO overflow_count, max_seq
    FROM parsed;

    IF overflow_count > 0 THEN
        RAISE EXCEPTION
            'calibration_number_seq cannot start: % matching number(s) overflow bigint',
            overflow_count
            USING ERRCODE = '22003';
    END IF;

    IF max_seq >= 9223372036854775807 THEN
        RAISE EXCEPTION
            'calibration_number_seq cannot start: maximum existing sequence is bigint max'
            USING ERRCODE = '22003';
    END IF;

    next_start := (max_seq + 1)::bigint;

    EXECUTE format(
        'CREATE SEQUENCE public.calibration_number_seq AS bigint INCREMENT BY 1 MINVALUE 1 MAXVALUE 9223372036854775807 START WITH %s NO CYCLE',
        next_start
    );
END;
$sequence$;

CREATE FUNCTION public.calibration_number_next_value()
RETURNS bigint
LANGUAGE sql
SET search_path = pg_catalog
AS $$
    SELECT nextval('public.calibration_number_seq');
$$;

COMMENT ON SEQUENCE public.calibration_number_seq IS
$$Global Calibration Number allocator. Application formats with
sprintf('CAL.VT%04d/%s', sequence, jakartaDate->format('m/y')) using Asia/Jakarta.
Cancelled numbers are never reused. Gaps are acceptable.$$;

-- ---------------------------------------------------------------------------
-- 3. Requirement configuration (master + frozen session snapshots)
-- ---------------------------------------------------------------------------

CREATE TABLE public.instrument_type_requirement (
    instrument_type_id bigint NOT NULL,
    requirement_category character varying(20) NOT NULL,
    requirement_mode character varying(20) NOT NULL DEFAULT 'not_configured',
    updated_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_instrument_type_requirement
        PRIMARY KEY (instrument_type_id, requirement_category),
    CONSTRAINT fk_instrument_type_requirement_type
        FOREIGN KEY (instrument_type_id)
        REFERENCES public.instrument_type (instrument_type_id)
        ON DELETE CASCADE,
    CONSTRAINT chk_instrument_type_requirement_category
        CHECK (requirement_category IN ('standards', 'activity', 'environment', 'test_profile')),
    CONSTRAINT chk_instrument_type_requirement_mode
        CHECK (requirement_mode IN ('required', 'optional', 'not_configured'))
);

COMMENT ON TABLE public.instrument_type_requirement IS
$$Missing rows mean not_configured. Never infer Optional or Required from absence.$$;

CREATE TABLE public.calibration_requirement_snapshot (
    calibration_requirement_snapshot_id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    calibration_id bigint NOT NULL,
    requirement_category character varying(20) NOT NULL,
    frozen_mode character varying(20) NOT NULL,
    resolved_mode character varying(20),
    resolved_at timestamp with time zone,
    resolved_reason text,
    resolved_by_user_id bigint,
    configuration_confirmed_at timestamp with time zone,
    configuration_confirmed_reason text,
    configuration_confirmed_by_user_id bigint,
    CONSTRAINT uq_calibration_requirement_snapshot_category
        UNIQUE (calibration_id, requirement_category),
    CONSTRAINT fk_calibration_requirement_snapshot_calibration
        FOREIGN KEY (calibration_id)
        REFERENCES public.calibration (calibration_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_calibration_requirement_snapshot_resolved_by
        FOREIGN KEY (resolved_by_user_id)
        REFERENCES public.app_user (app_user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_calibration_requirement_snapshot_confirmed_by
        FOREIGN KEY (configuration_confirmed_by_user_id)
        REFERENCES public.app_user (app_user_id)
        ON DELETE SET NULL,
    CONSTRAINT chk_calibration_requirement_snapshot_category
        CHECK (requirement_category IN ('standards', 'activity', 'environment', 'test_profile')),
    CONSTRAINT chk_calibration_requirement_snapshot_frozen
        CHECK (frozen_mode IN ('required', 'optional', 'not_configured')),
    CONSTRAINT chk_calibration_requirement_snapshot_resolved
        CHECK (
            (
                resolved_mode IS NULL
                AND resolved_at IS NULL
                AND resolved_reason IS NULL
                AND resolved_by_user_id IS NULL
            )
            OR (
                frozen_mode = 'not_configured'
                AND resolved_mode IN ('required', 'optional')
                AND resolved_at IS NOT NULL
                AND resolved_reason IS NOT NULL
                AND length(btrim(resolved_reason)) > 0
            )
        )
);

-- ---------------------------------------------------------------------------
-- 4. Extensible Calibration Environment
-- ---------------------------------------------------------------------------

CREATE TABLE public.environment_parameter (
    parameter_id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    parameter_code character varying(50) NOT NULL,
    parameter_name character varying(150) NOT NULL,
    unit character varying(50) NOT NULL,
    data_type character varying(20) NOT NULL,
    is_system boolean NOT NULL DEFAULT FALSE,
    created_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_environment_parameter_code UNIQUE (parameter_code),
    CONSTRAINT chk_environment_parameter_code
        CHECK (parameter_code ~ '^[a-z][a-z0-9_]{0,48}$'),
    CONSTRAINT chk_environment_parameter_name
        CHECK (length(btrim(parameter_name)) > 0),
    CONSTRAINT chk_environment_parameter_unit
        CHECK (length(btrim(unit)) > 0),
    CONSTRAINT chk_environment_parameter_type
        CHECK (data_type IN ('numeric', 'text'))
);

CREATE TABLE public.instrument_type_environment_parameter (
    instrument_type_id bigint NOT NULL,
    parameter_id bigint NOT NULL,
    requirement_mode character varying(20) NOT NULL,
    display_order integer NOT NULL,
    CONSTRAINT pk_instrument_type_environment_parameter
        PRIMARY KEY (instrument_type_id, parameter_id),
    CONSTRAINT uq_instrument_type_environment_parameter_order
        UNIQUE (instrument_type_id, display_order),
    CONSTRAINT fk_instrument_type_environment_parameter_type
        FOREIGN KEY (instrument_type_id)
        REFERENCES public.instrument_type (instrument_type_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_instrument_type_environment_parameter_parameter
        FOREIGN KEY (parameter_id)
        REFERENCES public.environment_parameter (parameter_id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_instrument_type_environment_parameter_mode
        CHECK (requirement_mode IN ('required', 'optional')),
    CONSTRAINT chk_instrument_type_environment_parameter_order
        CHECK (display_order >= 1)
);

CREATE TABLE public.calibration_environment_parameter_snapshot (
    calibration_id bigint NOT NULL,
    parameter_id bigint NOT NULL,
    parameter_code character varying(50) NOT NULL,
    parameter_name character varying(150) NOT NULL,
    unit character varying(50) NOT NULL,
    data_type character varying(20) NOT NULL,
    requirement_mode character varying(20) NOT NULL,
    display_order integer NOT NULL,
    included_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    inclusion_confirmed boolean NOT NULL DEFAULT TRUE,
    inclusion_reason text,
    CONSTRAINT pk_calibration_environment_parameter_snapshot
        PRIMARY KEY (calibration_id, parameter_id),
    CONSTRAINT uq_calibration_environment_parameter_snapshot_code
        UNIQUE (calibration_id, parameter_code),
    CONSTRAINT uq_calibration_environment_parameter_snapshot_order
        UNIQUE (calibration_id, display_order),
    CONSTRAINT fk_calibration_environment_parameter_snapshot_calibration
        FOREIGN KEY (calibration_id)
        REFERENCES public.calibration (calibration_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_calibration_environment_parameter_snapshot_parameter
        FOREIGN KEY (parameter_id)
        REFERENCES public.environment_parameter (parameter_id)
        ON DELETE RESTRICT,
    CONSTRAINT chk_calibration_environment_parameter_snapshot_mode
        CHECK (requirement_mode IN ('required', 'optional')),
    CONSTRAINT chk_calibration_environment_parameter_snapshot_type
        CHECK (data_type IN ('numeric', 'text')),
    CONSTRAINT chk_calibration_environment_parameter_snapshot_confirmed
        CHECK (inclusion_confirmed OR inclusion_reason IS NOT NULL)
);

CREATE TABLE public.calibration_environment_value (
    calibration_environment_value_id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    calibration_id bigint NOT NULL,
    parameter_id bigint NOT NULL,
    numeric_value numeric,
    text_value text,
    recorded_at timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_calibration_environment_value
        UNIQUE (calibration_id, parameter_id),
    CONSTRAINT fk_calibration_environment_value_snapshot
        FOREIGN KEY (calibration_id, parameter_id)
        REFERENCES public.calibration_environment_parameter_snapshot (calibration_id, parameter_id)
        ON DELETE CASCADE,
    CONSTRAINT chk_calibration_environment_value_one_kind
        CHECK (numeric_value IS NULL OR text_value IS NULL),
    CONSTRAINT chk_calibration_environment_value_text
        CHECK (text_value IS NULL OR length(btrim(text_value)) > 0)
);

INSERT INTO public.environment_parameter (
    parameter_code, parameter_name, unit, data_type, is_system
) VALUES
    ('temperature', 'Temperature', '°C', 'numeric', TRUE),
    ('relative_humidity', 'Relative Humidity', '%RH', 'numeric', TRUE);

DO $env_unique$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'uq_calibration_environment_calibration'
    ) THEN
        ALTER TABLE public.calibration_environment
            ADD CONSTRAINT uq_calibration_environment_calibration UNIQUE (calibration_id);
    END IF;
END;
$env_unique$;

-- ---------------------------------------------------------------------------
-- 5. Standards Used and Calibration Activity compatibility
-- ---------------------------------------------------------------------------

ALTER TABLE public.calibration_standard_usage
    ALTER COLUMN standard_instrument_id DROP NOT NULL;

ALTER TABLE public.calibration_standard_usage
    ADD COLUMN standard_id bigint,
    ADD COLUMN snapshot_standard_code character varying(20),
    ADD COLUMN snapshot_instrument_name character varying(150),
    ADD COLUMN snapshot_type_specification character varying(150),
    ADD COLUMN snapshot_serial_number character varying(150);

ALTER TABLE public.calibration_standard_usage
    ADD CONSTRAINT fk_calibration_standard_usage_master
        FOREIGN KEY (standard_id)
        REFERENCES public.standard_master (standard_id)
        ON DELETE RESTRICT,
    ADD CONSTRAINT chk_calibration_standard_usage_source
        CHECK (standard_id IS NOT NULL OR standard_instrument_id IS NOT NULL);

ALTER TABLE public.calibration_activity
    ALTER COLUMN activity_type DROP NOT NULL;

ALTER TABLE public.calibration_activity
    ADD COLUMN activity_id bigint,
    ADD COLUMN activity_name character varying(255);

ALTER TABLE public.calibration_activity
    ADD CONSTRAINT fk_calibration_activity_master
        FOREIGN KEY (activity_id)
        REFERENCES public.calibration_activity_master (activity_id)
        ON DELETE RESTRICT,
    ADD CONSTRAINT chk_calibration_activity_source
        CHECK (
            activity_id IS NOT NULL
            OR (activity_type IS NOT NULL AND length(btrim(activity_type)) > 0)
        ),
    ADD CONSTRAINT chk_calibration_activity_name
        CHECK (activity_name IS NULL OR length(btrim(activity_name)) > 0);

-- ---------------------------------------------------------------------------
-- 6. Test Component completion rules and snapshot schema_version 2
-- ---------------------------------------------------------------------------

ALTER TABLE public.test_column
    ADD COLUMN completion_rule character varying(20) NOT NULL DEFAULT 'not_configured';

ALTER TABLE public.test_column
    ADD CONSTRAINT chk_test_column_completion_rule
        CHECK (completion_rule IN ('required', 'optional', 'not_configured'));

CREATE OR REPLACE FUNCTION public.test_table_snapshot_wellformed(snapshot jsonb)
RETURNS boolean
LANGUAGE plpgsql
IMMUTABLE
PARALLEL SAFE
SET search_path = pg_catalog
AS $$
DECLARE
    column_row jsonb;
    point_row jsonb;
    seen_keys text[] := ARRAY[]::text[];
    column_key text;
    schema_version text;
BEGIN
    IF snapshot IS NULL OR jsonb_typeof(snapshot) IS DISTINCT FROM 'object' THEN
        RETURN false;
    END IF;

    schema_version := snapshot ->> 'schema_version';

    IF schema_version IS DISTINCT FROM '1'
       AND schema_version IS DISTINCT FROM '2' THEN
        RETURN false;
    END IF;

    IF (snapshot ->> 'duration_format') IS DISTINCT FROM 'hh:mm:ss' THEN
        RETURN false;
    END IF;

    BEGIN
        IF (snapshot ->> 'test_profile_id')::bigint IS NULL
           OR (snapshot ->> 'test_profile_version_id')::bigint IS NULL
           OR (snapshot ->> 'version_number')::integer IS NULL
           OR btrim(COALESCE(snapshot ->> 'profile_name', '')) = '' THEN
            RETURN false;
        END IF;
    EXCEPTION
        WHEN invalid_text_representation OR numeric_value_out_of_range THEN
            RETURN false;
    END;

    IF jsonb_typeof(snapshot -> 'columns') IS DISTINCT FROM 'array'
       OR jsonb_typeof(snapshot -> 'rows') IS DISTINCT FROM 'array' THEN
        RETURN false;
    END IF;

    FOR column_row IN
        SELECT value
        FROM jsonb_array_elements(snapshot -> 'columns')
    LOOP
        IF jsonb_typeof(column_row) IS DISTINCT FROM 'object' THEN
            RETURN false;
        END IF;

        column_key := column_row ->> 'column_key';

        IF column_key IS NULL
           OR column_key !~ '^[a-z][a-z0-9_]{0,62}$'
           OR column_key = ANY (seen_keys)
           OR btrim(COALESCE(column_row ->> 'column_label', '')) = ''
           OR COALESCE((column_row ->> 'column_order')::integer, 0) < 1
           OR (column_row ->> 'data_type') NOT IN ('numeric', 'text', 'duration')
           OR (column_row ->> 'value_source') NOT IN ('template', 'engineer', 'calculated')
           OR jsonb_typeof(column_row -> 'formula_inputs') IS DISTINCT FROM 'array' THEN
            RETURN false;
        END IF;

        IF schema_version = '2'
           AND (column_row ->> 'completion_rule') NOT IN ('required', 'optional', 'not_configured') THEN
            RETURN false;
        END IF;

        seen_keys := seen_keys || column_key;
    END LOOP;

    FOR point_row IN
        SELECT value
        FROM jsonb_array_elements(snapshot -> 'rows')
    LOOP
        IF jsonb_typeof(point_row) IS DISTINCT FROM 'object' THEN
            RETURN false;
        END IF;

        IF COALESCE((point_row ->> 'point_order')::integer, 0) < 1
           OR jsonb_typeof(point_row -> 'template_values') IS DISTINCT FROM 'object' THEN
            RETURN false;
        END IF;
    END LOOP;

    RETURN true;
END;
$$;

COMMENT ON COLUMN public.calibration_scope.test_table_snapshot IS
$$Frozen Test Table configuration. schema_version 1 remains valid.
schema_version 2 adds per-column completion_rule (required|optional|not_configured).
Existing published versions are not rewritten.$$;

-- ---------------------------------------------------------------------------
-- 7. Test Profile revision archive
-- ---------------------------------------------------------------------------

ALTER TABLE public.calibration_scope
    ADD CONSTRAINT uq_calibration_scope_id_calibration
        UNIQUE (calibration_scope_id, calibration_id);

CREATE TABLE public.calibration_test_profile_revision (
    revision_id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    calibration_id bigint NOT NULL,
    calibration_scope_id bigint NOT NULL,
    previous_test_profile_id bigint,
    previous_test_profile_version_id bigint,
    new_test_profile_id bigint,
    new_test_profile_version_id bigint,
    previous_structure jsonb NOT NULL,
    previous_results jsonb NOT NULL,
    proposed_mappings jsonb NOT NULL,
    confirmed_mappings jsonb NOT NULL,
    compatible_result_ids jsonb NOT NULL DEFAULT '[]'::jsonb,
    incompatible_result_ids jsonb NOT NULL DEFAULT '[]'::jsonb,
    reason text NOT NULL,
    revised_at timestamp with time zone NOT NULL,
    engineer_user_id bigint,
    CONSTRAINT fk_calibration_test_profile_revision_calibration
        FOREIGN KEY (calibration_id)
        REFERENCES public.calibration (calibration_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_calibration_test_profile_revision_scope_pair
        FOREIGN KEY (calibration_scope_id, calibration_id)
        REFERENCES public.calibration_scope (calibration_scope_id, calibration_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_calibration_test_profile_revision_engineer
        FOREIGN KEY (engineer_user_id)
        REFERENCES public.app_user (app_user_id)
        ON DELETE SET NULL,
    CONSTRAINT chk_calibration_test_profile_revision_reason
        CHECK (length(btrim(reason)) > 0),
    CONSTRAINT chk_calibration_test_profile_revision_structure
        CHECK (jsonb_typeof(previous_structure) = 'object'),
    CONSTRAINT chk_calibration_test_profile_revision_results
        CHECK (jsonb_typeof(previous_results) = 'object'),
    CONSTRAINT chk_calibration_test_profile_revision_proposed
        CHECK (jsonb_typeof(proposed_mappings) = 'object'),
    CONSTRAINT chk_calibration_test_profile_revision_confirmed
        CHECK (jsonb_typeof(confirmed_mappings) = 'object'),
    CONSTRAINT chk_calibration_test_profile_revision_compatible
        CHECK (jsonb_typeof(compatible_result_ids) = 'array'),
    CONSTRAINT chk_calibration_test_profile_revision_incompatible
        CHECK (jsonb_typeof(incompatible_result_ids) = 'array')
);

CREATE INDEX ix_calibration_test_profile_revision_session
    ON public.calibration_test_profile_revision (calibration_id, revised_at);

-- ---------------------------------------------------------------------------
-- 8. External Calibration documents
-- ---------------------------------------------------------------------------

CREATE TABLE public.calibration_session_setting (
    setting_key text PRIMARY KEY,
    setting_value text NOT NULL
);

INSERT INTO public.calibration_session_setting (setting_key, setting_value)
VALUES ('external_pdf_max_bytes', '20971520')
ON CONFLICT (setting_key) DO NOTHING;

CREATE TABLE public.calibration_external_document (
    document_id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    calibration_id bigint NOT NULL,
    storage_uuid uuid NOT NULL,
    storage_path text NOT NULL,
    original_filename text NOT NULL,
    mime_type character varying(100) NOT NULL,
    byte_size bigint NOT NULL,
    sha256 character varying(64) NOT NULL,
    is_active boolean NOT NULL,
    uploaded_at timestamp with time zone NOT NULL,
    superseded_at timestamp with time zone,
    uploaded_by_user_id bigint,
    CONSTRAINT uq_calibration_external_document_uuid UNIQUE (storage_uuid),
    CONSTRAINT fk_calibration_external_document_calibration
        FOREIGN KEY (calibration_id)
        REFERENCES public.calibration (calibration_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_calibration_external_document_uploaded_by
        FOREIGN KEY (uploaded_by_user_id)
        REFERENCES public.app_user (app_user_id)
        ON DELETE SET NULL,
    CONSTRAINT chk_calibration_external_document_mime
        CHECK (mime_type = 'application/pdf'),
    CONSTRAINT chk_calibration_external_document_filename
        CHECK (length(btrim(original_filename)) > 0),
    CONSTRAINT chk_calibration_external_document_path
        CHECK (length(btrim(storage_path)) > 0),
    CONSTRAINT chk_calibration_external_document_sha256
        CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    CONSTRAINT chk_calibration_external_document_size
        CHECK (byte_size > 0),
    CONSTRAINT chk_calibration_external_document_active
        CHECK (
            (is_active AND superseded_at IS NULL)
            OR (NOT is_active AND superseded_at IS NOT NULL)
        )
);

CREATE UNIQUE INDEX uq_calibration_external_document_active
    ON public.calibration_external_document (calibration_id)
    WHERE is_active;

-- ---------------------------------------------------------------------------
-- 9. Idempotent Internal Calibration Team seed
-- ---------------------------------------------------------------------------

INSERT INTO public.calibration_provider (
    provider_code,
    provider_name,
    provider_type,
    description,
    created_at
)
SELECT
    'INTERNAL_TEAM',
    'Internal Calibration Team',
    'INTERNAL',
    'System provider assigned automatically for Internal Calibration.',
    CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM public.calibration_provider
    WHERE provider_code = 'INTERNAL_TEAM'
);

-- ---------------------------------------------------------------------------
-- 10. Session lock, mutability, completeness
-- ---------------------------------------------------------------------------

CREATE FUNCTION public.calibration_session_lock(p_calibration_id bigint)
RETURNS public.calibration
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    rec public.calibration;
BEGIN
    SELECT *
    INTO rec
    FROM public.calibration
    WHERE calibration_id = p_calibration_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'calibration session % does not exist', p_calibration_id
            USING ERRCODE = '23503';
    END IF;

    RETURN rec;
END;
$$;

CREATE FUNCTION public.calibration_session_assert_mutable(p_calibration_id bigint)
RETURNS public.calibration
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    rec public.calibration;
BEGIN
    rec := public.calibration_session_lock(p_calibration_id);

    IF rec.session_status IN ('completed', 'cancelled') THEN
        RAISE EXCEPTION
            'calibration session % is terminal (%) and cannot be changed',
            p_calibration_id,
            rec.session_status
            USING ERRCODE = '25006';
    END IF;

    RETURN rec;
END;
$$;

CREATE FUNCTION public.calibration_requirement_effective_mode(
    p_frozen_mode text,
    p_resolved_mode text
)
RETURNS text
LANGUAGE sql
IMMUTABLE
SET search_path = pg_catalog
AS $$
    SELECT CASE
        WHEN p_frozen_mode IN ('required', 'optional') THEN p_frozen_mode
        WHEN p_frozen_mode = 'not_configured' AND p_resolved_mode IN ('required', 'optional') THEN p_resolved_mode
        ELSE 'not_configured'
    END;
$$;

CREATE FUNCTION public.calibration_session_assert_identity_frozen(rec public.calibration)
RETURNS void
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF rec.snapshot_instrument_name IS NULL
       OR btrim(rec.snapshot_instrument_name) = ''
       OR rec.snapshot_instrument_type_name IS NULL
       OR btrim(rec.snapshot_instrument_type_name) = ''
       OR rec.snapshot_location_id IS NULL
       OR rec.snapshot_detail_location_id IS NULL
       OR rec.snapshot_location_code IS NULL
       OR rec.snapshot_location_name IS NULL
       OR rec.snapshot_detail_location_code IS NULL
       OR rec.snapshot_detail_location_name IS NULL THEN
        RAISE EXCEPTION
            'complete requires a frozen instrument identity and location snapshot'
            USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE FUNCTION public.calibration_session_assert_environment_complete(p_calibration_id bigint)
RETURNS void
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    snap public.calibration_requirement_snapshot;
    effective_mode text;
    required_count integer;
    missing_count integer;
BEGIN
    SELECT *
    INTO snap
    FROM public.calibration_requirement_snapshot
    WHERE calibration_id = p_calibration_id
      AND requirement_category = 'environment';

    IF NOT FOUND THEN
        RAISE EXCEPTION 'environment requirement was not frozen at start'
            USING ERRCODE = '23514';
    END IF;

    effective_mode := public.calibration_requirement_effective_mode(
        snap.frozen_mode,
        snap.resolved_mode
    );

    IF effective_mode = 'not_configured' THEN
        RAISE EXCEPTION 'environment is not configured'
            USING ERRCODE = '23514';
    END IF;

    IF snap.configuration_confirmed_at IS NULL THEN
        RAISE EXCEPTION
            'environment requires explicit configuration confirmation before complete'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.calibration_environment_parameter_snapshot
        WHERE calibration_id = p_calibration_id
          AND NOT inclusion_confirmed
    ) THEN
        RAISE EXCEPTION
            'unconfirmed environment parameter inclusions block complete'
            USING ERRCODE = '23514';
    END IF;

    SELECT COUNT(*)
    INTO required_count
    FROM public.calibration_environment_parameter_snapshot
    WHERE calibration_id = p_calibration_id
      AND inclusion_confirmed
      AND requirement_mode = 'required';

    IF effective_mode = 'required' AND required_count < 1 THEN
        RAISE EXCEPTION
            'required environment needs at least one confirmed required parameter'
            USING ERRCODE = '23514';
    END IF;

    SELECT COUNT(*)
    INTO missing_count
    FROM public.calibration_environment_parameter_snapshot s
    LEFT JOIN public.calibration_environment_value v
        ON v.calibration_id = s.calibration_id
       AND v.parameter_id = s.parameter_id
    WHERE s.calibration_id = p_calibration_id
      AND s.inclusion_confirmed
      AND s.requirement_mode = 'required'
      AND (
          (s.data_type = 'numeric' AND v.numeric_value IS NULL)
          OR (s.data_type = 'text' AND (v.text_value IS NULL OR length(btrim(v.text_value)) = 0))
      );

    IF missing_count > 0 THEN
        RAISE EXCEPTION
            'required environment parameters are missing values; confirmation does not bypass this check'
            USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE FUNCTION public.calibration_session_assert_test_components(p_calibration_id bigint)
RETURNS void
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    scope_row public.calibration_scope;
    column_row jsonb;
    point_row jsonb;
    schema_version text;
    completion_rule text;
    column_key text;
    result_id bigint;
    value_present boolean;
BEGIN
    FOR scope_row IN
        SELECT *
        FROM public.calibration_scope
        WHERE calibration_id = p_calibration_id
          AND test_table_snapshot IS NOT NULL
    LOOP
        schema_version := scope_row.test_table_snapshot ->> 'schema_version';

        IF schema_version IS DISTINCT FROM '2' THEN
            RAISE EXCEPTION
                'a used test profile without schema_version 2 completion rules cannot be completed'
                USING ERRCODE = '23514';
        END IF;

        FOR column_row IN
            SELECT value
            FROM jsonb_array_elements(scope_row.test_table_snapshot -> 'columns')
        LOOP
            completion_rule := column_row ->> 'completion_rule';
            column_key := column_row ->> 'column_key';

            IF completion_rule IS DISTINCT FROM 'required'
               AND completion_rule IS DISTINCT FROM 'optional' THEN
                RAISE EXCEPTION
                    'unconfigured test component % blocks complete',
                    column_key
                    USING ERRCODE = '23514';
            END IF;

            IF completion_rule IS DISTINCT FROM 'required' THEN
                CONTINUE;
            END IF;

            FOR point_row IN
                SELECT value
                FROM jsonb_array_elements(scope_row.test_table_snapshot -> 'rows')
            LOOP
                SELECT calibration_test_result_id
                INTO result_id
                FROM public.calibration_test_result
                WHERE calibration_scope_id = scope_row.calibration_scope_id
                  AND test_point_id = (point_row ->> 'test_point_id')::bigint;

                IF result_id IS NULL THEN
                    RAISE EXCEPTION
                        'required test component % is missing a result row',
                        column_key
                        USING ERRCODE = '23514';
                END IF;

                SELECT (
                    numeric_value IS NOT NULL
                    OR (text_value IS NOT NULL AND length(btrim(text_value)) > 0)
                )
                INTO value_present
                FROM public.calibration_result_value
                WHERE calibration_test_result_id = result_id
                  AND column_key = column_row ->> 'column_key';

                IF value_present IS DISTINCT FROM TRUE THEN
                    RAISE EXCEPTION
                        'required test component % is missing an engineer-entered value',
                        column_key
                        USING ERRCODE = '23514';
                END IF;
            END LOOP;
        END LOOP;
    END LOOP;
END;
$$;

CREATE FUNCTION public.calibration_session_assert_complete(rec public.calibration)
RETURNS void
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    snap public.calibration_requirement_snapshot;
    effective_mode text;
    found_categories integer;
    usage_count integer;
    activity_count integer;
    active_pdf_count integer;
    pinned_count integer;
    internal_provider_id bigint;
BEGIN
    IF rec.completed_at IS NULL
       OR rec.calibration_date IS NULL
       OR rec.calibration_due IS NULL THEN
        RAISE EXCEPTION 'complete requires dates and completed_at'
            USING ERRCODE = '23514';
    END IF;

    IF rec.calibration_due < rec.calibration_date THEN
        RAISE EXCEPTION 'complete requires calibration_due >= calibration_date'
            USING ERRCODE = '23514';
    END IF;

    PERFORM public.calibration_session_assert_identity_frozen(rec);

    IF rec.calibration_method = 'EXTERNAL' THEN
        SELECT COUNT(*)
        INTO active_pdf_count
        FROM public.calibration_external_document
        WHERE calibration_id = rec.calibration_id
          AND is_active;

        IF active_pdf_count IS DISTINCT FROM 1 THEN
            RAISE EXCEPTION 'external complete requires exactly one active vendor PDF'
                USING ERRCODE = '23514';
        END IF;

        RETURN;
    END IF;

    IF rec.calibration_method IS DISTINCT FROM 'INTERNAL' THEN
        RAISE EXCEPTION 'unknown calibration method'
            USING ERRCODE = '23514';
    END IF;

    SELECT calibration_provider_id
    INTO internal_provider_id
    FROM public.calibration_provider
    WHERE provider_code = 'INTERNAL_TEAM';

    IF rec.calibration_provider_id IS NULL
       OR rec.calibration_provider_id IS DISTINCT FROM internal_provider_id THEN
        RAISE EXCEPTION 'internal complete requires Internal Calibration Team'
            USING ERRCODE = '23514';
    END IF;

    SELECT COUNT(*)
    INTO found_categories
    FROM public.calibration_requirement_snapshot
    WHERE calibration_id = rec.calibration_id
      AND requirement_category IN ('standards', 'activity', 'environment', 'test_profile');

    IF found_categories IS DISTINCT FROM 4 THEN
        RAISE EXCEPTION 'all four requirement categories must be frozen at start'
            USING ERRCODE = '23514';
    END IF;

    FOR snap IN
        SELECT *
        FROM public.calibration_requirement_snapshot
        WHERE calibration_id = rec.calibration_id
    LOOP
        effective_mode := public.calibration_requirement_effective_mode(
            snap.frozen_mode,
            snap.resolved_mode
        );

        IF effective_mode = 'not_configured' THEN
            RAISE EXCEPTION '% is not configured', snap.requirement_category
                USING ERRCODE = '23514';
        END IF;

        IF snap.requirement_category = 'standards' AND effective_mode = 'required' THEN
            SELECT COUNT(*) INTO usage_count
            FROM public.calibration_standard_usage
            WHERE calibration_id = rec.calibration_id;

            IF usage_count < 1 THEN
                RAISE EXCEPTION 'required standards used needs at least one standard'
                    USING ERRCODE = '23514';
            END IF;
        END IF;

        IF snap.requirement_category = 'activity' AND effective_mode = 'required' THEN
            SELECT COUNT(*) INTO activity_count
            FROM public.calibration_activity
            WHERE calibration_id = rec.calibration_id;

            IF activity_count < 1 THEN
                RAISE EXCEPTION 'required calibration activity needs at least one activity'
                    USING ERRCODE = '23514';
            END IF;
        END IF;

        IF snap.requirement_category = 'test_profile' THEN
            SELECT COUNT(*) INTO pinned_count
            FROM public.calibration_scope
            WHERE calibration_id = rec.calibration_id
              AND test_profile_version_id IS NOT NULL
              AND test_table_snapshot IS NOT NULL;

            IF effective_mode = 'required' AND pinned_count < 1 THEN
                RAISE EXCEPTION 'required test profile must be pinned'
                    USING ERRCODE = '23514';
            END IF;

            IF pinned_count > 0 THEN
                PERFORM public.calibration_session_assert_test_components(rec.calibration_id);
            END IF;
        END IF;
    END LOOP;

    PERFORM public.calibration_session_assert_environment_complete(rec.calibration_id);
END;
$$;

-- ---------------------------------------------------------------------------
-- 11. Terminal immutability and child protection
-- ---------------------------------------------------------------------------

CREATE FUNCTION public.trg_calibration_session_guard()
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

CREATE TRIGGER trg_calibration_session_guard
    BEFORE INSERT OR UPDATE OR DELETE ON public.calibration
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_session_guard();

CREATE FUNCTION public.trg_calibration_child_mutable()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    old_id bigint;
    new_id bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM public.calibration_session_assert_mutable(OLD.calibration_id);
        RETURN OLD;
    END IF;

    IF TG_OP = 'UPDATE' THEN
        old_id := OLD.calibration_id;
        new_id := NEW.calibration_id;
        PERFORM public.calibration_session_assert_mutable(old_id);
        IF new_id IS DISTINCT FROM old_id THEN
            PERFORM public.calibration_session_assert_mutable(new_id);
        END IF;
        RETURN NEW;
    END IF;

    PERFORM public.calibration_session_assert_mutable(NEW.calibration_id);
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.trg_calibration_nested_from_scope()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    parent_id bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN
        SELECT calibration_id INTO parent_id
        FROM public.calibration_scope
        WHERE calibration_scope_id = OLD.calibration_scope_id;
        IF parent_id IS NOT NULL THEN
            PERFORM public.calibration_session_assert_mutable(parent_id);
        END IF;
        RETURN OLD;
    END IF;

    SELECT calibration_id INTO parent_id
    FROM public.calibration_scope
    WHERE calibration_scope_id = NEW.calibration_scope_id;
    IF parent_id IS NULL THEN
        RAISE EXCEPTION 'calibration scope % does not exist', NEW.calibration_scope_id
            USING ERRCODE = '23503';
    END IF;
    PERFORM public.calibration_session_assert_mutable(parent_id);

    IF TG_OP = 'UPDATE' AND NEW.calibration_scope_id IS DISTINCT FROM OLD.calibration_scope_id THEN
        SELECT calibration_id INTO parent_id
        FROM public.calibration_scope
        WHERE calibration_scope_id = OLD.calibration_scope_id;
        IF parent_id IS NOT NULL THEN
            PERFORM public.calibration_session_assert_mutable(parent_id);
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE FUNCTION public.trg_calibration_nested_from_result()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    parent_id bigint;
    result_id bigint;
BEGIN
    result_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.calibration_test_result_id ELSE NEW.calibration_test_result_id END;

    SELECT s.calibration_id
    INTO parent_id
    FROM public.calibration_test_result r
    JOIN public.calibration_scope s
        ON s.calibration_scope_id = r.calibration_scope_id
    WHERE r.calibration_test_result_id = result_id;

    IF parent_id IS NOT NULL THEN
        PERFORM public.calibration_session_assert_mutable(parent_id);
    END IF;

    IF TG_OP = 'UPDATE'
       AND NEW.calibration_test_result_id IS DISTINCT FROM OLD.calibration_test_result_id THEN
        SELECT s.calibration_id
        INTO parent_id
        FROM public.calibration_test_result r
        JOIN public.calibration_scope s
            ON s.calibration_scope_id = r.calibration_scope_id
        WHERE r.calibration_test_result_id = OLD.calibration_test_result_id;

        IF parent_id IS NOT NULL THEN
            PERFORM public.calibration_session_assert_mutable(parent_id);
        END IF;
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_calibration_activity_mutable
    BEFORE INSERT OR UPDATE OR DELETE ON public.calibration_activity
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_child_mutable();

CREATE TRIGGER trg_calibration_environment_mutable
    BEFORE INSERT OR UPDATE OR DELETE ON public.calibration_environment
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_child_mutable();

CREATE TRIGGER trg_calibration_standard_usage_mutable
    BEFORE INSERT OR UPDATE OR DELETE ON public.calibration_standard_usage
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_child_mutable();

CREATE TRIGGER trg_calibration_scope_mutable
    BEFORE INSERT OR UPDATE OR DELETE ON public.calibration_scope
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_child_mutable();

CREATE TRIGGER trg_calibration_requirement_snapshot_mutable
    BEFORE INSERT OR UPDATE OR DELETE ON public.calibration_requirement_snapshot
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_child_mutable();

CREATE TRIGGER trg_calibration_environment_parameter_snapshot_mutable
    BEFORE INSERT OR UPDATE OR DELETE ON public.calibration_environment_parameter_snapshot
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_child_mutable();

CREATE TRIGGER trg_calibration_environment_value_mutable
    BEFORE INSERT OR UPDATE OR DELETE ON public.calibration_environment_value
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_child_mutable();

CREATE TRIGGER trg_calibration_external_document_mutable
    BEFORE INSERT OR UPDATE OR DELETE ON public.calibration_external_document
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_child_mutable();

CREATE TRIGGER trg_calibration_test_profile_revision_mutable
    BEFORE INSERT OR UPDATE OR DELETE ON public.calibration_test_profile_revision
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_child_mutable();

CREATE TRIGGER trg_calibration_test_result_mutable
    BEFORE INSERT OR UPDATE OR DELETE ON public.calibration_test_result
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_nested_from_scope();

CREATE TRIGGER trg_calibration_result_value_mutable
    BEFORE INSERT OR UPDATE OR DELETE ON public.calibration_result_value
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_nested_from_result();

CREATE TRIGGER trg_calibration_reading_mutable
    BEFORE INSERT OR UPDATE OR DELETE ON public.calibration_reading
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_nested_from_result();

CREATE FUNCTION public.trg_calibration_requirement_snapshot_freeze()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'frozen requirement snapshots cannot be deleted'
            USING ERRCODE = '25006';
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF NEW.requirement_category IS DISTINCT FROM OLD.requirement_category
           OR NEW.frozen_mode IS DISTINCT FROM OLD.frozen_mode
           OR NEW.calibration_id IS DISTINCT FROM OLD.calibration_id THEN
            RAISE EXCEPTION 'frozen requirement snapshots cannot be overwritten'
                USING ERRCODE = '25006';
        END IF;

        IF OLD.resolved_mode IS NOT NULL
           AND (
               NEW.resolved_mode IS DISTINCT FROM OLD.resolved_mode
               OR NEW.resolved_at IS DISTINCT FROM OLD.resolved_at
               OR NEW.resolved_reason IS DISTINCT FROM OLD.resolved_reason
               OR NEW.resolved_by_user_id IS DISTINCT FROM OLD.resolved_by_user_id
           ) THEN
            RAISE EXCEPTION 'a not-configured requirement can be resolved only once'
                USING ERRCODE = '25006';
        END IF;

        IF OLD.resolved_mode IS NULL AND NEW.resolved_mode IS NOT NULL
           AND OLD.frozen_mode IS DISTINCT FROM 'not_configured' THEN
            RAISE EXCEPTION 'only not-configured requirements may be resolved'
                USING ERRCODE = '25006';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_calibration_requirement_snapshot_freeze
    BEFORE UPDATE OR DELETE ON public.calibration_requirement_snapshot
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_requirement_snapshot_freeze();

CREATE FUNCTION public.trg_calibration_environment_parameter_snapshot_freeze()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'frozen environment parameter definitions cannot be deleted'
            USING ERRCODE = '25006';
    END IF;

    IF NEW.parameter_id IS DISTINCT FROM OLD.parameter_id
       OR NEW.calibration_id IS DISTINCT FROM OLD.calibration_id
       OR NEW.parameter_code IS DISTINCT FROM OLD.parameter_code
       OR NEW.parameter_name IS DISTINCT FROM OLD.parameter_name
       OR NEW.unit IS DISTINCT FROM OLD.unit
       OR NEW.data_type IS DISTINCT FROM OLD.data_type
       OR NEW.requirement_mode IS DISTINCT FROM OLD.requirement_mode THEN
        RAISE EXCEPTION 'frozen environment parameter definitions cannot be overwritten'
            USING ERRCODE = '25006';
    END IF;

    IF OLD.inclusion_confirmed
       AND NEW.inclusion_confirmed IS DISTINCT FROM TRUE THEN
        RAISE EXCEPTION 'confirmed environment parameters cannot be un-confirmed'
            USING ERRCODE = '25006';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_calibration_environment_parameter_snapshot_freeze
    BEFORE UPDATE OR DELETE ON public.calibration_environment_parameter_snapshot
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_environment_parameter_snapshot_freeze();

CREATE FUNCTION public.trg_calibration_test_profile_revision_immutable()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_OP = 'DELETE' OR TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION 'test profile revision history is immutable'
            USING ERRCODE = '25006';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_calibration_test_profile_revision_immutable
    BEFORE UPDATE OR DELETE ON public.calibration_test_profile_revision
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_test_profile_revision_immutable();

CREATE FUNCTION public.trg_calibration_external_document_rules()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    max_bytes bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'external documents cannot be deleted'
            USING ERRCODE = '25006';
    END IF;

    SELECT setting_value::bigint
    INTO max_bytes
    FROM public.calibration_session_setting
    WHERE setting_key = 'external_pdf_max_bytes';

    IF max_bytes IS NULL THEN
        max_bytes := 20971520;
    END IF;

    IF NEW.byte_size > max_bytes THEN
        RAISE EXCEPTION 'external PDF exceeds configured size limit'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF NEW.storage_uuid IS DISTINCT FROM OLD.storage_uuid
           OR NEW.storage_path IS DISTINCT FROM OLD.storage_path
           OR NEW.sha256 IS DISTINCT FROM OLD.sha256 THEN
            RAISE EXCEPTION 'external document storage identity cannot change'
                USING ERRCODE = '25006';
        END IF;

        IF OLD.is_active AND NEW.is_active IS DISTINCT FROM TRUE
           AND NEW.superseded_at IS NULL THEN
            RAISE EXCEPTION 'superseded documents require superseded_at'
                USING ERRCODE = '23514';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_calibration_external_document_rules
    BEFORE INSERT OR UPDATE OR DELETE ON public.calibration_external_document
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_external_document_rules();

CREATE FUNCTION public.trg_calibration_external_document_supersede()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF NOT NEW.is_active THEN
        RETURN NEW;
    END IF;

    PERFORM 1
    FROM public.calibration_external_document
    WHERE calibration_id = NEW.calibration_id
      AND is_active
      AND document_id IS DISTINCT FROM NEW.document_id
    FOR UPDATE;

    UPDATE public.calibration_external_document
    SET is_active = FALSE,
        superseded_at = COALESCE(superseded_at, CURRENT_TIMESTAMP)
    WHERE calibration_id = NEW.calibration_id
      AND is_active
      AND document_id IS DISTINCT FROM NEW.document_id;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_calibration_external_document_supersede
    BEFORE INSERT OR UPDATE OF is_active ON public.calibration_external_document
    FOR EACH ROW
    WHEN (NEW.is_active)
    EXECUTE FUNCTION public.trg_calibration_external_document_supersede();

CREATE FUNCTION public.trg_instrument_type_requirement_environment()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    required_params integer;
BEGIN
    IF NEW.requirement_category = 'environment'
       AND NEW.requirement_mode = 'required' THEN
        SELECT COUNT(*)
        INTO required_params
        FROM public.instrument_type_environment_parameter
        WHERE instrument_type_id = NEW.instrument_type_id
          AND requirement_mode = 'required';

        IF required_params < 1 THEN
            RAISE EXCEPTION
                'environment required on an instrument type needs at least one required parameter'
                USING ERRCODE = '23514';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_instrument_type_requirement_environment
    BEFORE INSERT OR UPDATE ON public.instrument_type_requirement
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_instrument_type_requirement_environment();

-- ---------------------------------------------------------------------------
-- 12. Temperature / Relative Humidity dual-write
-- ---------------------------------------------------------------------------

CREATE FUNCTION public.calibration_environment_ensure_legacy_row(p_calibration_id bigint)
RETURNS void
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    INSERT INTO public.calibration_environment (calibration_id, created_at)
    VALUES (p_calibration_id, CURRENT_TIMESTAMP)
    ON CONFLICT (calibration_id) DO NOTHING;
END;
$$;

CREATE FUNCTION public.calibration_environment_project_legacy(
    p_calibration_id bigint,
    p_parameter_id bigint,
    p_numeric_value numeric
)
RETURNS void
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    rec public.calibration_environment_parameter_snapshot;
BEGIN
    SELECT *
    INTO rec
    FROM public.calibration_environment_parameter_snapshot
    WHERE calibration_id = p_calibration_id
      AND parameter_id = p_parameter_id;

    IF NOT FOUND THEN
        RETURN;
    END IF;

    IF rec.parameter_code NOT IN ('temperature', 'relative_humidity') THEN
        RETURN;
    END IF;

    PERFORM set_config('calibration.project_legacy', '1', true);

    PERFORM public.calibration_environment_ensure_legacy_row(p_calibration_id);

    IF rec.parameter_code = 'temperature' THEN
        UPDATE public.calibration_environment
        SET temperature_value = p_numeric_value,
            temperature_unit = CASE
                WHEN p_numeric_value IS NULL THEN NULL
                ELSE rec.unit
            END
        WHERE calibration_id = p_calibration_id;
    ELSE
        UPDATE public.calibration_environment
        SET humidity_value = p_numeric_value,
            humidity_unit = CASE
                WHEN p_numeric_value IS NULL THEN NULL
                ELSE rec.unit
            END
        WHERE calibration_id = p_calibration_id;
    END IF;

    PERFORM set_config('calibration.project_legacy', '0', true);
EXCEPTION
    WHEN OTHERS THEN
        PERFORM set_config('calibration.project_legacy', '0', true);
        RAISE;
END;
$$;

CREATE FUNCTION public.trg_calibration_environment_value_sync()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM public.calibration_environment_project_legacy(
            OLD.calibration_id,
            OLD.parameter_id,
            NULL
        );
        RETURN OLD;
    END IF;

    IF TG_OP = 'UPDATE'
       AND (
           NEW.calibration_id IS DISTINCT FROM OLD.calibration_id
           OR NEW.parameter_id IS DISTINCT FROM OLD.parameter_id
       ) THEN
        PERFORM public.calibration_environment_project_legacy(
            OLD.calibration_id,
            OLD.parameter_id,
            NULL
        );
    END IF;

    PERFORM public.calibration_environment_project_legacy(
        NEW.calibration_id,
        NEW.parameter_id,
        NEW.numeric_value
    );

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_calibration_environment_value_sync
    AFTER INSERT OR UPDATE OR DELETE ON public.calibration_environment_value
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_environment_value_sync();

CREATE FUNCTION public.trg_calibration_environment_legacy_sync()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    has_canonical_projection boolean;
    session_id bigint;
    other_id bigint;
BEGIN
    IF current_setting('calibration.project_legacy', true) = '1' THEN
        IF TG_OP = 'DELETE' THEN
            RETURN OLD;
        END IF;
        RETURN NEW;
    END IF;

    IF TG_OP = 'DELETE' THEN
        session_id := OLD.calibration_id;
        other_id := OLD.calibration_id;
    ELSE
        session_id := NEW.calibration_id;
        other_id := CASE WHEN TG_OP = 'UPDATE' THEN OLD.calibration_id ELSE NEW.calibration_id END;
    END IF;

    SELECT EXISTS (
        SELECT 1
        FROM public.calibration_environment_parameter_snapshot
        WHERE calibration_id IN (session_id, other_id)
          AND parameter_code IN ('temperature', 'relative_humidity')
    )
    INTO has_canonical_projection;

    IF NOT has_canonical_projection THEN
        IF TG_OP = 'DELETE' THEN
            RETURN OLD;
        END IF;
        RETURN NEW;
    END IF;

    IF TG_OP = 'INSERT'
       AND NEW.temperature_value IS NULL
       AND NEW.humidity_value IS NULL THEN
        RETURN NEW;
    END IF;

    IF TG_OP = 'UPDATE'
       AND NEW.temperature_value IS NOT DISTINCT FROM OLD.temperature_value
       AND NEW.temperature_unit IS NOT DISTINCT FROM OLD.temperature_unit
       AND NEW.humidity_value IS NOT DISTINCT FROM OLD.humidity_value
       AND NEW.humidity_unit IS NOT DISTINCT FROM OLD.humidity_unit
       AND NEW.calibration_id IS NOT DISTINCT FROM OLD.calibration_id THEN
        RETURN NEW;
    END IF;

    RAISE EXCEPTION
        'Temperature and Relative Humidity are projected from canonical environment values; update calibration_environment_value'
        USING ERRCODE = '25006';
END;
$$;

CREATE TRIGGER trg_calibration_environment_legacy_sync
    BEFORE INSERT OR UPDATE OR DELETE ON public.calibration_environment
    FOR EACH ROW
    EXECUTE FUNCTION public.trg_calibration_environment_legacy_sync();

COMMIT;
