<?php

namespace Tests\Support;

use App\CalibrationSessions\CalibrationNumberAllocator;
use App\CalibrationSessions\CalibrationNumberFormatter;
use DateTimeInterface;
use Illuminate\Database\Connection;

final class FakeCalibrationNumberAllocator implements CalibrationNumberAllocator
{
    public int $calls = 0;

    public int $nextSequence = 1;

    public function allocate(Connection $connection, DateTimeInterface $instant): string
    {
        $this->calls++;

        $sequence = $this->nextSequence;
        $this->nextSequence++;

        return CalibrationNumberFormatter::format($sequence, $instant);
    }
}
