<?php

namespace App\StagingSqlDeployment;

/**
 * Contract outcomes for a future single-transaction executor.
 * Offline tests assert these semantics without opening PostgreSQL.
 */
final class StagingSqlExecutionOutcome
{
    public const STATUS_DISABLED = 'disabled';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const STATUS_VERIFICATION_FAILED = 'verification_failed';

    public const STATUS_AMBIGUOUS_COMMIT = 'ambiguous_commit';

    public const STATUS_COMMITTED = 'committed';

    private function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly bool $committed,
        public readonly bool $openedPostgreSql,
    ) {}

    public static function disabled(string $message): self
    {
        return new self(self::STATUS_DISABLED, $message, false, false);
    }

    public static function rolledBack(string $message): self
    {
        return new self(self::STATUS_ROLLED_BACK, $message, false, false);
    }

    public static function verificationFailed(string $message): self
    {
        return new self(self::STATUS_VERIFICATION_FAILED, $message, false, false);
    }

    public static function ambiguousCommit(string $message): self
    {
        return new self(self::STATUS_AMBIGUOUS_COMMIT, $message, false, false);
    }

    public static function committed(string $message, bool $openedPostgreSql = false): self
    {
        return new self(self::STATUS_COMMITTED, $message, true, $openedPostgreSql);
    }
}
