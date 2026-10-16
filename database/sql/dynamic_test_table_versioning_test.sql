-- Versioning + Snapshot checks. Creates temporary rows and always rolls them back.
-- Run only after dynamic_test_table_versioning.sql has been committed.
-- DO NOT run against the primary database except inside a transaction that rolls back.
BEGIN;

CREATE TEMP TABLE versioning_ref (
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

CREATE FUNCTION pg_temp.sample_snapshot(
    p_profile_id bigint,
    p_version_id bigint,
    p_version_number integer,
    p_point_id bigint
)
RETURNS jsonb
LANGUAGE sql
AS $$
    SELECT jsonb_build_object(
        'schema_version', 1,
        'captured_at', '2026-10-15T04:00:00Z',
        'test_profile_id', p_profile_id,
        'test_profile_version_id', p_version_id,
        'version_number', p_version_number,
        'profile_name', 'Versioned Profile',
        'display_title', 'Versioned Title',
        'description', null,
        'duration_format', 'hh:mm:ss',
        'columns', jsonb_build_array(
            jsonb_build_object(
                'column_key', 'standard_value',
                'column_label', 'Standard',
                'column_order', 1,
                'data_type', 'numeric',
                'value_source', 'template',
                'unit', 'g',
                'display_scale', 2,
                'formula_code', null,
                'formula_inputs', jsonb_build_array()
            )
        ),
        'rows', jsonb_build_array(
            jsonb_build_object(
                'test_point_id', p_point_id,
                'point_order', 1,
                'nominal_value', '100',
                'unit', 'g',
                'tolerance_plus', null,
                'tolerance_minus', null,
                'description', null,
                'template_values', jsonb_build_object(
                    'standard_value', jsonb_build_object(
                        'numeric_value', '100.00',
                        'text_value', null,
                        'unit', 'g'
                    )
                )
            )
        )
    );
$$;

DO $existing_unused_are_draft$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM test_profile AS profile_row
        JOIN test_profile_version AS version_row
          ON version_row.test_profile_id = profile_row.test_profile_id
         AND version_row.version_number = 1
        WHERE NOT public.test_profile_is_used_in_calibration(profile_row.test_profile_id)
          AND (
              version_row.status IS DISTINCT FROM 'draft'
              OR version_row.published_at IS NOT NULL
          )
    ) THEN
        RAISE EXCEPTION 'existing unused test_profile rows must backfill as draft';
    END IF;
END
$existing_unused_are_draft$;

DO $setup$
DECLARE
    v_instrument_id bigint;
    v_instrument_type_id bigint;
    v_provider_id bigint;
    v_profile_id bigint;
    v_version_id bigint;
    v_column_id bigint;
    v_point_id bigint;
    v_standard_id bigint;
    v_reading_id bigint;
    v_correction_id bigint;
