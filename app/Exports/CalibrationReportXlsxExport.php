<?php

namespace App\Exports;

use App\Reports\CalibrationReport;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class CalibrationReportXlsxExport
{
    public function __construct(
        private Connection $connection,
        private int $year,
        private string $location,
        private string $search,
    ) {}

    public function spreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Calibration Report');
        $sheet->setShowGridlines(false);

        $location = $this->location === '' ? 'All Locations' : $this->location;
        $sheet->setCellValue('A1', 'CALIBRATION REPORT '.$this->year);
        $sheet->setCellValue('A2', 'Calibration Year:');
        $this->setText($sheet, 'B2', (string) $this->year);
        $sheet->setCellValue('E2', 'Location:');
        $this->setText($sheet, 'F2', $location);
        $sheet->setCellValue('I2', 'Generated Date:');
        $this->setText($sheet, 'J2', now()->setTimezone('Asia/Jakarta')->format('d-M-Y H:i'));
        $sheet->mergeCells('B2:C2');
        $sheet->mergeCells('F2:G2');
        $sheet->mergeCells('J2:K2');

        $headerRow = 4;
        foreach (CalibrationReport::HEADERS as $column => $header) {
            $this->setText($sheet, Coordinate::stringFromColumnIndex($column + 1).$headerRow, $header);
        }

        $dataRow = $headerRow + 1;
        $statusColors = [];
        foreach (CalibrationReport::cursor($this->connection, $this->year, $this->location, $this->search) as $row) {
            foreach ([
                'A' => $row['instrument_name'],
                'B' => $row['brand'],
                'C' => $row['instrument_type'],
                'D' => $row['model'],
                'E' => $row['serial_number'],
                'F' => $row['asset_number'],
                'J' => $row['location'],
                'K' => $row['detail_location'],
            ] as $column => $value) {
                $this->setText($sheet, $column.$dataRow, (string) $value);
            }

            $this->setDate($sheet, 'G'.$dataRow, $row['calibration_date']);
            $this->setDate($sheet, 'H'.$dataRow, $row['calibration_due']);
            $this->setText($sheet, 'I'.$dataRow, (string) $row['due_status']);
            $statusColors[$dataRow] = $this->statusColor($row['due_status']);

            $dataRow++;
        }

        $lastRow = max($headerRow, $dataRow - 1);
        $sheet->mergeCells('A1:K1');
        $sheet->getStyle('A1:K1')->applyFromArray([
            'font' => ['name' => 'Calibri', 'size' => 18, 'bold' => true, 'color' => ['rgb' => '000000']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('A2:K2')->applyFromArray([
            'font' => ['name' => 'Calibri', 'size' => 11, 'color' => ['rgb' => '000000']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        foreach (['A2:C2', 'E2:G2', 'I2:K2'] as $metadataBox) {
            $sheet->getStyle($metadataBox)->applyFromArray([
                'borders' => [
                    'outline' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ]);
        }
        $sheet->getStyle('A2:A2')->getFont()->setBold(true);
        $sheet->getStyle('E2:E2')->getFont()->setBold(true);
        $sheet->getStyle('I2:I2')->getFont()->setBold(true);
        $sheet->getStyle('B2:C2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F2:G2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('J2:K2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A{$headerRow}:K{$headerRow}")->applyFromArray([
            'font' => ['name' => 'Calibri', 'size' => 11, 'bold' => true, 'color' => ['rgb' => '000000']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B4C6E7']],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getStyle('A'.($headerRow + 1).":K{$lastRow}")->applyFromArray([
            'font' => ['name' => 'Calibri', 'size' => 11, 'color' => ['rgb' => '000000']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFFFF']],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getStyle('G'.($headerRow + 1).":H{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('I'.($headerRow + 1).":I{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        foreach ($statusColors as $rowNumber => $color) {
            $sheet->getStyle("I{$rowNumber}")->getFill()->setFillType(Fill::FILL_SOLID);
            $sheet->getStyle("I{$rowNumber}")->getFill()->getStartColor()->setRGB($color);
        }

        foreach ([
            'A' => 55.5, 'B' => 27.5, 'C' => 41, 'D' => 22, 'E' => 24,
            'F' => 24, 'G' => 22, 'H' => 22, 'I' => 25, 'J' => 18, 'K' => 24,
        ] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->getRowDimension(1)->setRowHeight(40.5);
        $sheet->getRowDimension(2)->setRowHeight(28.5);
        $sheet->getRowDimension(3)->setRowHeight(18);
        $sheet->getRowDimension($headerRow)->setRowHeight(30);
        for ($row = $headerRow + 1; $row <= $lastRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(21);
        }

        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A{$headerRow}:K{$lastRow}");
        $sheet->getPageSetup()
            ->setOrientation('landscape')
            ->setPaperSize(9)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.5)->setRight(0.3)->setBottom(0.5)->setLeft(0.3);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);
        $sheet->getPageSetup()->setPrintArea("A1:K{$lastRow}");

        return $spreadsheet;
    }

    public function writer(): Xlsx
    {
        return new Xlsx($this->spreadsheet());
    }

    private function excelDate(?string $date): ?float
    {
        return $date === null ? null : Date::PHPToExcel(Carbon::parse($date));
    }

    private function setText(Worksheet $sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private function setDate(Worksheet $sheet, string $cell, ?string $date): void
    {
        if ($date === null) {
            $sheet->setCellValue($cell, null);

            return;
        }

        $sheet->setCellValue($cell, $this->excelDate($date));
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('d-mmm-yyyy');
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            'On Track' => '00B050',
            'Overdue' => 'FF0000',
            'Due Soon' => 'FFF2CC',
            'Not Calibrated' => 'D9D9D9',
            'In Progress' => 'DDEBF7',
            'Cancelled' => 'F2F2F2',
            default => 'E7E6E6',
        };
    }
}
