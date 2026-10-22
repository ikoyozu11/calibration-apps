<?php

namespace App\CalibrationSessions;

use DateTimeInterface;
use Illuminate\Database\Connection;

interface CalibrationNumberAllocator
{
    public function allocate(Connection $connection, DateTimeInterface $instant): string;
}
