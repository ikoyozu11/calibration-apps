<?php

namespace App\Standards;

use SimpleXMLElement;
use ZipArchive;

class StandardMasterWorkbook
{
    /**
     * @return list<array{standard_code: string, instrument_name: string, type_specification: ?string, serial_number: ?string}>
     */
    public function rows(string $path): array
    {
        if (! is_file($path)) {
            throw new StandardMasterException('The standard master workbook could not be found.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new StandardMasterException('The standard master workbook could not be opened.');
        }

        try {
            $strings = $this->sharedStrings($zip);
            $sheet = new SimpleXMLElement((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
            $sheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

            $rows = [];

            foreach ($sheet->xpath('//x:sheetData/x:row') ?: [] as $index => $row) {
                if ($index === 0) {
                    continue;
                }

                $cells = $this->cells($row, $strings);
                $code = trim((string) ($cells['A'] ?? ''));
                $name = trim((string) ($cells['B'] ?? ''));

                if ($code === '' && $name === '') {
                    continue;
                }

                $rows[] = [
                    'standard_code' => $code,
                    'instrument_name' => $name,
                    'type_specification' => $this->nullable($cells['C'] ?? null),
                    'serial_number' => $this->nullable($cells['D'] ?? null),
                ];
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $sst = new SimpleXMLElement($xml);
        $sst->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $strings = [];

        foreach ($sst->xpath('//x:si') ?: [] as $si) {
            $text = '';

            foreach ($si->xpath('.//x:t') ?: [] as $node) {
                $text .= (string) $node;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param  list<string>  $strings
     * @return array<string, string>
     */
    private function cells(SimpleXMLElement $row, array $strings): array
    {
        $cells = [];

        foreach ($row->xpath('./x:c') ?: [] as $cell) {
            $ref = (string) $cell['r'];
            $col = preg_replace('/\d+/', '', $ref) ?? '';
            $type = (string) $cell['t'];
            $value = (string) ($cell->xpath('./x:v')[0] ?? '');

            if ($type === 's') {
                $value = $strings[(int) $value] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cell->xpath('.//x:t')[0] ?? '');
            }

            $cells[$col] = $value;
        }

        return $cells;
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