BEGIN
    INSERT INTO instrument (instrument_name, created_at)
    VALUES ('VERSIONING Draft Table', now())
    RETURNING instrument.instrument_id INTO v_instrument_id;

    INSERT INTO instrument_type (instrument_id, type_name, created_at)
    VALUES (v_instrument_id, 'Versioning Type', now())
    RETURNING instrument_type.instrument_type_id INTO v_instrument_type_id;

    INSERT INTO calibration_provider (provider_code, provider_name, provider_type, created_at)
    VALUES ('VT-30G-PROV', 'Versioning Test Provider', 'INTERNAL', now())
    RETURNING calibration_provider.calibration_provider_id INTO v_provider_id;

    INSERT INTO versioning_ref (ref_key, ref_id) VALUES ('instrument', v_instrument_id);
    INSERT INTO versioning_ref (ref_key, ref_id) VALUES ('type', v_instrument_type_id);
    INSERT INTO versioning_ref (ref_key, ref_id) VALUES ('provider', v_provider_id);

    INSERT INTO test_profile (instrument_type_id, profile_name, display_title)
    VALUES (v_instrument_type_id, 'Versioned Profile', 'Versioned Title')
    RETURNING test_profile.test_profile_id INTO v_profile_id;

    INSERT INTO versioning_ref (ref_key, ref_id) VALUES ('profile', v_profile_id);

    SELECT test_profile_version_id
    INTO v_version_id
    FROM test_profile_version
    WHERE test_profile_id = v_profile_id
      AND status = 'draft'
      AND version_number = 1;

    IF v_version_id IS NULL THEN
        RAISE EXCEPTION 'new test_profile did not receive a draft version';
    END IF;

    INSERT INTO versioning_ref (ref_key, ref_id) VALUES ('v1', v_version_id);

    v_standard_id := public.test_column_insert(
        v_profile_id, 'standard_value', 'Standard', 1, 'numeric', 'template', 'g', 2::smallint
    );
    v_reading_id := public.test_column_insert(
        v_profile_id, 'reading_avg', 'Reading Avg', 2, 'numeric', 'engineer', 'g', 2::smallint
    );
    v_correction_id := public.test_column_insert(
        v_profile_id, 'correction', 'Correction', 3, 'numeric', 'calculated',
        'g', 2::smallint, 'standard_minus_reading', ARRAY['standard_value', 'reading_avg']::text[]
    );

    INSERT INTO versioning_ref (ref_key, ref_id) VALUES
        ('col_standard_v1', v_standard_id),
        ('col_reading_v1', v_reading_id),
        ('col_correction_v1', v_correction_id);

    PERFORM public.test_column_reorder(
        v_profile_id,
        ARRAY[v_reading_id, v_standard_id, v_correction_id]
    );

    IF (
        SELECT array_agg(column_key::text ORDER BY column_order)
        FROM test_column
        WHERE test_profile_version_id = v_version_id
    ) IS DISTINCT FROM ARRAY['reading_avg', 'standard_value', 'correction']::text[] THEN
        RAISE EXCEPTION 'draft column reorder did not persist';
    END IF;

    INSERT INTO test_point (test_profile_id, point_order, nominal_value, unit)
    VALUES (v_profile_id, 1, 100, 'g')
    RETURNING test_point.test_point_id INTO v_point_id;

    INSERT INTO versioning_ref (ref_key, ref_id) VALUES ('point_v1', v_point_id);

    PERFORM public.test_point_value_upsert(v_profile_id, v_point_id, v_standard_id, 100.00);
END
$setup$;

DO $immutable_op_matrix$
DECLARE
    v_profile_id bigint;
    v_v1 bigint;
    v_v2 bigint;
    v_col_id bigint;
    v_spare_id bigint;
    v_point_id bigint;
    v_calibration_id bigint;
    v_scope_id bigint;
