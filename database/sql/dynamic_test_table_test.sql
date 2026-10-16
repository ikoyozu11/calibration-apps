-- Dynamic Test Table checks. Creates temporary rows and always rolls them back.
-- Run only after dynamic_test_table_schema.sql has been committed.
BEGIN;

CREATE TEMP TABLE draft_ref (
    ref_key text PRIMARY KEY,
    ref_id bigint NOT NULL
);

CREATE FUNCTION pg_temp.expect_constraint(command text, expected text)
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    v_command text;
    v_observed text;
    v_sqlstate text;
    v_failed boolean := false;
BEGIN
    v_command := btrim(command, E' \t\n\r\f\v');

    BEGIN
        IF v_command ~* '^(INSERT|UPDATE|DELETE)[[:space:]]' THEN
            EXECUTE v_command;
        ELSE
            EXECUTE format(
                'DO $expect_body$ BEGIN PERFORM %s; END $expect_body$',
                v_command
            );
        END IF;
    EXCEPTION
        WHEN check_violation OR unique_violation OR foreign_key_violation THEN
            GET STACKED DIAGNOSTICS
                v_observed = CONSTRAINT_NAME,
                v_sqlstate = RETURNED_SQLSTATE;

            IF v_observed IS DISTINCT FROM expected THEN
                RAISE EXCEPTION 'expected constraint %, got % (SQLSTATE %)', expected, v_observed, v_sqlstate;
            END IF;

            v_failed := true;
    END;

    IF NOT v_failed THEN
        RAISE EXCEPTION 'invalid operation succeeded; expected constraint %', expected;
    END IF;
END;
$$;

DO $setup$
DECLARE
    v_instrument_id bigint;
    v_instrument_type_id bigint;
    v_profile_id bigint;
    v_point_id bigint;
    v_column_id bigint;
