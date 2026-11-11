<?php

namespace App\Certificates;

use App\Models\Certificate;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfDocument;

final class ServiceReportPdfRenderer
{
    public function render(Certificate $certificate): DomPdfDocument
    {
        $pdf = Pdf::setOption('fontDir', storage_path('fonts'))
            ->setOption('fontCache', storage_path('fonts'))
            ->setOption('chroot', [base_path()])
            ->loadView('certificates.service-report-v2', [
                'report' => ServiceReport::from($certificate),
            ])->setPaper('a4', 'portrait');

        $this->registerNotoSansFonts($pdf);

        $footerColor = [0.451, 0.451, 0.451];
        $headerTitleColor = [0.09, 0.09, 0.09];
        $headerNumberColor = [0.322, 0.322, 0.322];
        $separatorColor = [0.639, 0.639, 0.639];
        $copyright = 'Copyright '.chr(194).chr(169).' '.$certificate->issued_date->format('Y').' PT Vision Teknik. All rights reserved.';
        $logoPath = public_path('images/vision-teknik-logo.png');
        $logoWidth = 39.2 * (300 / 146);

        $pdf->getDomPDF()->setCallbacks([[
            'event' => 'end_document',
            'f' => static function (int $pageNumber, int $pageCount, $canvas, $fontMetrics) use ($footerColor, $headerTitleColor, $headerNumberColor, $separatorColor, $copyright, $certificate, $logoPath, $logoWidth): void {
                $font = $fontMetrics->getFont('Noto Sans', 'normal');
                $pageWidth = $canvas->get_width();
                $pageHeight = $canvas->get_height();
                $leftMargin = 35;
                $rightMargin = 35;
                $footerSeparatorY = $pageHeight - 39;
                $footerTextY = $pageHeight - 32;

                if ($pageNumber > 1) {
                    $headerBoldFont = $fontMetrics->getFont('Noto Sans', 'bold');
                    $headerTitle = 'SERVICE REPORT';
                    $headerTitleSize = 12.0;
                    $headerNumberSize = 8.4;
                    $headerTop = 5;
                    $headerTitleHeight = $fontMetrics->getFontHeight($headerBoldFont, $headerTitleSize);
                    $headerNumberHeight = $fontMetrics->getFontHeight($font, $headerNumberSize);
                    $logoHeight = 39.2;
                    $headerLineHeight = 12;
                    $metadataLineGap = 0;
                    $metadataHeight = ($headerLineHeight * 2) + $metadataLineGap;
                    $headerRowHeight = max($logoHeight, $headerTitleHeight, $metadataHeight);
                    $headerCenterY = $headerTop + ($headerRowHeight / 2);
                    $headerMetadataY = $headerCenterY - ($metadataHeight / 2);
                    $headerCalibrationDateY = $headerMetadataY + $headerLineHeight + $metadataLineGap;
                    $headerTitleY = $headerCenterY - ($headerTitleHeight / 2);
                    $headerSeparatorY = $headerTop + $headerRowHeight + 7;
                    $headerTitleWidth = $fontMetrics->getTextWidth($headerTitle, $headerBoldFont, $headerTitleSize);
                    $certificateNumberText = 'Document Number: '.$certificate->certificate_number;
                    $calibrationDate = $certificate->calibration->calibration_date?->format('d M Y') ?? '—';
                    $calibrationDateText = 'Calibration Date: '.$calibrationDate;
                    $certificateNumberX = $pageWidth - $rightMargin - $fontMetrics->getTextWidth($certificateNumberText, $font, $headerNumberSize);
                    $calibrationDateX = $pageWidth - $rightMargin - $fontMetrics->getTextWidth($calibrationDateText, $font, $headerNumberSize);

                    $canvas->image($logoPath, $leftMargin, $headerCenterY - ($logoHeight / 2), $logoWidth, $logoHeight);
                    $canvas->text(($pageWidth - $headerTitleWidth) / 2, $headerTitleY, $headerTitle, $headerBoldFont, $headerTitleSize, $headerTitleColor);
                    $canvas->text($certificateNumberX, $headerMetadataY, $certificateNumberText, $font, $headerNumberSize, $headerNumberColor);
                    $canvas->text($calibrationDateX, $headerCalibrationDateY, $calibrationDateText, $font, $headerNumberSize, $headerNumberColor);
                    $canvas->line($leftMargin, $headerSeparatorY, $pageWidth - $rightMargin, $headerSeparatorY, $separatorColor, 0.5);
                }

                if ($pageNumber === $pageCount) {
                    $statement = 'Electronically generated and approved. No wet signature is required.';
                    $statementSize = 6.8;
                    $statementColor = $footerColor;
                    $statementWidth = $pageWidth - $leftMargin - $rightMargin;
                    $statementLines = [];
                    $currentLine = '';

                    foreach (preg_split('/\s+/', $statement) ?: [] as $word) {
                        $candidate = $currentLine === '' ? $word : $currentLine.' '.$word;

                        if ($currentLine !== '' && $fontMetrics->getTextWidth($candidate, $font, $statementSize) > $statementWidth) {
                            $statementLines[] = $currentLine;
                            $currentLine = $word;
                        } else {
                            $currentLine = $candidate;
                        }
                    }

                    if ($currentLine !== '') {
                        $statementLines[] = $currentLine;
                    }

                    $statementLineHeight = $fontMetrics->getFontHeight($font, $statementSize);
                    $statementLineGap = 2;
                    $statementBlockHeight = (count($statementLines) * $statementLineHeight)
                        + (max(0, count($statementLines) - 1) * $statementLineGap);
                    $statementTop = $footerSeparatorY - 10 - $statementBlockHeight;
                    $statementTextWidths = array_map(
                        static fn (string $line): float => $fontMetrics->getTextWidth($line, $font, $statementSize),
                        $statementLines,
                    );
                    $statementTextWidth = max($statementTextWidths);
                    $checkboxSize = min(6.2, max(5.2, $statementLineHeight - 2.5));
                    $checkboxGap = 4;
                    $groupWidth = $checkboxSize + $checkboxGap + $statementTextWidth;
                    $groupLeft = $leftMargin + (($statementWidth - $groupWidth) / 2);
                    $checkboxVerticalOffset = 2;
                    $checkboxTop = $statementTop + (($statementBlockHeight - $checkboxSize) / 2) + $checkboxVerticalOffset;
                    $checkboxRight = $groupLeft + $checkboxSize;
                    $checkboxBottom = $checkboxTop + $checkboxSize;

                    $canvas->line($groupLeft, $checkboxTop, $checkboxRight, $checkboxTop, $statementColor, 0.7);
                    $canvas->line($checkboxRight, $checkboxTop, $checkboxRight, $checkboxBottom, $statementColor, 0.7);
                    $canvas->line($checkboxRight, $checkboxBottom, $groupLeft, $checkboxBottom, $statementColor, 0.7);
                    $canvas->line($groupLeft, $checkboxBottom, $groupLeft, $checkboxTop, $statementColor, 0.7);
                    $canvas->line(
                        $groupLeft + ($checkboxSize * 0.2),
                        $checkboxTop + ($checkboxSize * 0.52),
                        $groupLeft + ($checkboxSize * 0.44),
                        $checkboxTop + ($checkboxSize * 0.76),
                        $statementColor,
                        0.8,
                    );
                    $canvas->line(
                        $groupLeft + ($checkboxSize * 0.44),
                        $checkboxTop + ($checkboxSize * 0.76),
                        $groupLeft + ($checkboxSize * 0.84),
                        $checkboxTop + ($checkboxSize * 0.26),
                        $statementColor,
                        0.8,
                    );

                    foreach ($statementLines as $lineIndex => $line) {
                        $lineWidth = $statementTextWidths[$lineIndex];
                        $lineY = $statementTop + ($lineIndex * ($statementLineHeight + $statementLineGap));
                        $canvas->text(
                            $groupLeft + $checkboxSize + $checkboxGap + (($statementTextWidth - $lineWidth) / 2),
                            $lineY,
                            $line,
                            $font,
                            $statementSize,
                            $statementColor,
                        );
                    }
                }

                $canvas->line($leftMargin, $footerSeparatorY, $pageWidth - $rightMargin, $footerSeparatorY, $separatorColor, 0.45);
                $canvas->text($leftMargin, $footerTextY, $copyright, $font, 6.8, $footerColor);
                $pageNumberText = "Page {$pageNumber} of {$pageCount}";
                $pageNumberWidth = $fontMetrics->getTextWidth($pageNumberText, $font, 6.8);
                $canvas->text($pageWidth - $rightMargin - $pageNumberWidth, $footerTextY, $pageNumberText, $font, 6.8, $footerColor);
            },
        ]]);

        return $pdf;
    }

    private function registerNotoSansFonts(DomPdfDocument $pdf): void
    {
        $fontMetrics = $pdf->getDomPDF()->getFontMetrics();
        $fontDirectory = resource_path('fonts/noto-sans');
        $fonts = [
            ['weight' => 'normal', 'file' => 'NotoSans-Regular.ttf'],
            ['weight' => 600, 'file' => 'NotoSans-SemiBold.ttf'],
            ['weight' => 'bold', 'file' => 'NotoSans-Bold.ttf'],
        ];

        foreach ($fonts as $font) {
            $path = $fontDirectory.DIRECTORY_SEPARATOR.$font['file'];

            if (! is_file($path)) {
                throw new \RuntimeException('Missing required SERVICE REPORT font: '.$path);
            }

            $registered = $fontMetrics->registerFont([
                'family' => 'Noto Sans',
                'style' => 'normal',
                'weight' => $font['weight'],
            ], $this->fileUri($path));

            if ($registered === false) {
                throw new \RuntimeException('Unable to register SERVICE REPORT font: '.$path);
            }
        }
    }

    private function fileUri(string $path): string
    {
        return 'file://'.str_replace('\\', '/', $path);
    }
}
