<?php

namespace App\StagingSqlDeployment;

/**
 * Read-only, connection-independent snapshot consumed by schema verifiers.
 * A future staging inspector may populate it from information_schema and
 * PostgreSQL catalogs after validating the explicit staging connection.
 *
 * @phpstan-type ColumnMetadata array{data_type: string, is_nullable: bool}
 * @phpstan-type PermissionMetadata array{connect: bool, create_table: bool, create_index: bool, owner: bool}
 */
final class NotificationStagingSqlSchemaInspection
{
    /**
     * @param  list<string>  $relations
     * @param  array<string, string>  $keyTypes
     * @param  array<string, ColumnMetadata>  $columns
     * @param  list<string>  $primaryKeys
     * @param  list<string>  $primaryKeyColumns
     * @param  list<string>  $foreignKeys
     * @param  list<string>  $checkConstraints
     * @param  list<string>  $uniqueConstraints
     * @param  list<string>  $indexes
     * @param  PermissionMetadata  $permissions
     */
    public function __construct(
        public readonly string $database,
        public readonly string $host,
        public readonly int $port,
        public readonly array $relations,
        public readonly array $keyTypes,
        public readonly bool $notificationTableExists,
        public readonly array $columns,
        public readonly array $primaryKeys,
        public readonly array $foreignKeys,
        public readonly array $checkConstraints,
        public readonly array $uniqueConstraints,
        public readonly array $indexes,
        public readonly bool $identitySequenceExists,
        public readonly array $permissions,
        public readonly int $initialRowCount,
        public readonly array $primaryKeyColumns = [],
    ) {}
}
