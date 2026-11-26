<?php

namespace App\Dashboard;

use App\Models\Calibration;
use App\Models\Instrument;
use DateTimeInterface;
use Illuminate\Database\Connection;
use InvalidArgumentException;

final class HomeOperationalViewModel
{
    /**
     * @param  array{total: int, segments: list<array{label: string, count: int, color: string, percent: string}>}  $calibrationHealth
     * @param  list<array{instrument_id: int, instrument_name: string, serial_number: string, calibration_due: string, status: string, detail_url: string}>  $needsAttention
     * @param  list<array{location: string, count: int}>  $locationOverview
     * @param  list<array{key: string, calibration_id: int, instrument_name: string, serial_number: string, event: string, status: string, timestamp: string, timestamp_label: string, detail_url: string}>  $recentActivity
     */
    public function __construct(
        public int $totalInstruments,
        public int $onTrack,
        public int $dueSoon,
        public int $overdue,
        public int $notCalibrated,
        public array $calibrationHealth,
        public array $needsAttention,
        public array $locationOverview,
        public array $recentActivity,
    ) {}

    public static function current(Connection $connection): self
    {
        $overview = InstrumentCalibrationOverview::current($connection);

        return new self(
            totalInstruments: $overview->total,
            onTrack: $overview->onTrack,
            dueSoon: $overview->dueSoon,
            overdue: $overview->overdue,
            notCalibrated: $overview->notCalibrated,
            calibrationHealth: [
                'total' => $overview->total,
                'segments' => $overview->segments(),
            ],
            needsAttention: $overview->attention,
            locationOverview: self::locationOverview($connection),
            recentActivity: self::recentActivity($connection),
        );
    }

    /**
     * @return list<array{location: string, count: int}>
     */
    private static function locationOverview(Connection $connection): array
    {
        $name = self::connectionName($connection);

        return Instrument::on($name)
            ->with('detailLocation.location')
            ->get()
            ->groupBy(fn (Instrument $instrument): string => $instrument->detailLocation?->location?->location_name ?: 'Unassigned')
            ->map(fn ($instruments, string $location): array => [
                'location' => $location,
                'count' => $instruments->count(),
            ])
            ->sort(function (array $left, array $right): int {
                $countComparison = $right['count'] <=> $left['count'];

                return $countComparison !== 0
                    ? $countComparison
                    : strnatcasecmp($left['location'], $right['location']);
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{key: string, calibration_id: int, instrument_name: string, serial_number: string, event: string, status: string, timestamp: string, timestamp_label: string, detail_url: string}>
     */
    private static function recentActivity(Connection $connection): array
    {
        $name = self::connectionName($connection);
        $calibrations = Calibration::on($name)
            ->with([
                'instrument',
                'instrumentType',
            ])
            ->orderByDesc('calibration_id')
            ->get();
        $events = [];

        foreach ($calibrations as $calibration) {
            $instrumentName = self::firstFilled([
                $calibration->getAttribute('snapshot_instrument_name'),
                $calibration->instrument?->instrument_name,
            ]);
            $serialNumber = self::firstFilled([
                $calibration->getAttribute('snapshot_serial_number'),
                $calibration->instrument?->serial_number,
            ]);

            self::appendEvent($events, $calibration, 'Started', $calibration->getAttribute('created_at'), $instrumentName, $serialNumber);
            self::appendEvent($events, $calibration, 'Completed', $calibration->getAttribute('completed_at'), $instrumentName, $serialNumber);
            self::appendEvent($events, $calibration, 'Cancelled', $calibration->getAttribute('cancelled_at'), $instrumentName, $serialNumber);
        }

        usort($events, static fn (array $left, array $right): int => $right['timestamp_value'] <=> $left['timestamp_value']);

        return array_map(static function (array $event): array {
            unset($event['timestamp_value']);

            return $event;
        }, $events);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private static function appendEvent(
        array &$events,
        Calibration $calibration,
        string $event,
        mixed $timestamp,
        mixed $instrumentName,
        mixed $serialNumber,
    ): void {
        if (! $timestamp instanceof DateTimeInterface) {
            return;
        }

        $events[] = [
            'key' => $calibration->calibration_id.':'.strtolower($event),
            'calibration_id' => (int) $calibration->calibration_id,
            'instrument_name' => filled($instrumentName) ? (string) $instrumentName : '—',
            'serial_number' => filled($serialNumber) ? (string) $serialNumber : '—',
            'event' => $event,
            'status' => CalibrationSessionHistory::statusLabel((string) $calibration->session_status),
            'timestamp' => $timestamp->toIso8601String(),
            'timestamp_label' => $timestamp->format('d M Y, H:i'),
            'timestamp_value' => $timestamp->getTimestamp(),
            'detail_url' => route('calibrations.show', $calibration->calibration_id),
        ];
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function firstFilled(array $values): mixed
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function connectionName(Connection $connection): string
    {
        $name = $connection->getName();

        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException('Home operational queries require a named connection.');
        }

        return $name;
    }
}
