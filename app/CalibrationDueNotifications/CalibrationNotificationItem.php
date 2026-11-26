<?php

namespace App\CalibrationDueNotifications;

use Carbon\CarbonInterface;

final readonly class CalibrationNotificationItem
{
    public function __construct(
        public int $notificationId,
        public string $eventType,
        public bool $isRead,
        public string $instrumentName,
        public string $instrumentType,
        public CarbonInterface $dueDate,
        public CarbonInterface $createdAt,
        public bool $targetAvailable,
        public ?string $targetUrl,
    ) {}
}
