-- External Calibration PDF soft-remove schema preparation.
-- PostgreSQL 18. Apply ONLY to calibration_staging_db after manual review.
--
-- This file is intentionally schema-only. It does not update existing
-- document rows, delete rows, delete files, or activate a Remove PDF action.
-- Existing active rows remain active. Existing inactive rows with
-- superseded_at remain superseded. The new nullable columns remain NULL until
-- an application implementation records a manual removal.
--
-- The actor column follows the existing convention:
-- removed_by_user_id references public.app_user(app_user_id), not users.id.
-- Laravel users.id must first be resolved through user_app_user_map by the
-- application service, exactly as uploaded_by_user_id is resolved today.
-- ON DELETE RESTRICT preserves the actor reference as part of the audit trail.

BEGIN;

DO $guard$
DECLARE
    active_check text;
    original_rules text;
    index_is_unique boolean;
    index_predicate text;
    index_column_count bigint;
    index_column_name text;
    rules_trigger_enabled text;
    rules_trigger_function oid;
    rules_trigger_type integer;
    app_user_id_type text;
BEGIN
    IF current_database() IS DISTINCT FROM 'calibration_staging_db' THEN
        RAISE EXCEPTION
            'refusing calibration_external_document_soft_remove_staging.sql: current_database()=%',
            current_database()
            USING ERRCODE = '42501';
    END IF;

    IF to_regclass('public.calibration_external_document') IS NULL THEN
        RAISE EXCEPTION
            'calibration_external_document table is required'
            USING ERRCODE = '42P01';
    END IF;

    IF to_regclass('public.app_user') IS NULL THEN
        RAISE EXCEPTION
            'app_user table is required for the removal actor foreign key'
            USING ERRCODE = '42P01';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'calibration_external_document'
          AND column_name IN ('removed_at', 'removed_by_user_id')
    ) THEN
        RAISE EXCEPTION
            'soft-remove columns already exist; inspect staging before continuing'
            USING ERRCODE = '42701';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'calibration_external_document'
          AND column_name = 'is_active'
          AND data_type = 'boolean'
          AND is_nullable = 'NO'
    ) THEN
        RAISE EXCEPTION
            'expected calibration_external_document.is_active boolean NOT NULL column is missing'
            USING ERRCODE = '42804';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'calibration_external_document'
          AND column_name = 'superseded_at'
          AND data_type = 'timestamp with time zone'
          AND is_nullable = 'YES'
    ) THEN
        RAISE EXCEPTION
            'expected calibration_external_document.superseded_at timestamptz NULL column is missing'
            USING ERRCODE = '42804';
    END IF;

    SELECT lower(regexp_replace(pg_get_constraintdef(oid), '[[:space:]]+', '', 'g'))
    INTO active_check
    FROM pg_constraint
    WHERE conrelid = 'public.calibration_external_document'::regclass
      AND conname = 'chk_calibration_external_document_active';

    IF active_check IS DISTINCT FROM 'check(((is_activeand(superseded_atisnull))or((notis_active)and(superseded_atisnotnull))))' THEN
        RAISE EXCEPTION
            'expected chk_calibration_external_document_active has an unexpected definition: %',
            COALESCE(active_check, 'missing')
            USING ERRCODE = '42809';
    END IF;

    SELECT i.indisunique,
           lower(regexp_replace(COALESCE(pg_get_expr(i.indpred, i.indrelid), ''), '[[:space:]]+', '', 'g')),
           (
               SELECT COUNT(*)
               FROM unnest(i.indkey) WITH ORDINALITY AS key_columns(attnum, ordinal)
           ),
           (
               SELECT MAX(a.attname)
               FROM unnest(i.indkey) WITH ORDINALITY AS key_columns(attnum, ordinal)
               JOIN pg_attribute AS a
                   ON a.attrelid = i.indrelid
                  AND a.attnum = key_columns.attnum
           )
    INTO index_is_unique, index_predicate, index_column_count, index_column_name
    FROM pg_index AS i
    JOIN pg_class AS index_relation
        ON index_relation.oid = i.indexrelid
    JOIN pg_namespace AS index_namespace
        ON index_namespace.oid = index_relation.relnamespace
    WHERE index_namespace.nspname = 'public'
      AND index_relation.relname = 'uq_calibration_external_document_active'
      AND i.indrelid = 'public.calibration_external_document'::regclass;

    IF index_is_unique IS DISTINCT FROM TRUE
       OR index_predicate IS DISTINCT FROM 'is_active'
       OR index_column_count IS DISTINCT FROM 1
       OR index_column_name IS DISTINCT FROM 'calibration_id' THEN
        RAISE EXCEPTION
            'expected uq_calibration_external_document_active has an unexpected definition: unique=%, predicate=%, columns=% (%)',
            COALESCE(index_is_unique::text, 'missing'),
            COALESCE(index_predicate, 'missing'),
            COALESCE(index_column_count::text, 'missing'),
            COALESCE(index_column_name, 'missing')
            USING ERRCODE = '42809';
    END IF;

    SELECT pg_get_functiondef(p.oid)
    INTO original_rules
    FROM pg_proc AS p
    JOIN pg_namespace AS function_namespace
        ON function_namespace.oid = p.pronamespace
    WHERE function_namespace.nspname = 'public'
      AND p.proname = 'trg_calibration_external_document_rules'
      AND p.pronargs = 0;

    IF original_rules IS NULL
       OR original_rules NOT LIKE '%external documents cannot be deleted%'
       OR original_rules NOT LIKE '%external PDF exceeds configured size limit%'
       OR original_rules NOT LIKE '%external document storage identity cannot change%'
       OR original_rules NOT LIKE '%superseded documents require superseded_at%' THEN
        RAISE EXCEPTION
            'expected original external document rules function is missing or has unexpected protections'
            USING ERRCODE = '42704';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'public.calibration_external_document'::regclass
          AND tgname = 'trg_calibration_external_document_rules'
          AND NOT tgisinternal
    ) THEN
        RAISE EXCEPTION
            'expected external document rules trigger is missing'
            USING ERRCODE = '42704';
    END IF;

    SELECT t.tgenabled::text,
           t.tgfoid,
           t.tgtype
    INTO rules_trigger_enabled, rules_trigger_function, rules_trigger_type
    FROM pg_trigger AS t
    WHERE t.tgrelid = 'public.calibration_external_document'::regclass
      AND t.tgname = 'trg_calibration_external_document_rules'
      AND NOT t.tgisinternal;

    IF rules_trigger_enabled IS DISTINCT FROM 'O'
       OR rules_trigger_function IS DISTINCT FROM 'public.trg_calibration_external_document_rules()'::regprocedure::oid
       OR (rules_trigger_type & 1) = 0
       OR (rules_trigger_type & 2) = 0
       OR (rules_trigger_type & 4) = 0
       OR (rules_trigger_type & 8) = 0
       OR (rules_trigger_type & 16) = 0 THEN
        RAISE EXCEPTION
            'external document rules trigger must be enabled, bound to the original function, and cover BEFORE row INSERT/UPDATE/DELETE'
            USING ERRCODE = '42704';
    END IF;

    SELECT data_type
    INTO app_user_id_type
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name = 'app_user'
      AND column_name = 'app_user_id';

    IF app_user_id_type IS DISTINCT FROM 'bigint' THEN
        RAISE EXCEPTION
            'app_user.app_user_id must remain bigint, found %',
            COALESCE(app_user_id_type, 'missing')
            USING ERRCODE = '42804';
    END IF;
