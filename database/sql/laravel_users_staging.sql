-- Laravel authentication table for one environment.
-- PostgreSQL 18. Apply ONLY to calibration_staging_db.
--
-- NOT IDEMPOTENT. Run exactly once, as this whole file.
-- Creates public.users only. Inserts no accounts.
-- Does not create password reset, session, cache, job, or migration tables.
-- Does not change public.app_user, user_app_user_map, or actor foreign keys.
--
-- Matches the 20 October staging definition and the current Laravel users
-- migration: bigserial-compatible bigint, varchar lengths, and timestamp(0)
-- without time zone. Sequence public.users_id_seq is owned by public.users.id.
--
-- Future deployment prerequisites, not performed by this file:
--   Take a new custom-format backup of calibration_staging_db first.
--   Confirm that backup names calibration_staging_db, includes public.app_user,
--   and does not include public.users.
--   Run this file once in one session. A second run must fail.
--   After commit, read the catalog: users.id is bigint, users_pkey and
--   users_email_unique exist, the table has zero rows, and app_user is unchanged.
--   Do not run php artisan migrate against staging or the default connection.
--   Do not run this file on calibration_db.
--
-- The users migration remains pending in Laravel's migration repository.
-- Domain schema stays in database/sql.

BEGIN;

DO $guard$
BEGIN
    IF current_database() IS DISTINCT FROM 'calibration_staging_db' THEN
        RAISE EXCEPTION
            'refusing laravel_users_staging.sql: current_database()=%',
            current_database()
            USING ERRCODE = '42501';
    END IF;

    IF to_regclass('public.users') IS NOT NULL THEN
        RAISE EXCEPTION
            'laravel_users_staging.sql is not idempotent and public.users already exists'
            USING ERRCODE = '42710';
    END IF;

    IF to_regclass('public.users_id_seq') IS NOT NULL THEN
        RAISE EXCEPTION
            'laravel_users_staging.sql is not idempotent and public.users_id_seq already exists'
            USING ERRCODE = '42710';
    END IF;
END;
$guard$;

CREATE SEQUENCE public.users_id_seq
    AS bigint
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

CREATE TABLE public.users (
    id bigint NOT NULL DEFAULT nextval('public.users_id_seq'::regclass),
    name varchar(255) NOT NULL,
    email varchar(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password varchar(255) NOT NULL,
    remember_token varchar(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT users_pkey PRIMARY KEY (id),
    CONSTRAINT users_email_unique UNIQUE (email)
);

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;

COMMIT;
