<?php

namespace App\Dashboard;

use App\Models\Calibration;
use App\Models\Instrument;
use Carbon\CarbonInterface;

final class InstrumentCalibrationOverview
{
    /**
     * @param  list<array{instrument_id: int, instrument_name: string, serial_number: string, calibration_date: string, calibration_due: string, status: string}>  $recent
     */
    public function __construct(
        public int $total,
        public int $onTrack,
        public int $overdue,
        public int $dueSoon,
        public int $notCalibrated,
        public array $recent,
    ) {}

    public static function current(?string $name = null): self
    {
        $instruments = Instrument::query()
            ->select([
                'instrument_id',
                'instrument_name',
                'created_at',
            ])
            ->with([
                'calibrations:calibration_id,instrument_id,calibration_date,calibration_due',
                'instrumentTypes:instrument_type_id,instrument_id,serial_number',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('instrument_id')
            ->get();

        $onTrack = 0;
        $overdue = 0;
        $dueSoon = 0;
        $notCalibrated = 0;
        $recent = [];
        $needle = self::nameNeedle($name);

        foreach ($instruments as $instrument) {
            $calibration = self::latestCalibration($instrument);
            $standing = CalibrationStanding::fromLatest($calibration);

            if ($standing === CalibrationStanding::OnTrack) {
                $onTrack++;
            } elseif ($standing === CalibrationStanding::Overdue) {
                $overdue++;
            } elseif ($standing === CalibrationStanding::DueSoon) {
                $dueSoon++;
            } else {
                $notCalibrated++;
            }

            if ($needle !== '' && mb_stripos($instrument->instrument_name, $needle) === false) {
                continue;
            }

            if (count($recent) >= 10) {
                continue;
            }

            $recent[] = [
                'instrument_id' => $instrument->instrument_id,
                'instrument_name' => $instrument->instrument_name,
                'serial_number' => self::serialNumber($instrument),
                'calibration_date' => self::dateLabel($calibration?->calibration_date),
                'calibration_due' => self::dateLabel($calibration?->calibration_due),
                'status' => $standing->value,
            ];
        }

        return new self(
            total: $instruments->count(),
            onTrack: $onTrack,
            overdue: $overdue,
            dueSoon: $dueSoon,
            notCalibrated: $notCalibrated,
            recent: $recent,
        );
    }

    /**
     * @return list<array{label: string, count: int, color: string, percent: string}>
     */
    public function segments(): array
    {
        return [
            $this->segment(CalibrationStanding::OnTrack, $this->onTrack, '#12B76A'),
            $this->segment(CalibrationStanding::DueSoon, $this->dueSoon, '#F5A524'),
            $this->segment(CalibrationStanding::Overdue, $this->overdue, '#F04438'),
            $this->segment(CalibrationStanding::NotCalibrated, $this->notCalibrated, '#7C93B0'),
        ];
    }

    /**
     * @return array{label: string, count: int, color: string, percent: string}
     */
    private function segment(CalibrationStanding $standing, int $count, string $color): array
    {
        return [
            'label' => $standing->value,
            'count' => $count,
            'color' => $color,
            'percent' => $this->percentLabel($count),
        ];
    }

    private function percentLabel(int $count): string
    {
        if ($this->total === 0 || $count === 0) {
            return '0%';
        }

        $percent = round(($count / $this->total) * 100, 1);
        $formatted = rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.');

        return $formatted.'%';
    }

    private static function nameNeedle(?string $name): string
    {
        $needle = trim((string) $name);

        if ($needle === '') {
            return '';
        }

        return mb_substr($needle, 0, 100);
    }

    private static function latestCalibration(Instrument $instrument): ?Calibration
    {
        return $instrument->calibrations
            ->sortByDesc(fn (Calibration $calibration): array => [
                $calibration->calibration_date?->getTimestamp() ?? PHP_INT_MIN,
                $calibration->calibration_id,
            ])
            ->first();
    }

    private static function serialNumber(Instrument $instrument): string
    {
        $serials = $instrument->instrumentTypes
            ->pluck('serial_number')
            ->filter(fn (?string $serial): bool => filled($serial))
            ->unique()
            ->values();

        if ($serials->count() !== 1) {
            return '—';
        }

        return (string) $serials->first();
    }

    private static function dateLabel(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '—';
        }

        return $date->format('Y-m-d');
    }
}