BEGIN
    INSERT INTO test_profile (instrument_type_id, profile_name)
    VALUES (
        (SELECT ref_id FROM versioning_ref WHERE ref_key = 'type'),
        'Immutable Ops Profile'
    )
    RETURNING test_profile.test_profile_id INTO v_profile_id;

    SELECT test_profile_version_id
    INTO v_v1
    FROM test_profile_version
    WHERE test_profile_id = v_profile_id
      AND status = 'draft';

    v_col_id := public.test_column_insert(
        v_profile_id, 'nominal', 'Nominal', 1, 'numeric', 'template', 'mm'
    );

    UPDATE test_column
    SET column_label = 'Nominal Draft'
    WHERE test_column_id = v_col_id;

    IF (
        SELECT column_label FROM test_column WHERE test_column_id = v_col_id
    ) IS DISTINCT FROM 'Nominal Draft' THEN
        RAISE EXCEPTION 'draft UPDATE did not return NEW / persist';
    END IF;

    v_spare_id := public.test_column_insert(
        v_profile_id, 'spare_col', 'Spare', 2, 'numeric', 'template', 'mm'
    );

    DELETE FROM test_column
    WHERE test_column_id = v_spare_id;

    IF EXISTS (SELECT 1 FROM test_column WHERE test_column_id = v_spare_id) THEN
        RAISE EXCEPTION 'draft DELETE did not return OLD / remove the row';
    END IF;

    INSERT INTO test_point (test_profile_id, point_order, unit)
    VALUES (v_profile_id, 1, 'mm')
    RETURNING test_point.test_point_id INTO v_point_id;

    INSERT INTO test_point (test_profile_id, point_order, unit)
    VALUES (v_profile_id, 2, 'mm')
    RETURNING test_point.test_point_id INTO v_spare_id;

    PERFORM public.test_profile_version_publish(v_profile_id);

    PERFORM pg_temp.expect_constraint(
        format(
            'public.test_column_insert(%s, %L, %L, 2, %L, %L)',
            v_profile_id, 'active_insert', 'Active Insert', 'text', 'engineer'
        ),
        'test_profile_version_immutable'
    );

    PERFORM pg_temp.expect_constraint(
        format(
            'UPDATE test_column SET column_label = %L WHERE test_column_id = %s',
            'Active Update',
            v_col_id
        ),
        'test_profile_version_immutable'
    );

    PERFORM pg_temp.expect_constraint(
        format(
            $cmd$
            INSERT INTO test_point (
                test_profile_id, test_profile_version_id, point_order, unit
            ) VALUES (%s, %s, 3, 'mm')
            $cmd$,
            v_profile_id,
            v_v1
        ),
        'test_profile_version_immutable'
    );

    DELETE FROM test_point
    WHERE test_point_id = v_spare_id;

    IF EXISTS (SELECT 1 FROM test_point WHERE test_point_id = v_spare_id) THEN
        RAISE EXCEPTION 'unreferenced active DELETE should be allowed for unused cleanup';
    END IF;

    v_v2 := public.test_profile_version_fork(v_v1);

    UPDATE test_column
    SET column_label = 'Nominal Fork'
    WHERE test_profile_version_id = v_v2
      AND column_key = 'nominal';

    DELETE FROM test_point
    WHERE test_profile_version_id = v_v2;

    INSERT INTO test_point (test_profile_id, point_order, unit)
    VALUES (v_profile_id, 1, 'mm');

    PERFORM public.test_profile_version_publish(v_profile_id);

    PERFORM pg_temp.expect_constraint(
        format(
            $cmd$
            INSERT INTO test_column (
                test_profile_id, test_profile_version_id, column_key, column_label,
                column_order, data_type, value_source
            ) VALUES (%s, %s, 'retired_insert', 'Retired Insert', 9, 'text', 'engineer')
            $cmd$,
            v_profile_id,
            v_v1
        ),
        'test_profile_version_immutable'
    );

    PERFORM pg_temp.expect_constraint(
        format(
            'UPDATE test_column SET column_label = %L WHERE test_column_id = %s',
            'Retired Update',
            v_col_id
        ),
        'test_profile_version_immutable'
    );

    PERFORM pg_temp.expect_constraint(
        format(
            'public.test_column_insert(%s, %L, %L, 9, %L, %L)',
            v_profile_id, 'active_v2_insert', 'Active V2', 'text', 'engineer'
        ),
        'test_profile_version_immutable'
    );

    INSERT INTO calibration (
        instrument_id,
        calibration_provider_id,
        calibration_number,
        calibration_date,
        calibration_due,
        calibration_method,
        created_at,
        updated_at
    )
    VALUES (
        (SELECT ref_id FROM versioning_ref WHERE ref_key = 'instrument'),
        (SELECT ref_id FROM versioning_ref WHERE ref_key = 'provider'),
        'VERSIONING-TEST-IMMUTABLE',
        DATE '2026-10-15',
        DATE '2027-10-15',
        'INTERNAL',
        now(),
        now()
    )
    RETURNING calibration.calibration_id INTO v_calibration_id;

    INSERT INTO calibration_scope (
        calibration_id,
        instrument_type_id,
        test_profile_id,
        test_profile_version_id,
        scope_order,
        created_at
    )
    VALUES (
        v_calibration_id,
        (SELECT ref_id FROM versioning_ref WHERE ref_key = 'type'),
        v_profile_id,
        v_v1,
        3,
        now()
    )
    RETURNING calibration_scope.calibration_scope_id INTO v_scope_id;

    INSERT INTO calibration_test_result (
        calibration_scope_id,
        test_point_id,
        created_at
    )
    VALUES (
        v_scope_id,
        v_point_id,
        now()
    );

    PERFORM pg_temp.expect_constraint(
        format('DELETE FROM test_point WHERE test_point_id = %s', v_point_id),
        'test_profile_version_immutable'
    );

    PERFORM pg_temp.expect_constraint(
        format('DELETE FROM test_column WHERE test_column_id = %s', v_col_id),
        'test_profile_version_immutable'
    );
END
$immutable_op_matrix$;

DO $publish_fork_uniques$
DECLARE
    v_profile_id bigint;
    v_v1 bigint;
    v_v2 bigint;
    v_old_point_id bigint;
    v_new_point_id bigint;
    v_v1_keys text[];
    v_v2_keys text[];
    v_status_id bigint;
