<?php

namespace App\Exports;

use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvDownload
{
    /**
     * @param  list<string>  $header
     * @param  iterable<int, list<string>>  $rows
     */
    public static function response(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $header, ',', '"', '\\');

            foreach ($rows as $row) {
                fputcsv($handle, $row, ',', '"', '\\');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Prefix a leading formula trigger so a spreadsheet treats the cell as text.
     *
     * The apostrophe mitigates CSV formula injection. It is not a universal guarantee.
     */
    public static function cell(?string $value): string
    {
        $text = filled($value) ? $value : '—';

        if (preg_match('/\A[=+\-@\t\r\n]/', $text) === 1) {
            return "'".$text;
        }

        return $text;
    }

    public static function date(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '—';
        }

        return $date->format('Y-m-d');
    }

    public static function status(?CarbonInterface $due): string
    {
        if ($due === null) {
            return '—';
        }

        return today()->gt($due) ? 'Overdue' : 'On Track';
    }
}
