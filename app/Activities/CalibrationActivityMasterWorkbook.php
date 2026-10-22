<?php

namespace App\Activities;

use SimpleXMLElement;
use ZipArchive;

class CalibrationActivityMasterWorkbook
{
    public const SHEET_NAME = 'Activity Master';

    /**
     * @return list<array{activity_code: string, activity_name: string}>
     */
    public function rows(string $path): array
    {
        if (! is_file($path)) {
            throw new CalibrationActivityMasterException('The activity master workbook could not be found.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new CalibrationActivityMasterException('The activity master workbook could not be opened.');
        }

        try {
            $strings = $this->sharedStrings($zip);
            $sheetXml = $zip->getFromName($this->worksheetPath($zip, self::SHEET_NAME));

            if ($sheetXml === false) {
                throw new CalibrationActivityMasterException('The Activity Master sheet could not be read.');
            }

            $sheet = new SimpleXMLElement($sheetXml);
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
                    'activity_code' => $code,
                    'activity_name' => $name,
                ];
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function worksheetPath(ZipArchive $zip, string $sheetName): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relsXml === false) {
            throw new CalibrationActivityMasterException('The activity master workbook could not be read.');
        }

        $workbook = new SimpleXMLElement($workbookXml);
        $workbook->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rid = null;

        foreach ($workbook->xpath('//x:sheet') ?: [] as $sheet) {
            if ((string) $sheet['name'] !== $sheetName) {
                continue;
            }

            $rid = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            break;
        }

        if (! is_string($rid) || $rid === '') {
            throw new CalibrationActivityMasterException('The Activity Master sheet could not be found.');
        }

        $rels = new SimpleXMLElement($relsXml);
        $rels->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

        foreach ($rels->xpath('//r:Relationship') ?: [] as $relationship) {
            if ((string) $relationship['Id'] !== $rid) {
                continue;
            }

            $target = ltrim(str_replace('\\', '/', (string) $relationship['Target']), '/');

            if (! str_starts_with($target, 'xl/')) {
                $target = 'xl/'.$target;
            }

            return $target;
        }

        throw new CalibrationActivityMasterException('The Activity Master sheet could not be found.');
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
}