BEGIN
    v_profile_id := (SELECT ref_id FROM versioning_ref WHERE ref_key = 'profile');
    v_v1 := (SELECT ref_id FROM versioning_ref WHERE ref_key = 'v1');
    v_old_point_id := (SELECT ref_id FROM versioning_ref WHERE ref_key = 'point_v1');

    PERFORM public.test_profile_version_publish(v_profile_id);

    IF (
        SELECT status FROM test_profile_version WHERE test_profile_version_id = v_v1
    ) IS DISTINCT FROM 'active' THEN
        RAISE EXCEPTION 'publish did not activate version 1';
    END IF;

    PERFORM pg_temp.expect_constraint(
        format(
            'public.test_column_insert(%s, %L, %L, 4, %L, %L)',
            v_profile_id, 'status_col', 'Status', 'text', 'engineer'
        ),
        'test_profile_version_immutable'
    );

    BEGIN
        PERFORM set_config('app.allow_published_version_delete', '1', true);
    EXCEPTION
        WHEN others THEN
            NULL;
    END;

    PERFORM pg_temp.expect_constraint(
$cmd$
UPDATE test_column
SET column_label = 'GUC bypass'
WHERE test_column_id = (SELECT ref_id FROM versioning_ref WHERE ref_key = 'col_standard_v1')
$cmd$,
        'test_profile_version_immutable'
    );

    v_v2 := public.test_profile_version_fork(v_v1);
    INSERT INTO versioning_ref (ref_key, ref_id) VALUES ('v2', v_v2);

    SELECT test_point_id
    INTO v_new_point_id
    FROM test_point
    WHERE test_profile_version_id = v_v2;

    IF v_new_point_id IS NULL OR v_new_point_id = v_old_point_id THEN
        RAISE EXCEPTION 'fork reused or dropped the historical test_point_id';
    END IF;

    INSERT INTO versioning_ref (ref_key, ref_id) VALUES ('point_v2', v_new_point_id);

    SELECT array_agg(column_key ORDER BY column_key)
    INTO v_v1_keys
    FROM test_column
    WHERE test_profile_version_id = v_v1;

    SELECT array_agg(column_key ORDER BY column_key)
    INTO v_v2_keys
    FROM test_column
    WHERE test_profile_version_id = v_v2;

    IF v_v1_keys IS DISTINCT FROM v_v2_keys THEN
        RAISE EXCEPTION 'fork did not copy column keys into a new version';
    END IF;

    v_status_id := public.test_column_insert(
        v_profile_id, 'status_col', 'Status', 4, 'text', 'engineer'
    );
    INSERT INTO versioning_ref (ref_key, ref_id) VALUES ('col_status_v2', v_status_id);

    PERFORM public.test_column_reorder(
        v_profile_id,
        ARRAY[
            (SELECT test_column_id FROM test_column WHERE test_profile_version_id = v_v2 AND column_key = 'standard_value'),
            (SELECT test_column_id FROM test_column WHERE test_profile_version_id = v_v2 AND column_key = 'reading_avg'),
            (SELECT test_column_id FROM test_column WHERE test_profile_version_id = v_v2 AND column_key = 'correction'),
            v_status_id
        ]
    );

    PERFORM pg_temp.expect_constraint(
        format(
            $cmd$
            INSERT INTO test_profile_version (
                test_profile_id, version_number, status, profile_name
            ) VALUES (%s, 3, 'draft', 'Second Draft')
            $cmd$,
            v_profile_id
        ),
        'uq_test_profile_version_draft'
    );

    PERFORM public.test_profile_version_publish(v_profile_id);

    IF (
        SELECT status FROM test_profile_version WHERE test_profile_version_id = v_v1
    ) IS DISTINCT FROM 'retired' THEN
        RAISE EXCEPTION 'publishing v2 did not retire v1';
    END IF;

    IF (
        SELECT status FROM test_profile_version WHERE test_profile_version_id = v_v2
    ) IS DISTINCT FROM 'active' THEN
        RAISE EXCEPTION 'publishing v2 did not activate v2';
    END IF;
END
$publish_fork_uniques$;