BEGIN
    INSERT INTO instrument (instrument_name)
    VALUES ('DRAFT Dynamic Table')
    RETURNING instrument.instrument_id INTO v_instrument_id;

    INSERT INTO instrument_type (instrument_id, type_name)
    VALUES (v_instrument_id, 'Draft Type')
    RETURNING instrument_type.instrument_type_id INTO v_instrument_type_id;

    INSERT INTO test_profile (instrument_type_id, profile_name)
    VALUES
        (v_instrument_type_id, 'Draft Profile'),
        (v_instrument_type_id, 'Other Profile'),
        (v_instrument_type_id, 'Cascade Profile');

    INSERT INTO draft_ref (ref_key, ref_id)
    SELECT 'profile:' || profile_row.profile_name, profile_row.test_profile_id
    FROM test_profile AS profile_row
    WHERE profile_row.instrument_type_id = v_instrument_type_id
      AND profile_row.profile_name IN ('Draft Profile', 'Other Profile', 'Cascade Profile');

    INSERT INTO test_point (test_profile_id, point_order)
    SELECT fixture.ref_id, 1
    FROM draft_ref AS fixture
    WHERE fixture.ref_key LIKE 'profile:%';

    INSERT INTO draft_ref (ref_key, ref_id)
    SELECT 'point:' || profile_row.profile_name, point_row.test_point_id
    FROM test_point AS point_row
    JOIN test_profile AS profile_row
        ON profile_row.test_profile_id = point_row.test_profile_id
    WHERE profile_row.instrument_type_id = v_instrument_type_id
      AND profile_row.profile_name IN ('Draft Profile', 'Other Profile', 'Cascade Profile');

    v_profile_id := (
        SELECT fixture.ref_id
        FROM draft_ref AS fixture
        WHERE fixture.ref_key = 'profile:Draft Profile'
    );

    v_column_id := public.test_column_insert(v_profile_id, 'nominal', 'Nominal', 1, 'numeric', 'template', 'mm');
    INSERT INTO draft_ref (ref_key, ref_id) VALUES ('draft:nominal', v_column_id);

    v_column_id := public.test_column_insert(v_profile_id, 'note', 'Note', 2, 'text', 'template');
    INSERT INTO draft_ref (ref_key, ref_id) VALUES ('draft:note', v_column_id);

    v_column_id := public.test_column_insert(v_profile_id, 'reading', 'Reading', 3, 'numeric', 'engineer', 'mm', 3::smallint);
    INSERT INTO draft_ref (ref_key, ref_id) VALUES ('draft:reading', v_column_id);

    v_column_id := public.test_column_insert(v_profile_id, 'spare', 'Spare', 4, 'numeric', 'template', 'mm');
    INSERT INTO draft_ref (ref_key, ref_id) VALUES ('draft:spare', v_column_id);

    v_column_id := public.test_column_insert(
        v_profile_id, 'mean_reading', 'Mean', 5, 'numeric', 'calculated',
        NULL::varchar, 3::smallint, 'average', ARRAY['reading']::text[]
    );
    INSERT INTO draft_ref (ref_key, ref_id) VALUES ('draft:mean_reading', v_column_id);

    v_column_id := public.test_column_insert(
        v_profile_id, 'error_value', 'Error', 6, 'numeric', 'calculated',
        NULL::varchar, NULL::smallint, 'deviation', ARRAY['reading', 'nominal']::text[]
    );
    INSERT INTO draft_ref (ref_key, ref_id) VALUES ('draft:error_value', v_column_id);

    v_profile_id := (
        SELECT fixture.ref_id
        FROM draft_ref AS fixture
        WHERE fixture.ref_key = 'profile:Other Profile'
    );
    v_column_id := public.test_column_insert(v_profile_id, 'nominal', 'Nominal', 1, 'numeric', 'template', 'g');
    INSERT INTO draft_ref (ref_key, ref_id) VALUES ('other:nominal', v_column_id);
    v_column_id := public.test_column_insert(v_profile_id, 'foreign_only', 'Foreign', 2, 'numeric', 'template', 'g');
    INSERT INTO draft_ref (ref_key, ref_id) VALUES ('other:foreign_only', v_column_id);

    v_profile_id := (
        SELECT fixture.ref_id
        FROM draft_ref AS fixture
        WHERE fixture.ref_key = 'profile:Cascade Profile'
    );
    PERFORM public.test_column_insert(v_profile_id, 'nominal', 'Nominal', 1, 'numeric', 'template', 'mm');
    PERFORM public.test_column_insert(v_profile_id, 'reading', 'Reading', 2, 'numeric', 'engineer', 'mm');
    PERFORM public.test_column_insert(
        v_profile_id, 'error_value', 'Error', 3, 'numeric', 'calculated',
        NULL::varchar, NULL::smallint, 'deviation', ARRAY['reading', 'nominal']::text[]
    );

    v_profile_id := (
        SELECT fixture.ref_id
        FROM draft_ref AS fixture
        WHERE fixture.ref_key = 'profile:Draft Profile'
    );
    v_point_id := (
        SELECT fixture.ref_id
        FROM draft_ref AS fixture
        WHERE fixture.ref_key = 'point:Draft Profile'
    );
    PERFORM public.test_point_value_upsert(
        v_profile_id, v_point_id,
        (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:nominal'),
        10.000
    );
    PERFORM public.test_point_value_upsert(
        v_profile_id, v_point_id,
        (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:note'),
        NULL::numeric, 'stable'
    );
    PERFORM public.test_point_value_upsert(
        v_profile_id, v_point_id,
        (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:spare'),
        2.50
    );
END
$setup$;

DO $positive$
BEGIN
    IF (
        SELECT scale(cell.numeric_value)
        FROM test_point_value AS cell
        WHERE cell.test_column_id = (
            SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:nominal'
        )
    ) IS DISTINCT FROM 3 THEN
        RAISE EXCEPTION 'numeric scale was not preserved';
    END IF;

    IF (
        SELECT cell.text_value
        FROM test_point_value AS cell
        WHERE cell.test_column_id = (
            SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:note'
        )
    ) IS DISTINCT FROM 'stable' THEN
        RAISE EXCEPTION 'text value was not preserved';
    END IF;

    IF (
        SELECT column_row.formula_inputs
        FROM test_column AS column_row
        WHERE column_row.test_column_id = (
            SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:error_value'
        )
    ) IS DISTINCT FROM ARRAY['reading', 'nominal']::text[] THEN
        RAISE EXCEPTION 'deviation input order changed';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM test_point_value AS cell
        WHERE cell.test_column_id IN (
            SELECT fixture.ref_id
            FROM draft_ref AS fixture
            WHERE fixture.ref_key IN ('draft:reading', 'draft:mean_reading', 'draft:error_value')
        )
    ) THEN
        RAISE EXCEPTION 'engineer or calculated column stored a template value';
    END IF;
END
$positive$;

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Other Profile'),
    'too_soon', 'Too soon', 3, 'numeric', 'calculated',
    NULL::varchar, NULL::smallint, 'deviation', ARRAY['later_b', 'later_a']::text[]
)
$cmd$,
'test_column_formula_reference'
);

