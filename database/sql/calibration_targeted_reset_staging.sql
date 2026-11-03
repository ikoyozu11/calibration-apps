-- TARGETED CALIBRATION RESET PLAN — STAGING ONLY
--
-- Purpose: identify one instrument/type session set and report all dependent
-- rows before an approved maintenance cleanup.
--
-- SAFETY:
-- * Never run against calibration_db.
-- * This file intentionally stops before DELETE. The current immutability
--   triggers reject deletion of terminal session children and frozen snapshots.
-- * Do not disable triggers/constraints, use CASCADE, TRUNCATE, or reset any
--   sequence to bypass that policy.
-- * The final ROLLBACK is intentional while this is a preparation artifact.

BEGIN;

DO $guard$
BEGIN
    IF current_database() IS DISTINCT FROM 'calibration_staging_db' THEN
        RAISE EXCEPTION
            'refusing targeted calibration reset: current_database()=%',
            current_database()
            USING ERRCODE = '42501';
    END IF;
END;
$guard$;

-- Lock the target type so a concurrent Start Calibration cannot change the
-- target set while the inspection is being reviewed.
CREATE TEMP TABLE target_type ON COMMIT DROP AS
SELECT it.instrument_type_id, it.instrument_id, it.type_name
FROM public.instrument_type AS it
JOIN public.instrument AS i ON i.instrument_id = it.instrument_id
WHERE i.instrument_name = 'Cigarette Test Station'
  AND i.serial_number = 'Sr. 9 Nr. 180'
  AND it.type_name = 'Weight';

DO $target_guard$
DECLARE
    target_count integer;
BEGIN
    SELECT count(*) INTO target_count FROM target_type;

    IF target_count <> 1 THEN
        RAISE EXCEPTION
            'expected exactly one target instrument/type row, found %',
            target_count
            USING ERRCODE = '22023';
    END IF;
END;
$target_guard$;

SELECT *
FROM public.instrument_type AS it
JOIN target_type AS target
  ON target.instrument_type_id = it.instrument_type_id
 AND target.instrument_id = it.instrument_id
FOR UPDATE;

CREATE TEMP TABLE target_calibrations ON COMMIT DROP AS
SELECT c.calibration_id,
       c.calibration_number,
       c.session_status,
       c.calibration_method,
       c.calibration_date,
       c.calibration_due,
       c.completed_at,
       c.cancelled_at
FROM public.calibration AS c
JOIN target_type AS target
  ON target.instrument_type_id = c.instrument_type_id
 AND target.instrument_id = c.instrument_id;

SELECT *
FROM target_calibrations
ORDER BY calibration_id;

-- Required pre-deletion inventory. These SELECTs are read-only.
SELECT 'calibration' AS table_name, count(*) AS row_count
FROM target_calibrations
UNION ALL
SELECT 'calibration_scope', count(*)
FROM public.calibration_scope AS s
JOIN target_calibrations AS t ON t.calibration_id = s.calibration_id
UNION ALL
SELECT 'calibration_test_result', count(*)
FROM public.calibration_test_result AS r
JOIN public.calibration_scope AS s ON s.calibration_scope_id = r.calibration_scope_id
JOIN target_calibrations AS t ON t.calibration_id = s.calibration_id
UNION ALL
SELECT 'calibration_result_value', count(*)
FROM public.calibration_result_value AS v
JOIN public.calibration_test_result AS r
  ON r.calibration_test_result_id = v.calibration_test_result_id
JOIN public.calibration_scope AS s ON s.calibration_scope_id = r.calibration_scope_id
JOIN target_calibrations AS t ON t.calibration_id = s.calibration_id
UNION ALL
SELECT 'calibration_reading', count(*)
FROM public.calibration_reading AS reading
JOIN public.calibration_test_result AS r
  ON r.calibration_test_result_id = reading.calibration_test_result_id
