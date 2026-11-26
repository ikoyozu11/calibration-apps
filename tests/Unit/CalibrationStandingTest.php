<?php

use App\Dashboard\CalibrationStanding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-11-04 09:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('supports mutable carbon without mutating the input date', function () {
    $due = Carbon::parse('2026-11-04 14:30:00');
    $original = $due->toIso8601String();

    expect(CalibrationStanding::fromDueDate($due))->toBe(CalibrationStanding::DueSoon)
        ->and($due->toIso8601String())->toBe($original);
});

it('supports carbon immutable dates', function () {
    $due = CarbonImmutable::parse('2026-11-04 14:30:00');

    expect(CalibrationStanding::fromDueDate($due))->toBe(CalibrationStanding::DueSoon);
});

it('returns not calibrated for a null due date', function () {
    expect(CalibrationStanding::fromDueDate(null))->toBe(CalibrationStanding::NotCalibrated);
});

it('returns overdue for a past due date', function () {
    expect(CalibrationStanding::fromDueDate(CarbonImmutable::parse('2026-11-03')))
        ->toBe(CalibrationStanding::Overdue);
});

it('returns on track when the due date is more than sixty days away', function () {
    expect(CalibrationStanding::fromDueDate(CarbonImmutable::parse('2027-02-01')))
        ->toBe(CalibrationStanding::OnTrack);
});

it('treats a due date equal to today as due soon', function () {
    expect(CalibrationStanding::fromDueDate(CarbonImmutable::parse('2026-11-04')))
        ->toBe(CalibrationStanding::DueSoon);
});

it('supports an explicit evaluation date without changing the shared default rule', function () {
    expect(CalibrationStanding::fromDueDate(
        CarbonImmutable::parse('2027-01-03'),
        CarbonImmutable::parse('2026-11-04'),
    ))->toBe(CalibrationStanding::DueSoon);
});
