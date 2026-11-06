<?php

namespace App\CalibrationDueNotifications;

use Carbon\CarbonImmutable;

final readonly class CalibrationDueReminder
{
    public function __construct(
        public int $instrumentId,
        public int $instrumentTypeId,
        public int $latestCalibrationId,
        public string $calibrationMethod,
        public string $instrumentName,
        public string $instrumentType,
        public ?string $serialNumber,
        public ?string $location,
        public CarbonImmutable $calibrationDue,
        public string $milestoneCode,
        public CarbonImmutable $milestoneDate,
    ) {}

    /** @param object|array<string, mixed> $row */
    public static function fromSource(object|array $row, string $milestoneCode, CarbonImmutable $milestoneDate): self
    {
        $value = static fn (string $key): mixed => is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);

        return new self(
            instrumentId: (int) $value('instrument_id'),
            instrumentTypeId: (int) $value('instrument_type_id'),
            latestCalibrationId: (int) $value('latest_calibration_id'),
            calibrationMethod: (string) $value('calibration_method'),
            instrumentName: (string) $value('instrument_name'),
            instrumentType: (string) $value('instrument_type'),
            serialNumber: $value('serial_number') === null ? null : (string) $value('serial_number'),
            location: $value('location_name') === null ? null : (string) $value('location_name'),
            calibrationDue: CarbonImmutable::parse((string) $value('calibration_due'), CalibrationDueMilestoneCalculator::TIMEZONE)->startOfDay(),
            milestoneCode: $milestoneCode,
            milestoneDate: $milestoneDate,
        );
    }

    /** @param object|array<string, mixed> $row */
    public static function fromHistory(object|array $row): self
    {
        $value = static fn (string $key): mixed => is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);

        return new self(
            instrumentId: (int) $value('instrument_id'),
            instrumentTypeId: (int) $value('instrument_type_id'),
            latestCalibrationId: (int) $value('latest_calibration_id'),
            calibrationMethod: (string) $value('calibration_method'),
            instrumentName: (string) $value('instrument_name_snapshot'),
            instrumentType: (string) $value('instrument_type_snapshot'),
            serialNumber: $value('serial_number_snapshot') === null ? null : (string) $value('serial_number_snapshot'),
            location: $value('location_snapshot') === null ? null : (string) $value('location_snapshot'),
            calibrationDue: CarbonImmutable::parse((string) $value('due_date_snapshot'), CalibrationDueMilestoneCalculator::TIMEZONE)->startOfDay(),
            milestoneCode: (string) $value('milestone_code'),
            milestoneDate: CarbonImmutable::parse((string) $value('milestone_date'), CalibrationDueMilestoneCalculator::TIMEZONE)->startOfDay(),
        );
    }
}
