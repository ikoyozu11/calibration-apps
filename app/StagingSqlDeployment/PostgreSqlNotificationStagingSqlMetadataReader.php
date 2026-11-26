<?php

namespace App\StagingSqlDeployment;

use Illuminate\Database\Connection;
use Throwable;

/**
 * PostgreSQL catalog reader. Every statement is a read-only SELECT.
 */
final class PostgreSqlNotificationStagingSqlMetadataReader implements NotificationStagingSqlReadOnlyMetadataReader
{
    public function inspect(Connection $connection): NotificationStagingSqlSchemaInspection
    {
        try {
            $database = (string) $connection->scalar('select current_database()');
            $relations = array_values(array_filter(array_map(
                static fn (object $row): ?string => is_string($row->relation ?? null) ? $row->relation : null,
                $connection->select(<<<'SQL'
                    with expected(relation_name) as (
                        values
                            ('users'),
                            ('instrument'),
                            ('instrument_type'),
                            ('calibration'),
                            ('calibration_notifications')
                    )
                    select case when c.oid is null then null
                                else n.nspname || '.' || c.relname
                           end as relation
                    from expected
                    left join pg_catalog.pg_class c
                      on c.relname = expected.relation_name
                    left join pg_catalog.pg_namespace n
                      on n.oid = c.relnamespace
                     and n.nspname = 'public'
                    SQL,
                ))));

            $keyTypes = [];
            foreach ($connection->select(<<<'SQL'
                select table_name, column_name, data_type
                from information_schema.columns
                where table_schema = 'public'
                  and ((table_name = 'users' and column_name = 'id')
                    or (table_name = 'instrument' and column_name = 'instrument_id')
                    or (table_name = 'instrument_type' and column_name = 'instrument_type_id')
                    or (table_name = 'calibration' and column_name = 'calibration_id'))
                SQL) as $row) {
                $keyTypes['public.'.$row->table_name.'.'.$row->column_name] = (string) $row->data_type;
            }

            $tableExists = in_array('public.calibration_notifications', $relations, true);
            $columns = [];
            $primaryKeys = [];
            $primaryKeyColumns = [];
            $foreignKeys = [];
            $checkConstraints = [];
            $uniqueConstraints = [];
            $indexes = [];
            $identitySequenceExists = false;
            $rowCount = 0;

            if ($tableExists) {
                foreach ($connection->select(<<<'SQL'
                    select column_name, data_type, is_nullable
                    from information_schema.columns
                    where table_schema = 'public' and table_name = 'calibration_notifications'
                    SQL) as $row) {
                    $columns[(string) $row->column_name] = [
                        'data_type' => (string) $row->data_type,
                        'is_nullable' => $row->is_nullable === 'YES',
                    ];
                }

                foreach ($connection->select(<<<'SQL'
                    select constraint_name, constraint_type
                    from information_schema.table_constraints
                    where table_schema = 'public' and table_name = 'calibration_notifications'
                    SQL) as $row) {
                    match ((string) $row->constraint_type) {
                        'PRIMARY KEY' => $primaryKeys[] = (string) $row->constraint_name,
                        'FOREIGN KEY' => $foreignKeys[] = (string) $row->constraint_name,
                        'CHECK' => $checkConstraints[] = (string) $row->constraint_name,
                        'UNIQUE' => $uniqueConstraints[] = (string) $row->constraint_name,
                        default => null,
                    };
                }

                foreach ($connection->select(<<<'SQL'
                    select kcu.column_name
                    from information_schema.table_constraints tc
                    join information_schema.key_column_usage kcu
                      on kcu.constraint_schema = tc.constraint_schema
                     and kcu.constraint_name = tc.constraint_name
                     and kcu.table_schema = tc.table_schema
                     and kcu.table_name = tc.table_name
                    where tc.constraint_schema = 'public'
                      and tc.table_name = 'calibration_notifications'
                      and tc.constraint_type = 'PRIMARY KEY'
                    order by kcu.ordinal_position
                    SQL) as $row) {
                    $primaryKeyColumns[] = (string) $row->column_name;
                }

                $indexes = array_values(array_filter(array_map(
                    static fn (object $row): ?string => is_string($row->indexname ?? null) ? $row->indexname : null,
                    $connection->select(
                        'select indexname from pg_indexes where schemaname = ? and tablename = ?',
                        ['public', 'calibration_notifications'],
                    ),
                )));
                $identitySequenceExists = (bool) $connection->scalar(
                    "select pg_get_serial_sequence('public.calibration_notifications', 'calibration_notification_id') is not null"
                );
                $rowCount = (int) $connection->scalar('select count(*) from public.calibration_notifications');
            }

            $permissions = (array) ($connection->selectOne(<<<'SQL'
                with notification_relation as (
                    select
                        c.oid,
                        pg_get_userbyid(c.relowner) = current_user as is_owner
                    from pg_catalog.pg_class c
                    join pg_catalog.pg_namespace n on n.oid = c.relnamespace
                    where n.nspname = 'public'
                      and c.relname = 'calibration_notifications'
                )
                select
                    has_schema_privilege(current_user, 'public', 'CREATE') as create_schema,
                    has_database_privilege(current_user, current_database(), 'CONNECT') as can_connect,
                    coalesce((
                        select has_table_privilege(current_user, notification_relation.oid, 'SELECT')
                        from notification_relation
                    ), false) as can_select,
                    coalesce((select is_owner from notification_relation), false) as is_owner
                SQL) ?: []);

            return new NotificationStagingSqlSchemaInspection(
                database: $database,
                host: (string) $connection->getConfig('host'),
                port: (int) $connection->getConfig('port'),
                relations: $relations,
                keyTypes: $keyTypes,
                notificationTableExists: $tableExists,
                columns: $columns,
                primaryKeys: $primaryKeys,
                foreignKeys: $foreignKeys,
                checkConstraints: $checkConstraints,
                uniqueConstraints: $uniqueConstraints,
                indexes: $indexes,
                identitySequenceExists: $identitySequenceExists,
                permissions: [
                    'connect' => (bool) ($permissions['can_connect'] ?? false),
                    'create_table' => (bool) ($permissions['create_schema'] ?? false),
                    'create_index' => (bool) ($permissions['create_schema'] ?? false),
                    'owner' => (bool) ($permissions['is_owner'] ?? false),
                ],
                initialRowCount: $rowCount,
                primaryKeyColumns: $primaryKeyColumns,
            );
        } catch (Throwable $exception) {
            throw new StagingSqlDeploymentException('Read-only PostgreSQL schema inspection failed.', 0, $exception);
        }
    }
}