SELECT public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Other Profile'),
    'later_a', 'Later A', 3, 'numeric', 'template', 'g'
);

SELECT public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Other Profile'),
    'later_b', 'Later B', 4, 'numeric', 'engineer', 'g'
);

SELECT public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Other Profile'),
    'gap', 'Gap', 5, 'numeric', 'calculated',
    NULL::varchar, NULL::smallint, 'deviation', ARRAY['later_b', 'later_a']::text[]
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_point_value_upsert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'point:Draft Profile'),
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:spare'),
    NULL::numeric, 'text in numeric'
)
$cmd$,
'test_point_value_numeric_type'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_point_value_upsert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'point:Draft Profile'),
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:note'),
    1::numeric, NULL::text
)
$cmd$,
'test_point_value_text_type'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_point_value_upsert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'point:Draft Profile'),
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:reading'),
    1::numeric, NULL::text
)
$cmd$,
'test_point_value_template_only'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_point_value_upsert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'point:Draft Profile'),
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:mean_reading'),
    1::numeric, NULL::text
)
$cmd$,
'test_point_value_template_only'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_point_value_upsert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'point:Draft Profile'),
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'other:foreign_only'),
    1::numeric, NULL::text
)
$cmd$,
'fk_test_point_value_column'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_point_value_upsert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'point:Other Profile'),
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:nominal'),
    1::numeric, NULL::text
)
$cmd$,
'fk_test_point_value_point'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_point_value_upsert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'point:Draft Profile'),
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:note'),
    NULL::numeric, '   '
)
$cmd$,
'ck_test_point_value_text'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    'bad_null', 'Bad', 90, 'numeric', 'calculated',
    NULL::varchar, NULL::smallint, 'average', ARRAY['reading', NULL]::text[]
)
$cmd$,
'ck_test_column_formula_inputs'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    'bad_duplicate', 'Bad', 91, 'numeric', 'calculated',
    NULL::varchar, NULL::smallint, 'deviation', ARRAY['reading', 'reading']::text[]
)
$cmd$,
'ck_test_column_formula_inputs'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    'bad_self', 'Bad', 92, 'numeric', 'calculated',
    NULL::varchar, NULL::smallint, 'deviation', ARRAY['reading', 'bad_self']::text[]
)
$cmd$,
'ck_test_column_formula_inputs'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    'bad_count', 'Bad', 93, 'numeric', 'calculated',
    NULL::varchar, NULL::smallint, 'average', ARRAY[]::text[]
)
$cmd$,
'ck_test_column_formula'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    'bad_null_code', 'Bad', 97, 'numeric', 'calculated',
    NULL::varchar, NULL::smallint, NULL::text, ARRAY[]::text[]
)
$cmd$,
'ck_test_column_formula'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    'bad_foreign', 'Bad', 94, 'numeric', 'calculated',
    NULL::varchar, NULL::smallint, 'average', ARRAY['foreign_only']::text[]
)
$cmd$,
'test_column_formula_reference'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    'bad_text', 'Bad', 95, 'numeric', 'calculated',
    NULL::varchar, NULL::smallint, 'average', ARRAY['note']::text[]
)
$cmd$,
'test_column_formula_input_type'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    'bad_chain', 'Bad', 96, 'numeric', 'calculated',
    NULL::varchar, NULL::smallint, 'average', ARRAY['mean_reading']::text[]
)
$cmd$,
'test_column_formula_input_type'
);

SELECT pg_temp.expect_constraint(
$cmd$
UPDATE test_column AS column_row
SET column_key = 'renamed'
WHERE column_row.test_column_id = (
    SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:reading'
)
$cmd$,
'test_column_immutable'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_update(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:spare'),
    'Spare', 4, 'text', 'template'
)
$cmd$,
'test_column_cell_conflict'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_update(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:spare'),
    'Spare', 4, 'numeric', 'engineer', 'mm'
)
$cmd$,
'test_column_cell_conflict'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_update(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:reading'),
    'Reading', 3, 'text', 'engineer'
)
$cmd$,
'test_column_formula_input_type'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_delete(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:reading')
)
$cmd$,
'test_column_formula_reference'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_update(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:note'),
    'Note', 1, 'text', 'template'
)
$cmd$,
'uq_test_column_order'
);

