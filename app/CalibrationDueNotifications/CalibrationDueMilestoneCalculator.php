<?php

namespace App\CalibrationDueNotifications;

use Carbon\CarbonImmutable;

final class CalibrationDueMilestoneCalculator
{
    public const TIMEZONE = 'Asia/Jakarta';

    public const DUE_TODAY = 'due_today';

    public const THREE_DAYS = 'three_days_before';

    public const SEVEN_DAYS = 'seven_days_before';

    public const ONE_MONTH = 'one_month_before';

    public const TWO_MONTHS = 'two_months_before';

    /**
     * @return array<string, CarbonImmutable>
     */
    public function milestoneDates(CarbonImmutable $due): array
    {
        $due = $due->setTimezone(self::TIMEZONE)->startOfDay();

        return [
            self::TWO_MONTHS => $due->subMonthsNoOverflow(2),
            self::ONE_MONTH => $due->subMonthNoOverflow(),
            self::SEVEN_DAYS => $due->subDays(7),
            self::THREE_DAYS => $due->subDays(3),
            self::DUE_TODAY => $due,
        ];
    }

    /**
     * @return list<array{code: string, date: CarbonImmutable}>
     */
    public function eligibleMilestones(
        CarbonImmutable $due,
        CarbonImmutable $now,
        int $catchUpDays = 3,
    ): array {
        $today = $now->setTimezone(self::TIMEZONE)->startOfDay();
        $firstEligibleDate = $today->subDays(max(0, $catchUpDays));

        return collect($this->milestoneDates($due))
            ->map(fn (CarbonImmutable $date, string $code): array => [
                'code' => $code,
                'date' => $date,
            ])
            ->filter(fn (array $milestone): bool => $milestone['date']->betweenIncluded($firstEligibleDate, $today))
            ->sortBy(fn (array $milestone): int => $this->priority($milestone['code']))
            ->values()
            ->all();
    }

    private function priority(string $code): int
    {
        return match ($code) {
            self::DUE_TODAY => 1,
            self::THREE_DAYS => 2,
            self::SEVEN_DAYS => 3,
            self::ONE_MONTH => 4,
            self::TWO_MONTHS => 5,
            default => 99,
        };
    }
}
