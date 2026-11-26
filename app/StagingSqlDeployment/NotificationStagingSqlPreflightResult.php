<?php

namespace App\StagingSqlDeployment;

/**
 * Read-only result proving that future execution gates were satisfied.
 */
final class NotificationStagingSqlPreflightResult
{
    public function __construct(
        public readonly string $releaseIdentity,
        public readonly string $artifactSha256,
        public readonly string $targetDatabase,
        public readonly bool $readyForSeparatelyAuthorizedExecution = true,
    ) {}
}