SELECT public.test_column_update(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:reading'),
    'Instrument reading', 3, 'numeric', 'engineer', 'mm', 3::smallint
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_reorder(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    ARRAY[]::bigint[]
)
$cmd$,
'test_column_reorder'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_reorder(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    ARRAY[
        (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:note'),
        (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:nominal')
    ]::bigint[]
)
$cmd$,
'test_column_reorder'
);

SELECT public.test_column_reorder(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    ARRAY[
        (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:note'),
        (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:nominal'),
        (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:reading'),
        (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:spare'),
        (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:mean_reading'),
        (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:error_value')
    ]::bigint[]
);

DO $reorder$
BEGIN
    IF (
        SELECT array_agg(column_row.column_key::text ORDER BY column_row.column_order)
        FROM test_column AS column_row
        WHERE column_row.test_profile_id = (
            SELECT fixture.ref_id
            FROM draft_ref AS fixture
            WHERE fixture.ref_key = 'profile:Draft Profile'
        )
    ) IS DISTINCT FROM ARRAY['note', 'nominal', 'reading', 'spare', 'mean_reading', 'error_value']::text[] THEN
        RAISE EXCEPTION 'reorder did not apply the requested order';
    END IF;
END
$reorder$;

SELECT public.test_column_delete(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:error_value')
);

SELECT public.test_column_update(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:mean_reading'),
    'Mean', 5, 'numeric', 'calculated',
    NULL::varchar, 3::smallint, 'average', ARRAY['nominal']::text[]
);

SELECT public.test_column_delete(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:reading')
);

DELETE FROM test_profile AS profile_row
WHERE profile_row.test_profile_id = (
    SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Cascade Profile'
);

DO $positive$
BEGIN
    IF (
        SELECT column_row.formula_inputs
        FROM test_column AS column_row
        WHERE column_row.test_profile_id = (
            SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Other Profile'
        )
          AND column_row.column_key = 'gap'
    ) IS DISTINCT FROM ARRAY['later_b', 'later_a']::text[] THEN
        RAISE EXCEPTION 'gap inputs were not stored in source order';
    END IF;

    IF (
        SELECT column_row.column_order
        FROM test_column AS column_row
        WHERE column_row.test_column_id = (
            SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:note'
        )
    ) IS DISTINCT FROM 1
    OR (
        SELECT column_row.column_order
        FROM test_column AS column_row
        WHERE column_row.test_column_id = (
            SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:nominal'
        )
    ) IS DISTINCT FROM 2 THEN
        RAISE EXCEPTION 'reorder did not swap the first two columns';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM test_column AS column_row
        WHERE column_row.column_key = 'reading'
          AND column_row.test_profile_id = (
              SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'
          )
    ) THEN
        RAISE EXCEPTION 'reading column was not deleted after its formula was retargeted';
    END IF;

    IF (
        SELECT cell.numeric_value
        FROM test_point_value AS cell
        WHERE cell.test_column_id = (
            SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:spare'
        )
    ) IS DISTINCT FROM 2.50
    OR (
        SELECT column_row.value_source
        FROM test_column AS column_row
        WHERE column_row.test_column_id = (
            SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'draft:spare'
        )
    ) IS DISTINCT FROM 'template' THEN
        RAISE EXCEPTION 'rejected spare change was committed';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM test_column AS column_row
        WHERE column_row.test_profile_id = (
            SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Cascade Profile'
        )
    ) THEN
        RAISE EXCEPTION 'cascade delete left test columns behind';
    END IF;
END
$positive$;

SELECT public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    'standard_time', 'Standard Time', 11, 'duration', 'template'
);

SELECT public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    'reading_average', 'Reading Average', 12, 'duration', 'engineer'
);

SELECT public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    'time_correction', 'Correction', 13, 'duration', 'calculated',
    NULL::varchar, NULL::smallint, 'standard_minus_reading', ARRAY['standard_time', 'reading_average']::text[]
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    'bad_duration_average', 'Bad', 98, 'duration', 'calculated',
    NULL::varchar, NULL::smallint, 'average', ARRAY['standard_time']::text[]
)
$cmd$,
'ck_test_column_formula'
);

SELECT pg_temp.expect_constraint(
$cmd$
public.test_column_insert(
    (SELECT fixture.ref_id FROM draft_ref AS fixture WHERE fixture.ref_key = 'profile:Draft Profile'),
    'bad_duration_numeric', 'Bad', 99, 'duration', 'calculated',
    NULL::varchar, NULL::smallint, 'reading_minus_standard', ARRAY['nominal', 'reading']::text[]
)
$cmd$,
'test_column_formula_input_type'
);

ROLLBACK;
