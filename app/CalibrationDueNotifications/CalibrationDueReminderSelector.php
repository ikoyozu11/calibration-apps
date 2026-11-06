<?php

namespace App\CalibrationDueNotifications;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;

final class CalibrationDueReminderSelector
{
    public function __construct(
        private CurrentCalibrationDueResolver $dueResolver,
        private CalibrationDueMilestoneCalculator $calculator,
    ) {}

    /** @return Collection<int, CalibrationDueReminder> */
    public function select(Connection $connection, CarbonImmutable $now, int $catchUpDays = 3): Collection
    {
        return $this->dueResolver->resolve($connection)
            ->filter(fn (object $row): bool => $row->latest_calibration_id !== null && $row->calibration_due !== null)
            ->flatMap(function (object $row) use ($now, $catchUpDays): Collection {
                $due = CarbonImmutable::parse((string) $row->calibration_due, CalibrationDueMilestoneCalculator::TIMEZONE);

                return collect($this->calculator->eligibleMilestones($due, $now, $catchUpDays))
                    ->map(fn (array $milestone): CalibrationDueReminder => CalibrationDueReminder::fromSource(
                        $row,
                        $milestone['code'],
                        $milestone['date'],
                    ));
            })
            ->sortBy([
                fn (CalibrationDueReminder $reminder): int => match ($reminder->milestoneCode) {
                    CalibrationDueMilestoneCalculator::DUE_TODAY => 1,
                    CalibrationDueMilestoneCalculator::THREE_DAYS => 2,
                    CalibrationDueMilestoneCalculator::SEVEN_DAYS => 3,
                    CalibrationDueMilestoneCalculator::ONE_MONTH => 4,
                    CalibrationDueMilestoneCalculator::TWO_MONTHS => 5,
                    default => 99,
                },
                fn (CalibrationDueReminder $reminder): string => $reminder->instrumentName,
                fn (CalibrationDueReminder $reminder): int => $reminder->instrumentTypeId,
            ])
            ->values();
    }
}
