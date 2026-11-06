<?php

use App\CalibrationDueNotifications\CalibrationDueMilestoneCalculator;
use Carbon\CarbonImmutable;

it('calculates all approved milestones without mutating the due date', function () {
    $calculator = new CalibrationDueMilestoneCalculator;
    $due = CarbonImmutable::parse('2027-03-31', 'Asia/Jakarta');

    $milestones = $calculator->milestoneDates($due);

    expect($milestones[CalibrationDueMilestoneCalculator::TWO_MONTHS]->toDateString())->toBe('2027-01-31')
        ->and($milestones[CalibrationDueMilestoneCalculator::ONE_MONTH]->toDateString())->toBe('2027-02-28')
        ->and($milestones[CalibrationDueMilestoneCalculator::SEVEN_DAYS]->toDateString())->toBe('2027-03-24')
        ->and($milestones[CalibrationDueMilestoneCalculator::THREE_DAYS]->toDateString())->toBe('2027-03-28')
        ->and($milestones[CalibrationDueMilestoneCalculator::DUE_TODAY]->toDateString())->toBe('2027-03-31')
        ->and($due->toDateString())->toBe('2027-03-31');
});

it('handles leap-year month subtraction without overflow', function () {
    $calculator = new CalibrationDueMilestoneCalculator;

    expect($calculator->milestoneDates(CarbonImmutable::parse('2024-02-29', 'Asia/Jakarta'))[
        CalibrationDueMilestoneCalculator::ONE_MONTH
    ]->toDateString())->toBe('2024-01-29');
});

it('selects today and bounded catch-up dates only', function () {
    $calculator = new CalibrationDueMilestoneCalculator;
    $due = CarbonImmutable::parse('2027-03-31', 'Asia/Jakarta');
    $now = CarbonImmutable::parse('2027-03-31 08:00:00', 'Asia/Jakarta');

    $eligible = $calculator->eligibleMilestones($due, $now, 3);

    expect($eligible)->toHaveCount(2)
        ->and($eligible[0]['code'])->toBe(CalibrationDueMilestoneCalculator::DUE_TODAY)
        ->and($eligible[1]['code'])->toBe(CalibrationDueMilestoneCalculator::THREE_DAYS);
});

it('keeps distinct milestones when they share a calendar date', function () {
    $calculator = new CalibrationDueMilestoneCalculator;
    $due = CarbonImmutable::parse('2027-03-31', 'Asia/Jakarta');
    $now = CarbonImmutable::parse('2027-03-31', 'Asia/Jakarta');

    $milestones = $calculator->eligibleMilestones($due, $now, 30);

    expect(collect($milestones)->pluck('code')->all())
        ->toContain(CalibrationDueMilestoneCalculator::DUE_TODAY)
        ->toContain(CalibrationDueMilestoneCalculator::THREE_DAYS);
});
