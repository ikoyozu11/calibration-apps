<?php

namespace App\CalibrationDueNotifications;

final readonly class CalibrationNotificationReadModelData
{
    /**
     * @param  list<CalibrationNotificationItem>  $recentNotifications
     */
    public function __construct(
        public string $recipientName,
        public string $recipientInitials,
        public string $recipientRole,
        public int $unreadCount,
        public array $recentNotifications,
    ) {}
}
