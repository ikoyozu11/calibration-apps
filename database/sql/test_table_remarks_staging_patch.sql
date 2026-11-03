-- REVIEW ONLY. Do not execute automatically.
-- Staging database guard: calibration_staging_db only; never calibration_db.
BEGIN;
DO $$
BEGIN
    IF current_database() <> 'calibration_staging_db' THEN
        RAISE EXCEPTION 'staging-only patch refused on database %', current_database();
    END IF;
END;
$$;

ALTER TABLE public.test_profile_version
    ADD COLUMN IF NOT EXISTS remarks text;

CREATE OR REPLACE FUNCTION public.test_profile_version_fork(p_source_version_id bigint)
RETURNS bigint
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    source public.test_profile_version%ROWTYPE;
    new_id bigint;
    next_number integer;
    old_column record;
    new_column_id bigint;
    column_map jsonb := '{}'::jsonb;
    old_point record;
    new_point_id bigint;
    point_map jsonb := '{}'::jsonb;
    old_cell record;
BEGIN
    SELECT * INTO source FROM public.test_profile_version
    WHERE test_profile_version_id = p_source_version_id FOR SHARE;
    IF NOT FOUND THEN RAISE EXCEPTION 'test profile version % does not exist', p_source_version_id USING ERRCODE = '23503'; END IF;
    IF source.status = 'draft' THEN RAISE EXCEPTION 'fork a published version, not a draft' USING ERRCODE = '23514'; END IF;
    PERFORM 1 FROM public.test_profile WHERE test_profile_id = source.test_profile_id FOR UPDATE;
    IF EXISTS (SELECT 1 FROM public.test_profile_version WHERE test_profile_id = source.test_profile_id AND status = 'draft') THEN
        RAISE EXCEPTION 'a draft version already exists for this test table' USING ERRCODE = '23514';
    END IF;
    SELECT max(version_number) + 1 INTO next_number FROM public.test_profile_version WHERE test_profile_id = source.test_profile_id;
    INSERT INTO public.test_profile_version (test_profile_id, version_number, status, profile_name, display_title, description, remarks, published_at)
    VALUES (source.test_profile_id, next_number, 'draft', source.profile_name, source.display_title, source.description, source.remarks, NULL)
    RETURNING test_profile_version_id INTO new_id;
    FOR old_column IN SELECT * FROM public.test_column WHERE test_profile_version_id = source.test_profile_version_id ORDER BY CASE WHEN value_source = 'calculated' THEN 1 ELSE 0 END, column_order, test_column_id LOOP
        INSERT INTO public.test_column (test_profile_id, test_profile_version_id, column_key, column_label, column_order, data_type, value_source, unit, display_scale, formula_code, formula_inputs)
        VALUES (old_column.test_profile_id, new_id, old_column.column_key, old_column.column_label, old_column.column_order, old_column.data_type, old_column.value_source, old_column.unit, old_column.display_scale, old_column.formula_code, old_column.formula_inputs)
        RETURNING test_column_id INTO new_column_id;
        column_map := column_map || jsonb_build_object(old_column.test_column_id::text, new_column_id);
    END LOOP;
    FOR old_point IN SELECT * FROM public.test_point WHERE test_profile_version_id = source.test_profile_version_id ORDER BY point_order, test_point_id LOOP
        INSERT INTO public.test_point (test_profile_id, test_profile_version_id, point_order, nominal_value, unit, tolerance_plus, tolerance_minus, description)
        VALUES (old_point.test_profile_id, new_id, old_point.point_order, old_point.nominal_value, old_point.unit, old_point.tolerance_plus, old_point.tolerance_minus, old_point.description)
        RETURNING test_point_id INTO new_point_id;
        point_map := point_map || jsonb_build_object(old_point.test_point_id::text, new_point_id);
    END LOOP;
    FOR old_cell IN SELECT * FROM public.test_point_value WHERE test_profile_version_id = source.test_profile_version_id ORDER BY test_point_value_id LOOP
        INSERT INTO public.test_point_value (test_profile_id, test_profile_version_id, test_point_id, test_column_id, numeric_value, text_value, unit)
        VALUES (old_cell.test_profile_id, new_id, (point_map ->> old_cell.test_point_id::text)::bigint, (column_map ->> old_cell.test_column_id::text)::bigint, old_cell.numeric_value, old_cell.text_value, old_cell.unit);
    END LOOP;
    RETURN new_id;
END;
$$;

COMMIT;

-- Rollback: restore the previous test_profile_version_fork function definition
-- from database/sql/dynamic_test_table_versioning.sql. Do not drop remarks if
-- any draft or snapshot now depends on it; dropping the column is destructive.
