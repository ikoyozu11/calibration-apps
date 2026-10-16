-- READ ONLY inspection for Dynamic Test Table Versioning + Snapshot (step 30F).
-- Does not insert, update, or delete rows.
--
-- Optional wrapper:
--   BEGIN;
--   SET TRANSACTION READ ONLY;
--   -- run this file
--   COMMIT;
--
-- Compare results to repository SQL. Do not assume they match.

-- 1. Tables in the test / calibration / certificate area
SELECT table_schema, table_name
FROM information_schema.tables
WHERE table_schema = 'public'
  AND table_name IN (
      'test_profile',
      'test_profile_version',
      'test_column',
      'test_point',
      'test_point_value',
      'calibration',
      'calibration_scope',
      'calibration_test_result',
      'calibration_reading',
      'calibration_result_value',
      'certificate',
      'certificate_revision'
  )
ORDER BY table_name;

-- 2. Columns
SELECT table_name, column_name, data_type, udt_name, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = 'public'
  AND table_name IN (
      'test_profile',
      'test_profile_version',
      'test_column',
      'test_point',
      'test_point_value',
      'calibration_scope',
      'calibration_test_result',
      'calibration_result_value'
  )
ORDER BY table_name, ordinal_position;

-- 3. Primary keys
SELECT
    tc.table_name,
    tc.constraint_name,
    kcu.column_name,
    kcu.ordinal_position
FROM information_schema.table_constraints AS tc
JOIN information_schema.key_column_usage AS kcu
    ON kcu.constraint_name = tc.constraint_name
   AND kcu.constraint_schema = tc.constraint_schema
WHERE tc.table_schema = 'public'
  AND tc.constraint_type = 'PRIMARY KEY'
  AND tc.table_name IN (
      'test_profile',
      'test_profile_version',
      'test_column',
      'test_point',
      'test_point_value',
      'calibration',
      'calibration_scope',
      'calibration_test_result',
      'calibration_reading',
      'certificate',
      'certificate_revision'
  )
ORDER BY tc.table_name, kcu.ordinal_position;

-- 4. Foreign keys and ON DELETE / ON UPDATE
SELECT
    con.conname AS constraint_name,
    src.relname AS table_name,
    pg_get_constraintdef(con.oid) AS definition,
    CASE con.confdeltype
        WHEN 'a' THEN 'NO ACTION'
        WHEN 'r' THEN 'RESTRICT'
        WHEN 'c' THEN 'CASCADE'
        WHEN 'n' THEN 'SET NULL'
        WHEN 'd' THEN 'SET DEFAULT'
    END AS on_delete,
    CASE con.confupdtype
        WHEN 'a' THEN 'NO ACTION'
        WHEN 'r' THEN 'RESTRICT'
        WHEN 'c' THEN 'CASCADE'
        WHEN 'n' THEN 'SET NULL'
        WHEN 'd' THEN 'SET DEFAULT'
    END AS on_update
FROM pg_constraint AS con
JOIN pg_class AS src
    ON src.oid = con.conrelid
JOIN pg_namespace AS nsp
    ON nsp.oid = src.relnamespace
WHERE nsp.nspname = 'public'
  AND con.contype = 'f'
  AND src.relname IN (
      'test_profile',
      'test_profile_version',
      'test_column',
      'test_point',
      'test_point_value',
      'calibration_scope',
      'calibration_test_result',
      'calibration_reading'
  )
ORDER BY src.relname, con.conname;

-- 5. Unique constraints
SELECT
    tc.table_name,
    tc.constraint_name,
    pg_get_constraintdef(con.oid) AS definition
FROM information_schema.table_constraints AS tc
JOIN pg_constraint AS con
    ON con.conname = tc.constraint_name
JOIN pg_namespace AS nsp
    ON nsp.oid = con.connamespace
WHERE tc.table_schema = 'public'
  AND nsp.nspname = 'public'
  AND tc.constraint_type = 'UNIQUE'
  AND tc.table_name IN (
      'test_profile',
      'test_profile_version',
      'test_column',
      'test_point',
      'test_point_value',
      'calibration_scope',
      'calibration_test_result'
  )
ORDER BY tc.table_name, tc.constraint_name;

-- 6. Check constraints
SELECT
    src.relname AS table_name,
    con.conname AS constraint_name,
    pg_get_constraintdef(con.oid) AS definition
FROM pg_constraint AS con
JOIN pg_class AS src
    ON src.oid = con.conrelid
JOIN pg_namespace AS nsp
    ON nsp.oid = src.relnamespace
WHERE nsp.nspname = 'public'
  AND con.contype = 'c'
  AND src.relname IN (
      'test_profile',
      'test_profile_version',
      'test_column',
      'test_point',
      'test_point_value',
      'calibration_scope',
      'calibration_test_result'
  )
ORDER BY src.relname, con.conname;

-- 7. Indexes
SELECT
    tablename,
    indexname,
    indexdef
FROM pg_indexes
WHERE schemaname = 'public'
  AND tablename IN (
      'test_profile',
      'test_profile_version',
      'test_column',
      'test_point',
      'test_point_value',
      'calibration_scope',
      'calibration_test_result'
  )
