<?php

namespace App\CalibrationDueNotifications;

final readonly class AuthoritativeCalibrationPair
{
    public function __construct(
        public int $instrumentId,
        public int $instrumentTypeId,
    ) {}

    public function key(): string
    {
        return sprintf('%d:%d', $this->instrumentId, $this->instrumentTypeId);
    }
}
