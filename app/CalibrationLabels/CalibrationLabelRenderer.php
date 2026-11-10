<?php

namespace App\CalibrationLabels;

use App\Models\Calibration;
use Carbon\CarbonInterface;
use GdImage;

final class CalibrationLabelRenderer
{
    public const WIDTH = 827;

    public const HEIGHT = 472;

    private const DPI = 300;

    private const OUTER_MARGIN = 10;

    private const TOP_PANEL_TOP = 13;

    private const LEFT_COLUMN_END = 277;

    private const RIGHT_COLUMN_END = 818;

    private const LOWER_PANEL_TOP = 196;

    private const LOWER_PANEL_BOTTOM = 460;

    private const TOP_PANEL_BOTTOM = 196;

    private const COMPANY_TEXT_RIGHT = 782;

    private const WEBSITE_LEFT = 327;

    private const WEBSITE_TOP = 411;

    private const WEBSITE_BOTTOM = 460;

    private const COLON_X = 297;

    private const VALUE_X = 310;

    private const VALUE_END_X = 794;

    private const ROW_TOPS = [214, 261, 307, 351];

    private const ROW_BASELINES = [245, 289, 334, 379];

    private const ROW_LINES = [254, 301, 347, 391];

    private const NAVY = [9, 54, 135];

    private const NAVY_DARK = [4, 43, 112];

    private const NAVY_LIGHT = [166, 199, 246];

    private string $regularFont;

    private string $boldFont;

    public function __construct()
    {
        $fontDirectory = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'fonts'.DIRECTORY_SEPARATOR.'noto-sans';
        $this->regularFont = $fontDirectory.DIRECTORY_SEPARATOR.'NotoSans-Regular.ttf';
        $this->boldFont = $fontDirectory.DIRECTORY_SEPARATOR.'NotoSans-Bold.ttf';

        foreach ([$this->regularFont, $this->boldFont] as $font) {
            if (! is_file($font)) {
                throw new CalibrationLabelException('Required Calibration Label font is missing: '.$font);
            }
        }
    }

    /**
     * @return array{instrument_name: string, serial_number: string, calibration_date: string, calibration_due: string}
     */
    public function dataFromCalibration(Calibration $calibration): array
    {
        $calibration->loadMissing(['instrument']);

        if ((string) $calibration->calibration_method !== Calibration::METHOD_INTERNAL
            || (string) $calibration->session_status !== Calibration::STATUS_COMPLETED) {
            throw CalibrationLabelException::unsupportedSession();
        }

        $values = [
            'instrument_name' => $calibration->snapshot_instrument_name ?? $calibration->instrument?->instrument_name,
            'serial_number' => $calibration->snapshot_serial_number ?? $calibration->instrument?->serial_number,
            'calibration_date' => $this->formatDate($calibration->calibration_date),
            'calibration_due' => $this->formatDate($calibration->calibration_due),
        ];

        foreach ($values as $field => $value) {
            if (! is_string($value) || trim($value) === '') {
                throw CalibrationLabelException::unavailableValue(str_replace('_', ' ', $field));
            }
        }

        return $values;
    }

    /**
     * @param  array{instrument_name: string, serial_number: string, calibration_date: string, calibration_due: string}  $data
     */
    public function render(array $data): string
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if (! $image instanceof GdImage) {
            throw new CalibrationLabelException('The GD image renderer is unavailable.');
        }

        imagealphablending($image, true);
        imagesavealpha($image, false);

        $white = $this->color($image, [255, 255, 255]);
        $black = $this->color($image, [18, 18, 18]);
        $navy = $this->color($image, self::NAVY);
        $navyDark = $this->color($image, self::NAVY_DARK);

        imagefill($image, 0, 0, $white);
        $this->roundedRectangle(
            $image,
            self::OUTER_MARGIN,
            self::OUTER_MARGIN,
            self::WIDTH - self::OUTER_MARGIN,
            self::HEIGHT - self::OUTER_MARGIN,
            8,
            $white,
            null,
        );

        $this->drawLogo($image);
        $this->drawHorizontalGradient($image, self::LEFT_COLUMN_END, self::TOP_PANEL_TOP, self::RIGHT_COLUMN_END, self::TOP_PANEL_BOTTOM, self::NAVY_DARK, self::NAVY);
        $this->drawCompanyInformation($image, $white);

        imagefilledrectangle($image, self::OUTER_MARGIN, self::LOWER_PANEL_TOP, self::LEFT_COLUMN_END, self::LOWER_PANEL_BOTTOM, $navy);
        $this->drawFieldLabels($image, $white);

        $this->drawFieldValues($image, $data, $black, $navy);
        $this->drawWebsiteStrip($image, $navy, $navyDark);

        ob_start();
        imagepng($image, null, 6);
        $png = ob_get_clean();
        imagedestroy($image);

        if (! is_string($png) || $png === '') {
            throw new CalibrationLabelException('Calibration Label PNG rendering failed.');
        }