ORDER BY tablename, indexname;

-- 8. Triggers
SELECT
    event_object_table AS table_name,
    trigger_name,
    action_timing,
    event_manipulation,
    action_statement
FROM information_schema.triggers
WHERE trigger_schema = 'public'
  AND event_object_table IN (
      'test_profile',
      'test_profile_version',
      'test_column',
      'test_point',
      'test_point_value',
      'calibration_scope'
  )
ORDER BY event_object_table, trigger_name, event_manipulation;

-- 9. Functions used by Dynamic Test Tables
SELECT
    p.proname AS function_name,
    pg_get_function_identity_arguments(p.oid) AS arguments,
    pg_get_function_result(p.oid) AS result_type
FROM pg_proc AS p
JOIN pg_namespace AS n
    ON n.oid = p.pronamespace
WHERE n.nspname = 'public'
  AND p.proname LIKE 'test_%'
ORDER BY p.proname, arguments;

-- 10. Row counts (no payload)
SELECT 'test_profile' AS table_name, count(*) AS row_count FROM public.test_profile
UNION ALL
SELECT 'test_column', count(*) FROM public.test_column
UNION ALL
SELECT 'test_point', count(*) FROM public.test_point
UNION ALL
SELECT 'test_point_value', count(*) FROM public.test_point_value
UNION ALL
SELECT 'calibration', count(*) FROM public.calibration
UNION ALL
SELECT 'calibration_scope', count(*) FROM public.calibration_scope
UNION ALL
SELECT 'calibration_scope_with_profile', count(*) FROM public.calibration_scope WHERE test_profile_id IS NOT NULL
UNION ALL
SELECT 'calibration_test_result', count(*) FROM public.calibration_test_result
UNION ALL
SELECT 'calibration_reading', count(*) FROM public.calibration_reading
ORDER BY table_name;

-- 11. Calibration references to live test identities
SELECT
    count(*) AS scope_rows,
    count(test_profile_id) AS scopes_with_profile,
    count(DISTINCT test_profile_id) AS distinct_profiles
FROM public.calibration_scope;

SELECT
    count(*) AS result_rows,
    count(DISTINCT test_point_id) AS distinct_points,
    count(*) FILTER (
        WHERE NOT EXISTS (
            SELECT 1
            FROM public.test_point AS point_row
            WHERE point_row.test_point_id = result_row.test_point_id
        )
    ) AS results_missing_point
FROM public.calibration_test_result AS result_row;

SELECT
    count(*) AS points_used_by_results
FROM public.test_point AS point_row
WHERE EXISTS (
    SELECT 1
    FROM public.calibration_test_result AS result_row
    WHERE result_row.test_point_id = point_row.test_point_id
);

-- 12. Orphans that would block version-1 backfill
SELECT column_row.test_column_id, column_row.test_profile_id
FROM public.test_column AS column_row
WHERE NOT EXISTS (
    SELECT 1
    FROM public.test_profile AS profile_row
    WHERE profile_row.test_profile_id = column_row.test_profile_id
);

SELECT point_row.test_point_id, point_row.test_profile_id
FROM public.test_point AS point_row
WHERE NOT EXISTS (
    SELECT 1
    FROM public.test_profile AS profile_row
    WHERE profile_row.test_profile_id = point_row.test_profile_id
);

SELECT
    cell.test_point_value_id,
    cell.test_profile_id,
    cell.test_point_id,
    cell.test_column_id
FROM public.test_point_value AS cell
WHERE NOT EXISTS (
        SELECT 1
        FROM public.test_point AS point_row
        WHERE point_row.test_point_id = cell.test_point_id
          AND point_row.test_profile_id = cell.test_profile_id
    )
   OR NOT EXISTS (
        SELECT 1
        FROM public.test_column AS column_row
        WHERE column_row.test_column_id = cell.test_column_id
          AND column_row.test_profile_id = cell.test_profile_id
    );

-- 13. Duplicate orders/keys that would block per-version unique constraints
SELECT test_profile_id, column_key, count(*) AS row_count
FROM public.test_column
GROUP BY test_profile_id, column_key
HAVING count(*) > 1;

SELECT test_profile_id, column_order, count(*) AS row_count
FROM public.test_column
GROUP BY test_profile_id, column_order
HAVING count(*) > 1;

SELECT test_profile_id, point_order, count(*) AS row_count
FROM public.test_point
GROUP BY test_profile_id, point_order
HAVING count(*) > 1;

-- 14. Existing versioning / snapshot objects (should be empty before 30F)
SELECT column_name
FROM information_schema.columns
WHERE table_schema = 'public'
  AND (
      (table_name = 'test_profile' AND column_name IN ('archived_at', 'active_version_id'))
      OR (table_name = 'test_column' AND column_name = 'test_profile_version_id')
      OR (table_name = 'test_point' AND column_name = 'test_profile_version_id')
      OR (table_name = 'test_point_value' AND column_name = 'test_profile_version_id')
      OR (
          table_name = 'calibration_scope'
          AND column_name IN ('test_profile_version_id', 'test_table_snapshot')
      )
  )
ORDER BY table_name, column_name;
