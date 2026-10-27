<?php

namespace App\StagingSqlDeployment;

/**
 * Narrow session contract for atomic staging SQL deployment.
 * Implementations must not use Laravel's default DB connection.
 */
interface StagingSqlSession
{
    public function applySafetyTimeouts(int $statementTimeoutMs, int $lockTimeoutMs, int $idleInTransactionMs): void;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function close(): void;

    public function isOpen(): bool;

    public function identitySnapshot(): StagingSqlIdentitySnapshot;

    public function relationExists(string $schemaQualifiedName): bool;

    /**
     * @return array{data_type: string, is_nullable: string}|null
     */
    public function columnMetadata(string $schema, string $table, string $column): ?array;

    public function functionDefinition(string $schema, string $functionName): ?string;

    /**
     * @return array{md5: string, length: int, definition: string}|null
     */
    public function functionFingerprint(string $schema, string $functionName): ?array;

    /**
     * @return list<array{name: string, enabled: string, definition: string}>
     */
    public function triggersOnTable(string $schema, string $table, string $triggerName): array;

    /**
     * @return list<string>
     */
    public function roleCatalogNames(): array;

    public function foreignKeyDeleteAction(string $schema, string $table, string $column): ?string;

    public function foreignKeyTarget(string $schema, string $table, string $column): ?string;

    public function executeSql(string $sql): void;

    /**
     * Transaction-scoped advisory lock against concurrent staging SQL deployment.
     */
    public function acquireDeploymentLock(): void;

    /**
     * True only when this session is backed by a real PostgreSQL connector.
     */
    public function isRealPostgreSql(): bool;
}
