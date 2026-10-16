<?php

namespace App\TestColumns;

class NumericDisplay
{
    public static function format(string $stored, ?int $scale): string
    {
        $value = trim($stored);

        if ($value === '' || $scale === null) {
            return $value;
        }

        $dot = strpos($value, '.');
        $fraction = $dot === false ? '' : substr($value, $dot + 1);

        if (strlen($fraction) > $scale) {
            return $value;
        }

        return bcadd($value, '0', $scale);
    }
}
