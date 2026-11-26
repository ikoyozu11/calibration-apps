<?php

namespace App\CalibrationDueNotifications;

use Carbon\CarbonImmutable;

final readonly class CalibrationNotificationCandidate
{
    public function __construct(
        public int $instrumentId,
        public int $instrumentTypeId,
        public int $sourceCalibrationId,
        public string $eventType,
        public string $eventKey,
        public CarbonImmutable $dueDate,
        public string $instrumentNameSnapshot,
        public string $instrumentTypeSnapshot,
        public ?string $serialNumberSnapshot,
    ) {}
}
