-- Staging-only patch for the existing calibration completion trigger rules.
-- Do not run this file against production or against calibration_db.
-- The expected staging database name is calibration_staging_db.

BEGIN;

DO $$
BEGIN
    IF current_database() <> 'calibration_staging_db' THEN
        RAISE EXCEPTION
            'staging-only patch refused on database %', current_database();
    END IF;
END;
$$;

-- The existing triggers are intentionally unchanged. They already invoke these
-- functions; CREATE OR REPLACE keeps the patch idempotent and trigger-only.
CREATE OR REPLACE FUNCTION public.calibration_session_assert_environment_complete(p_calibration_id bigint)
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

    effective_mode := CASE
        WHEN snap.frozen_mode IN ('required', 'optional') THEN snap.frozen_mode
        WHEN snap.resolved_mode IN ('required', 'optional') THEN snap.resolved_mode
        ELSE 'required'
    END;

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

    IF (
        SELECT COUNT(*)
        FROM public.calibration_environment_parameter_snapshot
        WHERE calibration_id = p_calibration_id
          AND parameter_code IN ('temperature', 'relative_humidity')
          AND inclusion_confirmed
          AND requirement_mode = 'required'
    ) IS DISTINCT FROM 2 THEN
        RAISE EXCEPTION
            'internal environment requires required Temperature and Relative Humidity snapshots'
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

CREATE OR REPLACE FUNCTION public.calibration_session_assert_test_components(p_calibration_id bigint)
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
            completion_rule := COALESCE(column_row ->> 'completion_rule', 'not_configured');
            column_key := column_row ->> 'column_key';

            IF completion_rule IS DISTINCT FROM 'required'
               AND completion_rule IS DISTINCT FROM 'optional'
               AND completion_rule IS DISTINCT FROM 'not_configured' THEN
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

CREATE OR REPLACE FUNCTION public.calibration_session_assert_complete(rec public.calibration)
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
        effective_mode := CASE
            WHEN snap.requirement_category = 'activity' THEN 'optional'
            WHEN snap.frozen_mode IN ('required', 'optional') THEN snap.frozen_mode
            WHEN snap.resolved_mode IN ('required', 'optional') THEN snap.resolved_mode
            ELSE 'optional'
        END;

        IF snap.requirement_category = 'standards' AND effective_mode = 'required' THEN
            SELECT COUNT(*) INTO usage_count
            FROM public.calibration_standard_usage
            WHERE calibration_id = rec.calibration_id;

            IF usage_count < 1 THEN
                RAISE EXCEPTION 'required standards used needs at least one standard'
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

COMMIT;

-- Rollback: restore the three function bodies from the exact foundation SQL
-- revision that was installed immediately before this patch. Do not use a
-- broad schema rollback and do not recreate or drop any trigger.