END;
$guard$;

ALTER TABLE public.calibration_external_document
    ADD COLUMN removed_at timestamp with time zone,
    ADD COLUMN removed_by_user_id bigint,
    ADD CONSTRAINT fk_calibration_external_document_removed_by
        FOREIGN KEY (removed_by_user_id)
        REFERENCES public.app_user (app_user_id)
        ON DELETE RESTRICT;

ALTER TABLE public.calibration_external_document
    DROP CONSTRAINT chk_calibration_external_document_active,
    ADD CONSTRAINT chk_calibration_external_document_active
        CHECK (
            (is_active
                AND superseded_at IS NULL
                AND removed_at IS NULL
                AND removed_by_user_id IS NULL)
            OR (NOT is_active
                AND superseded_at IS NOT NULL
                AND removed_at IS NULL
                AND removed_by_user_id IS NULL)
            OR (NOT is_active
                AND superseded_at IS NULL
                AND removed_at IS NOT NULL
                AND removed_by_user_id IS NOT NULL)
        );

CREATE OR REPLACE FUNCTION public.trg_calibration_external_document_rules()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $function$
DECLARE
    max_bytes bigint;
    v_calibration_method text;
    v_session_status text;
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

    IF TG_OP = 'INSERT' AND (
        NOT NEW.is_active
        OR NEW.superseded_at IS NOT NULL
        OR NEW.removed_at IS NOT NULL
        OR NEW.removed_by_user_id IS NOT NULL
    ) THEN
        RAISE EXCEPTION 'new external documents must start in the active state'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.is_active
       AND (NEW.superseded_at IS NOT NULL
            OR NEW.removed_at IS NOT NULL
            OR NEW.removed_by_user_id IS NOT NULL) THEN
        RAISE EXCEPTION 'active external documents cannot be superseded or removed'
            USING ERRCODE = '23514';
    END IF;

    IF NOT NEW.is_active
       AND NEW.superseded_at IS NOT NULL
       AND (NEW.removed_at IS NOT NULL OR NEW.removed_by_user_id IS NOT NULL) THEN
        RAISE EXCEPTION 'an external document cannot be superseded and removed'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.removed_at IS NOT NULL AND NEW.superseded_at IS NOT NULL THEN
        RAISE EXCEPTION 'an external document cannot be superseded and removed'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF NEW.document_id IS DISTINCT FROM OLD.document_id THEN
            RAISE EXCEPTION 'external document identity cannot change'
                USING ERRCODE = '25006';
        END IF;

        IF NEW.calibration_id IS DISTINCT FROM OLD.calibration_id THEN
            RAISE EXCEPTION 'external document calibration ownership cannot change'
                USING ERRCODE = '25006';
        END IF;

        IF NEW.storage_uuid IS DISTINCT FROM OLD.storage_uuid
           OR NEW.storage_path IS DISTINCT FROM OLD.storage_path
           OR NEW.sha256 IS DISTINCT FROM OLD.sha256 THEN
            RAISE EXCEPTION 'external document storage identity cannot change'
                USING ERRCODE = '25006';
        END IF;

        IF NEW.original_filename IS DISTINCT FROM OLD.original_filename
           OR NEW.mime_type IS DISTINCT FROM OLD.mime_type
           OR NEW.byte_size IS DISTINCT FROM OLD.byte_size
           OR NEW.uploaded_at IS DISTINCT FROM OLD.uploaded_at
           OR NEW.uploaded_by_user_id IS DISTINCT FROM OLD.uploaded_by_user_id THEN
            RAISE EXCEPTION 'external document metadata cannot change'
                USING ERRCODE = '25006';
        END IF;

        IF OLD.removed_at IS NOT NULL THEN
            RAISE EXCEPTION 'removed external documents are immutable'
                USING ERRCODE = '25006';
        END IF;

        IF OLD.superseded_at IS NOT NULL THEN
            RAISE EXCEPTION 'superseded external documents are immutable'
                USING ERRCODE = '25006';
        END IF;

        IF OLD.is_active AND NOT NEW.is_active THEN
            IF NEW.superseded_at IS NULL AND NEW.removed_at IS NULL THEN
                RAISE EXCEPTION 'inactive external documents require superseded_at or removed_at'
                    USING ERRCODE = '23514';
            END IF;

            IF NEW.removed_at IS NOT NULL THEN
                IF NEW.removed_by_user_id IS NULL THEN
                    RAISE EXCEPTION 'manual removal requires a resolved app_user actor'
                        USING ERRCODE = '23514';
                END IF;

                SELECT c.calibration_method, c.session_status
                INTO v_calibration_method, v_session_status
                FROM public.calibration AS c
                WHERE c.calibration_id = NEW.calibration_id
                FOR UPDATE;

                IF v_calibration_method IS DISTINCT FROM 'EXTERNAL'
                   OR v_session_status IS DISTINCT FROM 'in_progress' THEN
                    RAISE EXCEPTION 'external documents can be removed only from an in-progress external calibration session'
                        USING ERRCODE = '25006';
                END IF;
            END IF;
        END IF;
    END IF;

    RETURN NEW;
END;
$function$;

COMMENT ON COLUMN public.calibration_external_document.removed_at IS
'Timestamp when the document was manually soft-removed; NULL means it was not manually removed.';

COMMENT ON COLUMN public.calibration_external_document.removed_by_user_id IS
'Resolved public.app_user actor that manually soft-removed the document; the RESTRICT foreign key preserves this audit identity.';

COMMIT;
