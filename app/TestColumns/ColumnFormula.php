<?php

namespace App\TestColumns;

use InvalidArgumentException;

class ColumnFormula
{
    public const Average = 'average';

    public const Deviation = 'deviation';

    public const StandardMinusReading = 'standard_minus_reading';

    public const ReadingMinusStandard = 'reading_minus_standard';

    /**
     * @return list<string>
     */
    public static function codesFor(string $dataType): array
    {
        return match ($dataType) {
            'numeric' => [
                self::Average,
                self::Deviation,
                self::StandardMinusReading,
                self::ReadingMinusStandard,
            ],
            'duration' => [
                self::Deviation,
                self::StandardMinusReading,
                self::ReadingMinusStandard,
            ],
            default => [],
        };
    }

    public static function usesPairedInputs(string $code): bool
    {
        return in_array($code, [
            self::Deviation,
            self::StandardMinusReading,
            self::ReadingMinusStandard,
        ], true);
    }

    /**
     * @param  list<int>  $seconds
     */
    public static function durationDifference(string $code, array $seconds): int
    {
        if (! self::usesPairedInputs($code) || count($seconds) !== 2) {
            throw new InvalidArgumentException('Duration formulas need two whole-second values.');
        }

        return $seconds[0] - $seconds[1];
    }

    /**
     * @param  list<string>  $numbers
     */
    public static function numericResult(string $code, array $numbers): string
    {
        if ($numbers === []) {
            throw new InvalidArgumentException('A formula needs at least one number.');
        }

        if ($code === self::Average) {
            $sum = '0';

            foreach ($numbers as $number) {
                $sum = bcadd($sum, $number, 12);
            }

            return bcdiv($sum, (string) count($numbers), 12);
        }

        if (! self::usesPairedInputs($code) || count($numbers) !== 2) {
            throw new InvalidArgumentException('This formula needs two numbers.');
        }

        return bcsub($numbers[0], $numbers[1], 12);
    }
}
