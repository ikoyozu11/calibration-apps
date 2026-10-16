-- Additive duration and correction-formula support for existing Dynamic Test Tables.
-- Does not drop tables or change existing rows. Run after dynamic_test_table_schema.sql.
BEGIN;

ALTER TABLE public.test_column
    DROP CONSTRAINT IF EXISTS ck_test_column_data_type;

ALTER TABLE public.test_column
    ADD CONSTRAINT ck_test_column_data_type
        CHECK (data_type IN ('numeric', 'text', 'duration'));

ALTER TABLE public.test_column
    DROP CONSTRAINT IF EXISTS ck_test_column_formula;

ALTER TABLE public.test_column
    ADD CONSTRAINT ck_test_column_formula
        CHECK (
            (
                value_source = 'calculated'
                AND formula_code IS NOT NULL
                AND (
                    (
                        data_type = 'numeric'
                        AND (
                            (formula_code = 'average' AND cardinality(formula_inputs) >= 1)
                            OR (formula_code IN ('deviation', 'standard_minus_reading', 'reading_minus_standard') AND cardinality(formula_inputs) = 2)
                        )
                    )
                    OR (
                        data_type = 'duration'
                        AND formula_code IN ('deviation', 'standard_minus_reading', 'reading_minus_standard')
                        AND cardinality(formula_inputs) = 2
                    )
                )
            )
            OR (
                value_source IN ('template', 'engineer')
                AND formula_code IS NULL
                AND cardinality(formula_inputs) = 0
            )
        );

CREATE OR REPLACE FUNCTION public.test_column_assert_profile(profile_id bigint)
RETURNS void
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    formula record;
    source record;
    found_count integer;
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM public.test_profile
        WHERE test_profile_id = profile_id
    ) THEN
        IF EXISTS (
            SELECT 1
            FROM public.test_column
            WHERE test_profile_id = profile_id
        ) THEN
            RAISE EXCEPTION 'test columns remain for a missing profile'
                USING ERRCODE = '23503', CONSTRAINT = 'fk_test_column_test_profile';
        END IF;

        RETURN;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.test_column AS column_row
        JOIN public.test_point_value AS cell
          ON cell.test_profile_id = column_row.test_profile_id
         AND cell.test_column_id = column_row.test_column_id
        WHERE column_row.test_profile_id = profile_id
          AND (
              column_row.value_source <> 'template'
              OR (column_row.data_type IN ('numeric', 'duration') AND cell.text_value IS NOT NULL)
              OR (column_row.data_type = 'text' AND cell.numeric_value IS NOT NULL)
              OR (
                    column_row.data_type = 'duration'
                    AND cell.numeric_value IS NOT NULL
                    AND cell.numeric_value <> trunc(cell.numeric_value)
              )
          )
    ) THEN
        RAISE EXCEPTION 'stored cell does not match the column source or data type'
            USING ERRCODE = '23514', CONSTRAINT = 'test_column_cell_conflict';
    END IF;

    FOR formula IN
        SELECT formula_column.column_key, formula_column.formula_inputs, formula_column.data_type
        FROM public.test_column AS formula_column
        WHERE formula_column.test_profile_id = profile_id
          AND formula_column.value_source = 'calculated'
        ORDER BY formula_column.test_column_id
    LOOP
        found_count := 0;

        FOR source IN
            SELECT source_column.column_key,
                   source_column.data_type,
                   source_column.value_source
            FROM public.test_column AS source_column
            WHERE source_column.test_profile_id = profile_id
              AND source_column.column_key = ANY (formula.formula_inputs)
            ORDER BY source_column.test_column_id
        LOOP
            found_count := found_count + 1;

            IF source.data_type IS DISTINCT FROM formula.data_type
               OR source.value_source NOT IN ('template', 'engineer') THEN
                RAISE EXCEPTION
                    'formula input % must be a matching template or engineer column on the same profile',
                    source.column_key
                    USING ERRCODE = '23514', CONSTRAINT = 'test_column_formula_input_type';
            END IF;
        END LOOP;

        IF found_count <> cardinality(formula.formula_inputs) THEN
            RAISE EXCEPTION 'every formula input must already exist on the same profile'
                USING ERRCODE = '23514', CONSTRAINT = 'test_column_formula_reference';
        END IF;
    END LOOP;
END;
$$;

CREATE OR REPLACE FUNCTION public.trg_test_point_value_type_check()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    source record;
BEGIN
    IF TG_OP <> 'INSERT' AND TG_OP <> 'UPDATE' THEN
        RAISE EXCEPTION 'template value check called for %', TG_OP
            USING ERRCODE = '0A000';
    END IF;

    SELECT data_type, value_source
    INTO source
    FROM public.test_column
    WHERE test_profile_id = NEW.test_profile_id
      AND test_column_id = NEW.test_column_id;

    IF NOT FOUND THEN
        RETURN NEW;
    END IF;

    IF source.value_source <> 'template' THEN
        RAISE EXCEPTION 'only template columns can store template values'
            USING ERRCODE = '23514', CONSTRAINT = 'test_point_value_template_only';
    END IF;

    IF source.data_type IN ('numeric', 'duration') AND NEW.text_value IS NOT NULL THEN
        RAISE EXCEPTION 'numeric column cannot store text_value'
            USING ERRCODE = '23514', CONSTRAINT = 'test_point_value_numeric_type';
    END IF;

    IF source.data_type = 'text' AND NEW.numeric_value IS NOT NULL THEN
        RAISE EXCEPTION 'text column cannot store numeric_value'
            USING ERRCODE = '23514', CONSTRAINT = 'test_point_value_text_type';
    END IF;

    IF source.data_type = 'duration'
       AND NEW.numeric_value IS NOT NULL
       AND NEW.numeric_value <> trunc(NEW.numeric_value) THEN
        RAISE EXCEPTION 'duration column must store whole seconds'
            USING ERRCODE = '23514', CONSTRAINT = 'test_point_value_duration_type';
    END IF;

    RETURN NEW;
END;
$$;

COMMIT;