JOIN public.calibration_scope AS s ON s.calibration_scope_id = r.calibration_scope_id
JOIN target_calibrations AS t ON t.calibration_id = s.calibration_id
UNION ALL
SELECT 'calibration_test_profile_revision', count(*)
FROM public.calibration_test_profile_revision AS revision
JOIN target_calibrations AS t ON t.calibration_id = revision.calibration_id
UNION ALL
SELECT 'calibration_requirement_snapshot', count(*)
FROM public.calibration_requirement_snapshot AS snapshot
JOIN target_calibrations AS t ON t.calibration_id = snapshot.calibration_id
UNION ALL
SELECT 'calibration_environment_parameter_snapshot', count(*)
FROM public.calibration_environment_parameter_snapshot AS snapshot
JOIN target_calibrations AS t ON t.calibration_id = snapshot.calibration_id
UNION ALL
SELECT 'calibration_environment_value', count(*)
FROM public.calibration_environment_value AS value_row
JOIN target_calibrations AS t ON t.calibration_id = value_row.calibration_id
UNION ALL
SELECT 'calibration_environment', count(*)
FROM public.calibration_environment AS environment
JOIN target_calibrations AS t ON t.calibration_id = environment.calibration_id
UNION ALL
SELECT 'calibration_standard_usage', count(*)
FROM public.calibration_standard_usage AS usage_row
JOIN target_calibrations AS t ON t.calibration_id = usage_row.calibration_id
UNION ALL
SELECT 'calibration_activity', count(*)
FROM public.calibration_activity AS activity
JOIN target_calibrations AS t ON t.calibration_id = activity.calibration_id
UNION ALL
SELECT 'calibration_external_document', count(*)
FROM public.calibration_external_document AS document
JOIN target_calibrations AS t ON t.calibration_id = document.calibration_id
UNION ALL
SELECT 'certificate', count(*)
FROM public.certificate AS certificate
JOIN target_calibrations AS t ON t.calibration_id = certificate.calibration_id
UNION ALL
SELECT 'certificate_revision', count(*)
FROM public.certificate_revision AS revision
JOIN public.certificate AS certificate ON certificate.certificate_id = revision.certificate_id
JOIN target_calibrations AS t ON t.calibration_id = certificate.calibration_id
ORDER BY table_name;

-- Identify audit/history objects and all foreign keys before any approved
-- maintenance procedure is designed. notification_log is deliberately not
-- deleted by this plan.
SELECT table_schema, table_name
FROM information_schema.tables
WHERE table_schema = 'public'
  AND (table_name ILIKE '%audit%' OR table_name = 'notification_log')
ORDER BY table_name;

SELECT child_ns.nspname AS child_schema,
       child.relname AS child_table,
       constraint_row.conname AS constraint_name,
       pg_get_constraintdef(constraint_row.oid) AS definition,
       CASE constraint_row.confdeltype
           WHEN 'a' THEN 'NO ACTION'
           WHEN 'r' THEN 'RESTRICT'
           WHEN 'c' THEN 'CASCADE'
           WHEN 'n' THEN 'SET NULL'
           WHEN 'd' THEN 'SET DEFAULT'
       END AS on_delete
FROM pg_constraint AS constraint_row
JOIN pg_class AS child ON child.oid = constraint_row.conrelid
JOIN pg_namespace AS child_ns ON child_ns.oid = child.relnamespace
WHERE constraint_row.contype = 'f'
  AND constraint_row.confrelid IN (
      'public.calibration'::regclass,
      'public.calibration_scope'::regclass,
      'public.calibration_test_result'::regclass,
      'public.certificate'::regclass
  )
ORDER BY child_schema, child_table, constraint_name;

-- Current policy prevents deletion of this target because all visible target
-- sessions are terminal and the child/snapshot triggers reject DELETE. This
-- explicit stop is safer than weakening immutability in a reset script.
DO $immutability_guard$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM target_calibrations
        WHERE session_status IN ('completed', 'cancelled')
    ) THEN
        RAISE EXCEPTION
            'target contains terminal sessions; approved maintenance delete path is required before cleanup'
            USING ERRCODE = '25006';
    END IF;

    IF to_regclass('public.calibration_requirement_snapshot') IS NOT NULL THEN
        RAISE EXCEPTION
            'frozen requirement snapshots have a delete-blocking trigger; no cleanup bypass is prepared'
            USING ERRCODE = '25006';
    END IF;
END;
$immutability_guard$;

-- No DELETE statements are intentionally present. After an explicit approval
-- of a staging-only maintenance procedure that preserves audit policy, add
-- its reviewed, FK-ordered statements here and retain the pre/post checks.

ROLLBACK;
