<?php

use App\Dashboard\CalibrationAnnualOverview;
use App\Dashboard\CalibrationAnnualStatus;

it('defaults the selected year to the current year with a 2026 floor', function () {
    expect(CalibrationAnnualOverview::defaultYear(2025))->toBe(2026)
        ->and(CalibrationAnnualOverview::defaultYear(2026))->toBe(2026)
        ->and(CalibrationAnnualOverview::defaultYear(2027))->toBe(2027);
});

it('builds year options from 2026 through the later of current year and data', function () {
    expect(CalibrationAnnualOverview::yearOptions(2026))->toBe([2026])
        ->and(CalibrationAnnualOverview::yearOptions(2027, 2026))->toBe([2026, 2027])
        ->and(CalibrationAnnualOverview::yearOptions(2026, 2028))->toBe([2026, 2027, 2028]);
});

it('rejects years outside the supported options', function () {
    $allowed = CalibrationAnnualOverview::yearOptions(2026);

    expect(CalibrationAnnualOverview::sanitizeYear(2019, $allowed, 2026))->toBe(2026)
        ->and(CalibrationAnnualOverview::sanitizeYear(2028, $allowed, 2026))->toBe(2026)
        ->and(CalibrationAnnualOverview::sanitizeYear(2026, $allowed, 2026))->toBe(2026);
});

it('accepts only the approved calibrations tabs', function () {
    expect(CalibrationAnnualOverview::sanitizeTab('overview'))->toBe('overview')
        ->and(CalibrationAnnualOverview::sanitizeTab('history'))->toBe('history')
        ->and(CalibrationAnnualOverview::sanitizeTab('unknown'))->toBe('overview');
});

it('treats a missing session as not calibrated', function () {
    expect(CalibrationAnnualOverview::status(null))->toBe(CalibrationAnnualStatus::NotCalibrated)
        ->and(CalibrationAnnualOverview::progress(null, null))->toBe(CalibrationAnnualStatus::NotCalibrated)
        ->and(CalibrationAnnualOverview::yearActive(null, 2026))->toBeNull();
});

it('counts each type once and keeps the lifecycle card invariant', function () {
    $totals = CalibrationAnnualOverview::composeTypeTotals(4, [2, 2], [1, 2, 3]);

    expect($totals)->toBe([
        'types' => 4,
        'completed' => 2,
        'inProgress' => 1,
        'notCalibrated' => 1,
    ])->and($totals['types'])->toBe($totals['completed'] + $totals['inProgress'] + $totals['notCalibrated']);
});

it('does not treat a cross-year in-progress session as the selected year', function () {
    expect(CalibrationAnnualOverview::crossYearActiveYear(null, 2026))->toBeNull();
});