SELECT pg_temp.expect_constraint(
$cmd$
UPDATE test_column
SET column_label = 'Mutated active'
WHERE test_column_id = (
    SELECT test_column_id
    FROM test_column
    WHERE test_profile_version_id = (SELECT ref_id FROM versioning_ref WHERE ref_key = 'v2')
      AND column_key = 'status_col'
)
$cmd$,
'test_profile_version_immutable'
);

SELECT pg_temp.expect_constraint(
$cmd$
UPDATE test_column
SET column_label = 'Mutated retired'
WHERE test_column_id = (SELECT ref_id FROM versioning_ref WHERE ref_key = 'col_standard_v1')
$cmd$,
'test_profile_version_immutable'
);

DO $snapshot_values_and_history$
DECLARE
    v_profile_id bigint;
    v_v1 bigint;
    v_point_v1 bigint;
    v_snapshot jsonb;
    v_calibration_id bigint;
    v_scope_id bigint;
    v_result_id bigint;
    v_instrument_id bigint;
    v_type_id bigint;
BEGIN
    v_profile_id := (SELECT ref_id FROM versioning_ref WHERE ref_key = 'profile');
    v_v1 := (SELECT ref_id FROM versioning_ref WHERE ref_key = 'v1');
    v_point_v1 := (SELECT ref_id FROM versioning_ref WHERE ref_key = 'point_v1');
    v_instrument_id := (SELECT ref_id FROM versioning_ref WHERE ref_key = 'instrument');
    v_type_id := (SELECT ref_id FROM versioning_ref WHERE ref_key = 'type');

    v_snapshot := pg_temp.sample_snapshot(v_profile_id, v_v1, 1, v_point_v1);

    IF public.test_table_snapshot_wellformed(v_snapshot) IS DISTINCT FROM true THEN
        RAISE EXCEPTION 'expected a well-formed snapshot document';
    END IF;

    IF public.test_table_snapshot_wellformed('{"schema_version": 1}'::jsonb) THEN
        RAISE EXCEPTION 'incomplete snapshot was accepted';
    END IF;

    INSERT INTO calibration (
        instrument_id,
        calibration_provider_id,
        calibration_number,
        calibration_date,
        calibration_due,
        calibration_method,
        created_at,
        updated_at
    )
    VALUES (
        v_instrument_id,
        (SELECT ref_id FROM versioning_ref WHERE ref_key = 'provider'),
        'VERSIONING-TEST-1',
        DATE '2026-10-15',
        DATE '2027-10-15',
        'INTERNAL',
        now(),
        now()
    )
    RETURNING calibration.calibration_id INTO v_calibration_id;

    INSERT INTO calibration_scope (
        calibration_id,
        instrument_type_id,
        test_profile_id,
        test_profile_version_id,
        test_table_snapshot,
        scope_order,
        created_at
    )
    VALUES (
        v_calibration_id,
        v_type_id,
        v_profile_id,
        v_v1,
        v_snapshot,
        1,
        now()
    )
    RETURNING calibration_scope.calibration_scope_id INTO v_scope_id;

    INSERT INTO calibration_test_result (
        calibration_scope_id,
        test_point_id,
        standard_value,
        average_value,
        correction_value,
        created_at
    )
    VALUES (
        v_scope_id,
        v_point_v1,
        100,
        99.8,
        0.2,
        now()
    )
    RETURNING calibration_test_result.calibration_test_result_id INTO v_result_id;

    INSERT INTO calibration_result_value (
        calibration_test_result_id,
        column_key,
        value_source,
        data_type,
        numeric_value,
        unit
    )
    VALUES (
        v_result_id,
        'standard_value',
        'template',
        'numeric',
        100,
        'g'
    );

    INSERT INTO versioning_ref (ref_key, ref_id) VALUES
        ('calibration', v_calibration_id),
        ('scope', v_scope_id),
        ('result', v_result_id);

    PERFORM pg_temp.expect_constraint(
        format(
            'DELETE FROM test_point WHERE test_point_id = %s',
            v_point_v1
        ),
        'test_profile_version_immutable'
    );

    PERFORM pg_temp.expect_constraint(
        format('public.test_profile_delete_unused(%s)', v_profile_id),
        'fk_calibration_scope_test_profile'
    );

    PERFORM public.test_profile_archive(v_profile_id);

    IF (
        SELECT archived_at
        FROM test_profile
        WHERE test_profile_id = v_profile_id
    ) IS NULL THEN
        RAISE EXCEPTION 'archive did not set archived_at';
    END IF;
