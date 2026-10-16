<?php

namespace App\TestColumns;

use InvalidArgumentException;

class DurationValue
{
    public static function parse(string $input): int
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Enter a duration as hh:mm:ss.');
        }

        $sign = 1;

        if (str_starts_with($trimmed, '+')) {
            $trimmed = substr($trimmed, 1);
        } elseif (str_starts_with($trimmed, '-')) {
            $sign = -1;
            $trimmed = substr($trimmed, 1);
        }

        if (preg_match('/^(\d+):([0-5]\d):([0-5]\d)$/', $trimmed, $parts) !== 1
            && preg_match('/^(\d+)\.([0-5]\d)\.([0-5]\d)$/', $trimmed, $parts) !== 1) {
            throw new InvalidArgumentException('Enter a duration as hh:mm:ss.');
        }

        $hours = (int) $parts[1];
        $minutes = (int) $parts[2];
        $seconds = (int) $parts[3];

        return $sign * (($hours * 3600) + ($minutes * 60) + $seconds);
    }

    public static function tryParse(string $input): ?int
    {
        try {
            return self::parse($input);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function format(int $seconds, bool $alwaysSigned = false): string
    {
        $sign = '';

        if ($seconds < 0) {
            $sign = '-';
        } elseif ($alwaysSigned) {
            $sign = '+';
        }

        $absolute = abs($seconds);
        $hours = intdiv($absolute, 3600);
        $minutes = intdiv($absolute % 3600, 60);
        $remainder = $absolute % 60;

        return sprintf('%s%02d:%02d:%02d', $sign, $hours, $minutes, $remainder);
    }
}
