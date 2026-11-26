<?php

namespace App\CalibrationDueNotifications;

final readonly class CalibrationNotificationReconciliationReport
{
    /**
     * @param  list<array<string, mixed>>  $outcomes
     * @param  list<array<string, mixed>>  $failures
     */
    public function __construct(
        public bool $complete,
        public bool $dryRun,
        public int $processed,
        public int $created,
        public int $alreadyCurrent,
        public int $resolved,
        public int $skippedInvalid,
        public int $potentiallyStale,
        public array $outcomes,
        public array $failures,
        public ?string $failureCategory = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->complete && $this->failures === [];
    }
}
