<?php

namespace App\StagingSqlDeployment;

/**
 * Immutable, non-executable package description for the notification schema.
 *
 * This package intentionally contains no database connection or execution
 * capability. A separately approved staging entry point must consume it.
 */
final class NotificationStagingSqlPackage
{
    /**
     * @param  list<string>  $requiredTables
     */
    public function __construct(
        public readonly string $artifactPath,
        public readonly string $artifactSha256,
        public readonly string $releaseIdentity,
        public readonly string $targetDatabase,
        public readonly string $targetHost,
        public readonly int $targetPort,
        public readonly string $sql,
        public readonly array $requiredTables,
        public readonly string $expectedTable,
    ) {}

    public function hasOuterTransaction(): bool
    {
        return preg_match('/\\bBEGIN\\s*;/i', $this->sql) === 1
            && preg_match('/COMMIT\\s*;\\s*$/i', $this->sql) === 1;
    }
}
