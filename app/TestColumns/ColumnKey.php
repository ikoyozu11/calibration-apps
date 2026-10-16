<?php

namespace App\TestColumns;

use Illuminate\Support\Str;

class ColumnKey
{
    /**
     * @param  list<string>  $taken
     */
    public static function fromLabel(string $label, array $taken): string
    {
        $base = Str::of($label)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();
        $base = rtrim(substr($base, 0, 63), '_');

        if ($base === '' || ! preg_match('/^[a-z]/', $base)) {
            $base = rtrim(substr('col_'.$base, 0, 63), '_');
        }

        if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $base)) {
            $base = 'column';
        }

        $key = $base;
        $suffix = 2;
        $used = array_fill_keys($taken, true);

        while (isset($used[$key])) {
            $tail = '_'.$suffix;
            $key = rtrim(substr($base, 0, 63 - strlen($tail)), '_').$tail;
            $suffix++;

            if ($suffix > 100) {
                return 'column_'.substr(sha1($label), 0, 8);
            }
        }

        return $key;
    }
}
