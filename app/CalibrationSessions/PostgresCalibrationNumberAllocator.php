<?php

namespace App\CalibrationSessions;

use DateTimeInterface;
use Illuminate\Database\Connection;
use RuntimeException;

final class PostgresCalibrationNumberAllocator implements CalibrationNumberAllocator
{
    public function allocate(Connection $connection, DateTimeInterface $instant): string
    {
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'PostgreSQL Calibration Number allocation requires a pgsql connection.',
            );
        }

        $sequence = $connection->scalar("select nextval('calibration_number_seq')");

        if (! is_numeric($sequence)) {
            throw new RuntimeException('PostgreSQL did not return a calibration number sequence value.');
        }

        return CalibrationNumberFormatter::format((int) $sequence, $instant);
    }
}
