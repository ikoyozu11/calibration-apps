-- READ ONLY inspection for Calibration Session CRUD foundation (Phase 1B).
-- SELECT-only. Does not insert, update, or delete application rows.
--
-- Optional wrapper:
--   BEGIN;
--   SET TRANSACTION READ ONLY;
--   -- run this file
--   ROLLBACK;
--
-- Aborts unless current_database() is calibration_staging_db.

DO $guard$
BEGIN
    IF current_database() IS DISTINCT FROM 'calibration_staging_db' THEN
        RAISE EXCEPTION
            'refusing calibration_session_crud_foundation_inspect.sql: current_database()=%',
            current_database()
            USING ERRCODE = '42501';
    END IF;
END;
$guard$;

SELECT current_database() AS database,
       current_user AS db_user,
       inet_server_addr() AS host,
       inet_server_port() AS port;

SELECT tablename
FROM pg_tables
WHERE schemaname = 'public'
  AND tablename IN (
      'instrument_type_requirement',
      'calibration_requirement_snapshot',
      'environment_parameter',
      'instrument_type_environment_parameter',
      'calibration_environment_parameter_snapshot',
      'calibration_environment_value',
      'calibration_test_profile_revision',
      'calibration_external_document',
      'calibration_session_setting'
  )
ORDER BY 1;

SELECT column_name, data_type, is_nullable
FROM information_schema.columns
WHERE table_schema = 'public'
  AND table_name = 'calibration'
  AND column_name IN (
      'calibration_date',
      'calibration_due',
      'calibration_provider_id',
      'completed_at',
      'cancelled_at',
      'cancelled_reason',
      'snapshot_instrument_name',
      'snapshot_location_id'
  )
ORDER BY column_name;

SELECT pg_get_serial_sequence('public.calibration', 'calibration_id') IS NULL AS calibration_id_not_using_number_seq;

SELECT last_value, is_called
FROM public.calibration_number_seq;

SELECT COUNT(*) FILTER (
           WHERE (regexp_match(calibration_number, '^CAL\.VT([0-9]{4,})/(0[1-9]|1[0-2])/[0-9]{2}$'))[1] IS NOT NULL
       ) AS matching_numbers,
       COUNT(*) AS calibration_rows
FROM public.calibration;

SELECT parameter_code, parameter_name, unit, is_system
FROM public.environment_parameter
ORDER BY parameter_id;

SELECT provider_code, provider_name, provider_type
FROM public.calibration_provider
WHERE provider_code = 'INTERNAL_TEAM';

SELECT setting_key, setting_value
FROM public.calibration_session_setting
ORDER BY 1;

SELECT conname, pg_get_constraintdef(oid) AS definition
FROM pg_constraint
WHERE conrelid = 'public.calibration'::regclass
  AND conname IN (
      'chk_calibration_due',
      'chk_calibration_cancelled_audit',
      'chk_calibration_completed_at',
      'fk_calibration_snapshot_location'
  )
ORDER BY 1;

SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'public'
  AND indexname IN (
      'uq_calibration_in_progress_per_type',
      'uq_calibration_external_document_active',
      'calibration_calibration_number_key'
  )
ORDER BY 1;

SELECT event_object_table, trigger_name, event_manipulation, action_timing
FROM information_schema.triggers
WHERE event_object_schema = 'public'
  AND trigger_name LIKE 'trg_calibration%'
ORDER BY event_object_table, trigger_name, event_manipulation;

SELECT tgrelid::regclass AS table_name, tgname
FROM pg_trigger
WHERE NOT tgisinternal
  AND tgrelid IN ('public.certificate'::regclass, 'public.notification_log'::regclass)
ORDER BY 1, 2;

SELECT column_name, column_default, is_nullable
FROM information_schema.columns
WHERE table_schema = 'public'
  AND table_name = 'test_column'
  AND column_name = 'completion_rule';

SELECT COUNT(*) FILTER (WHERE completion_rule = 'not_configured') AS not_configured_columns,
       COUNT(*) AS test_columns
FROM public.test_column;

SELECT public.test_table_snapshot_wellformed(
    '{"schema_version":"1","duration_format":"hh:mm:ss","test_profile_id":1,"test_profile_version_id":1,"version_number":1,"profile_name":"Main","columns":[{"column_key":"standard_value","column_label":"Standard Value","column_order":1,"data_type":"numeric","value_source":"engineer","formula_inputs":[]}],"rows":[{"point_order":1,"template_values":{}}]}'::jsonb
) AS v1_without_completion_rule_ok;

SELECT public.test_table_snapshot_wellformed(
    '{"schema_version":"2","duration_format":"hh:mm:ss","test_profile_id":1,"test_profile_version_id":1,"version_number":1,"profile_name":"Main","columns":[{"column_key":"standard_value","column_label":"Standard Value","column_order":1,"data_type":"numeric","value_source":"engineer","formula_inputs":[],"completion_rule":"required"}],"rows":[{"point_order":1,"template_values":{}}]}'::jsonb
) AS v2_with_completion_rule_ok;