        return $this->withDpiMetadata($png);
    }

    private function drawLogo(GdImage $image): void
    {
        $logoPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.'vision-teknik-logo.png';
        $logo = @imagecreatefrompng($logoPath);

        if (! $logo instanceof GdImage) {
            throw new CalibrationLabelException('Official Vision Teknik logo cannot be loaded.');
        }

        $targetWidth = 235;
        $targetHeight = (int) round(imagesy($logo) * ($targetWidth / imagesx($logo)));
        imagecopyresampled($image, $logo, 27, 43, 0, 0, $targetWidth, $targetHeight, imagesx($logo), imagesy($logo));
        imagedestroy($logo);
    }

    private function drawCompanyInformation(GdImage $image, int $white): void
    {
        $lines = [
            ['PT. VISION TEKNIK', 22, $this->boldFont, 51],
            ['TOTAL Building 6th Floor Jl. Letjen S. Parman', 13, $this->boldFont, 93],
            ['Kav. 106 A Jakarta 11440 - Indonesia', 13, $this->boldFont, 112],
            ['Telp. +62 21 5695 1538 Fax. +62 21 5695 1539', 12, $this->boldFont, 151],
            ['Email. service@vision-teknik.com', 12, $this->boldFont, 170],
        ];

        foreach ($lines as [$text, $size, $font, $baseline]) {
            $this->rightText($image, $text, self::COMPANY_TEXT_RIGHT, $baseline, $size, $font, $white);
        }

        foreach ([49, 72, 95] as $y) {
            imagefilledrectangle($image, self::RIGHT_COLUMN_END - 13, $y, self::RIGHT_COLUMN_END, $y + 5, $white);
        }
    }

    private function drawFieldLabels(GdImage $image, int $white): void
    {
        foreach (['Instrument Name', 'Serial Number', 'Calibration Date', 'Calibration Due'] as $index => $label) {
            $this->text($image, $label, 37, self::ROW_BASELINES[$index], 18, $this->boldFont, $white);
        }
    }

    /**
     * @param  array{instrument_name: string, serial_number: string, calibration_date: string, calibration_due: string}  $data
     */
    private function drawFieldValues(GdImage $image, array $data, int $black, int $navy): void
    {
        $fields = [
            'instrument_name' => 'Instrument Name',
            'serial_number' => 'Serial Number',
            'calibration_date' => 'Calibration Date',
            'calibration_due' => 'Calibration Due',
        ];

        foreach (array_values($fields) as $index => $field) {
            $value = $data[array_keys($fields)[$index]];
            $fit = $this->fitText($value, self::VALUE_X + 10, self::VALUE_END_X - 6, self::ROW_TOPS[$index], 38, $field);
            $textHeight = count($fit['lines']) * $fit['line_height'];
            $baseline = self::ROW_TOPS[$index]
                + (int) floor((self::ROW_LINES[$index] - self::ROW_TOPS[$index] - $textHeight) / 2)
                + $fit['size'];
            foreach ($fit['lines'] as $lineIndex => $line) {
                $this->text($image, $line, self::VALUE_X + 10, $baseline + ($lineIndex * $fit['line_height']), $fit['size'], $this->boldFont, $black);
            }

            $this->text($image, ':', self::COLON_X, self::ROW_BASELINES[$index], 21, $this->boldFont, $navy);
            imageline($image, self::VALUE_X, self::ROW_LINES[$index], self::VALUE_END_X, self::ROW_LINES[$index], $navy);
        }
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $start
     * @param  array{0: int, 1: int, 2: int}  $end
     */
    private function drawHorizontalGradient(GdImage $image, int $left, int $top, int $right, int $bottom, array $start, array $end): void
    {
        $width = max(1, $right - $left);

        for ($x = $left; $x <= $right; $x++) {
            $ratio = ($x - $left) / $width;
            $color = imagecolorallocate(
                $image,
                (int) round($start[0] + (($end[0] - $start[0]) * $ratio)),
                (int) round($start[1] + (($end[1] - $start[1]) * $ratio)),
                (int) round($start[2] + (($end[2] - $start[2]) * $ratio)),
            );
            imageline($image, $x, $top, $x, $bottom, $color);
        }
    }

    private function drawWebsiteStrip(GdImage $image, int $navy, int $navyDark): void
    {
        for ($x = self::WEBSITE_LEFT; $x <= self::RIGHT_COLUMN_END; $x++) {
            $ratio = ($x - self::WEBSITE_LEFT) / (self::RIGHT_COLUMN_END - self::WEBSITE_LEFT);
            $color = imagecolorallocate($image, (int) (9 + (166 * $ratio)), (int) (54 + (145 * $ratio)), (int) (135 + (111 * $ratio)));
            imageline($image, $x, self::WEBSITE_TOP, $x, self::WEBSITE_BOTTOM, $color);
        }

        foreach ([282, 291, 300, 309, 318] as $x) {
            imagefilledrectangle($image, $x, self::WEBSITE_TOP, $x + 4, self::WEBSITE_BOTTOM, $navyDark);
        }

        $this->text($image, 'www.vision-teknik.com', 343, 445, 22, $this->boldFont, imagecolorallocate($image, 255, 255, 255));
    }

    /** @return array{lines: list<string>, size: int, line_height: int} */
    private function fitText(string $value, int $minX, int $maxX, int $topY, int $maxHeight, string $field): array
    {
        $availableWidth = $maxX - $minX;

        for ($size = 21; $size >= 12; $size--) {
            $lines = $this->wrapText($value, $this->boldFont, $size, $availableWidth);
            $lineHeight = (int) round($size * 1.18);
            if (count($lines) <= 2
                && (count($lines) * $lineHeight) <= $maxHeight
                && ! array_filter($lines, fn (string $line): bool => $this->textWidth($line, $this->boldFont, $size) > $availableWidth)) {
                return ['lines' => $lines, 'size' => $size, 'line_height' => $lineHeight];
            }
        }

        throw CalibrationLabelException::textDoesNotFit($field);
    }

    /** @return list<string> */
    private function wrapText(string $value, string $font, int $size, int $width): array
    {
        $words = preg_split('/\s+/', trim($value)) ?: [];
        $lines = [];
        $line = '';

        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($line !== '' && $this->textWidth($candidate, $font, $size) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        if (count($lines) <= 2 && ! array_filter($lines, fn (string $line): bool => $this->textWidth($line, $font, $size) > $width)) {
            return $lines;
        }

        $characters = preg_split('//u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = '';
        foreach ($characters as $character) {
            if ($this->textWidth($first.$character, $font, $size) > $width) {
                break;
            }
            $first .= $character;
        }

        $second = trim(mb_substr(trim($value), mb_strlen($first)));

        if ($second !== '' && $this->textWidth($second, $font, $size) > $width) {
            return [$first, $second, ''];
        }

        return [$first, $second];
    }

    private function text(GdImage $image, string $text, int $x, int $baseline, int $size, string $font, int $color): void
    {
        imagettftext($image, $size, 0, $x, $baseline, $color, $font, $text);
    }

    private function rightText(GdImage $image, string $text, int $right, int $baseline, int $size, string $font, int $color): void
    {
        $this->text($image, $text, $right - $this->textWidth($text, $font, $size), $baseline, $size, $font, $color);
    }

    private function centerText(GdImage $image, string $text, int $left, int $right, int $baseline, int $size, string $font, int $color): void
    {
        $x = $left + (int) floor(($right - $left - $this->textWidth($text, $font, $size)) / 2);
        $this->text($image, $text, $x, $baseline, $size, $font, $color);
    }

    private function textWidth(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return (int) abs($box[2] - $box[0]);
    }

    private function color(GdImage $image, array $rgb): int
    {
        return imagecolorallocate($image, ...$rgb);
    }

    private function roundedRectangle(GdImage $image, int $left, int $top, int $right, int $bottom, int $radius, int $fill, ?int $border): void
    {
        imagefilledrectangle($image, $left + $radius, $top, $right - $radius, $bottom, $fill);
        imagefilledrectangle($image, $left, $top + $radius, $right, $bottom - $radius, $fill);
        imagefilledellipse($image, $left + $radius, $top + $radius, $radius * 2, $radius * 2, $fill);
        imagefilledellipse($image, $right - $radius, $top + $radius, $radius * 2, $radius * 2, $fill);
        imagefilledellipse($image, $left + $radius, $bottom - $radius, $radius * 2, $radius * 2, $fill);
        imagefilledellipse($image, $right - $radius, $bottom - $radius, $radius * 2, $radius * 2, $fill);

        if ($border !== null) {
            imagerectangle($image, $left, $top, $right, $bottom, $border);
        }
    }

    private function formatDate(?CarbonInterface $date): ?string
    {
        return $date?->format('d M Y');
    }

    private function withDpiMetadata(string $png): string
    {
        $signature = "\x89PNG\r\n\x1a\n";
        if (! str_starts_with($png, $signature)) {
            return $png;
        }

        $pixelsPerMeter = (int) round(self::DPI / 0.0254);
        $chunkData = pack('NNc', $pixelsPerMeter, $pixelsPerMeter, 1);
        $existingPhys = strpos($png, 'pHYs');
        if ($existingPhys !== false) {
            return substr($png, 0, $existingPhys + 4)
                .$chunkData
                .pack('N', crc32('pHYs'.$chunkData))
                .substr($png, $existingPhys + 17);
        }

        $chunk = pack('N', strlen($chunkData)).'pHYs'.$chunkData.pack('N', crc32('pHYs'.$chunkData));
        $offset = 8;
        $length = unpack('N', substr($png, $offset, 4))[1];
        $afterIhdr = $offset + 12 + $length;

        return substr($png, 0, $afterIhdr).$chunk.substr($png, $afterIhdr);
    }
}
