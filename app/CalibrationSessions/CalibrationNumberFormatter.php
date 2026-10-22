<?php

namespace App\CalibrationSessions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final class CalibrationNumberFormatter
{
    public const PATTERN = '/^CAL\.VT([0-9]{4,})\/(0[1-9]|1[0-2])\/[0-9]{2}$/';

    public const BIGINT_MAX = '9223372036854775807';

    public static function format(int $sequence, DateTimeInterface $instant): string
    {
        if ($sequence < 1) {
            throw new InvalidArgumentException('Calibration number sequence must be at least 1.');
        }

        $jakarta = CarbonImmutable::parse($instant)->timezone('Asia/Jakarta');

        return sprintf('CAL.VT%04d/%s', $sequence, $jakarta->format('m/y'));
    }

    public static function parseSequence(?string $number): ?int
    {
        if ($number === null || preg_match(self::PATTERN, $number, $matches) !== 1) {
            return null;
        }

        if (self::sequenceTextOverflows($matches[1])) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @param  list<string|null>  $numbers
     */
    public static function nextSequenceStart(array $numbers): int
    {
        $max = 0;

        foreach ($numbers as $number) {
            if ($number === null) {
                continue;
            }

            if (preg_match(self::PATTERN, $number, $matches) !== 1) {
                continue;
            }

            if (self::sequenceTextOverflows($matches[1])) {
                throw new InvalidArgumentException(
                    'An existing Calibration Number exceeds the allocator range.',
                );
            }

            $sequence = (int) $matches[1];

            if ($sequence > $max) {
                $max = $sequence;
            }
        }

        if ($max === PHP_INT_MAX) {
            throw new InvalidArgumentException(
                'An existing Calibration Number exceeds the allocator range.',
            );
        }

        return $max + 1;
    }

    public static function sequenceTextOverflows(string $sequenceText): bool
    {
        $length = strlen($sequenceText);

        if ($length > 19) {
            return true;
        }

        return $length === 19 && $sequenceText > self::BIGINT_MAX;
    }
}