END
$snapshot_values_and_history$;

DO $historical_fk_on_draft_point$
DECLARE
    v_profile_id bigint;
    v_point_id bigint;
    v_calibration_id bigint;
    v_scope_id bigint;
BEGIN
    INSERT INTO test_profile (
        instrument_type_id,
        profile_name
    )
    VALUES (
        (SELECT ref_id FROM versioning_ref WHERE ref_key = 'type'),
        'FK Draft Profile'
    )
    RETURNING test_profile.test_profile_id INTO v_profile_id;

    INSERT INTO test_point (test_profile_id, point_order)
    VALUES (v_profile_id, 1)
    RETURNING test_point.test_point_id INTO v_point_id;

    INSERT INTO calibration (
        instrument_id,
        calibration_provider_id,
        calibration_number,
        calibration_date,
        calibration_due,
        calibration_method,
        created_at,
        updated_at
    )
    VALUES (
        (SELECT ref_id FROM versioning_ref WHERE ref_key = 'instrument'),
        (SELECT ref_id FROM versioning_ref WHERE ref_key = 'provider'),
        'VERSIONING-TEST-FK',
        DATE '2026-10-15',
        DATE '2027-10-15',
        'INTERNAL',
        now(),
        now()
    )
    RETURNING calibration.calibration_id INTO v_calibration_id;

    INSERT INTO calibration_scope (
        calibration_id,
        instrument_type_id,
        test_profile_id,
        scope_order,
        created_at
    )
    VALUES (
        v_calibration_id,
        (SELECT ref_id FROM versioning_ref WHERE ref_key = 'type'),
        v_profile_id,
        2,
        now()
    )
    RETURNING calibration_scope.calibration_scope_id INTO v_scope_id;

    INSERT INTO calibration_test_result (
        calibration_scope_id,
        test_point_id,
        created_at
    )
    VALUES (
        v_scope_id,
        v_point_id,
        now()
    );

    PERFORM pg_temp.expect_constraint(
        format('DELETE FROM test_point WHERE test_point_id = %s', v_point_id),
        'fk_calibration_test_result_test_point'
    );
END
$historical_fk_on_draft_point$;

DO $unused_delete_and_type_cascade$
DECLARE
    v_instrument_id bigint;
    v_type_id bigint;
    v_profile_id bigint;
    v_published bigint;
BEGIN
    INSERT INTO instrument (instrument_name, created_at)
    VALUES ('VERSIONING Cascade Table', now())
    RETURNING instrument.instrument_id INTO v_instrument_id;

    INSERT INTO instrument_type (instrument_id, type_name, created_at)
    VALUES (v_instrument_id, 'Cascade Type', now())
    RETURNING instrument_type.instrument_type_id INTO v_type_id;

    INSERT INTO test_profile (instrument_type_id, profile_name)
    VALUES (v_type_id, 'Cascade Profile')
    RETURNING test_profile.test_profile_id INTO v_profile_id;

    PERFORM public.test_column_insert(
        v_profile_id, 'nominal', 'Nominal', 1, 'numeric', 'template', 'mm'
    );
    v_published := public.test_profile_version_publish(v_profile_id);

    PERFORM public.test_profile_delete_unused(v_profile_id);

    IF EXISTS (SELECT 1 FROM test_profile WHERE test_profile_id = v_profile_id)
       OR EXISTS (SELECT 1 FROM test_profile_version WHERE test_profile_version_id = v_published) THEN
        RAISE EXCEPTION 'unused published test table was not deleted';
    END IF;

    INSERT INTO test_profile (instrument_type_id, profile_name)
    VALUES (v_type_id, 'Type Cascade Profile')
    RETURNING test_profile.test_profile_id INTO v_profile_id;

    PERFORM public.test_profile_version_publish(v_profile_id);

    DELETE FROM instrument_type
    WHERE instrument_type_id = v_type_id;

    IF EXISTS (SELECT 1 FROM test_profile WHERE test_profile_id = v_profile_id) THEN
        RAISE EXCEPTION 'instrument_type ON DELETE CASCADE did not remove test_profile; expected current production risk';
    END IF;
END
$unused_delete_and_type_cascade$;

ROLLBACK;
