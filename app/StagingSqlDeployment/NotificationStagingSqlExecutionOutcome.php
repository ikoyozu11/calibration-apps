<?php

namespace App\StagingSqlDeployment;

/**
 * Explicit result for offline-tested notification deployment orchestration.
 */
final class NotificationStagingSqlExecutionOutcome
{
    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const STATUS_PRE_DEPLOYMENT_FAILED = 'pre_deployment_failed';

    public const STATUS_POST_DEPLOYMENT_FAILED = 'post_deployment_failed';

    public const STATUS_AMBIGUOUS_COMMIT = 'ambiguous_commit';

    public const STATUS_COMMITTED = 'committed';

    private function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly bool $committed,
        public readonly bool $connectionOpened,
        public readonly bool $lockAcquired,
    ) {}

    public static function blocked(string $message): self
    {
        return new self(self::STATUS_BLOCKED, $message, false, false, false);
    }

    public static function rolledBack(string $message, bool $connectionOpened = true, bool $lockAcquired = false): self
    {
        return new self(self::STATUS_ROLLED_BACK, $message, false, $connectionOpened, $lockAcquired);
    }

    public static function preDeploymentFailed(string $message, bool $connectionOpened = true): self
    {
        return new self(self::STATUS_PRE_DEPLOYMENT_FAILED, $message, false, $connectionOpened, false);
    }

    public static function postDeploymentFailed(string $message, bool $connectionOpened = true): self
    {
        return new self(self::STATUS_POST_DEPLOYMENT_FAILED, $message, false, $connectionOpened, true);
    }

    public static function ambiguousCommit(string $message): self
    {
        return new self(self::STATUS_AMBIGUOUS_COMMIT, $message, false, true, true);
    }

    public static function committed(): self
    {
        return new self(self::STATUS_COMMITTED, 'Notification schema execution committed.', true, true, true);
    }
}
