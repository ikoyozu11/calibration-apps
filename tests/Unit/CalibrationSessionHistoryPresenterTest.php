<?php

use App\Dashboard\CalibrationAnnualOverview;
use App\Dashboard\CalibrationSessionHistory;
use App\Models\Calibration;

it('normalizes invalid session status values to all', function () {
    expect(CalibrationSessionHistory::sanitizeStatus('completed'))->toBe(Calibration::STATUS_COMPLETED)
        ->and(CalibrationSessionHistory::sanitizeStatus('in_progress'))->toBe(Calibration::STATUS_IN_PROGRESS)
        ->and(CalibrationSessionHistory::sanitizeStatus('cancelled'))->toBe(Calibration::STATUS_CANCELLED)
        ->and(CalibrationSessionHistory::sanitizeStatus('all'))->toBe(CalibrationSessionHistory::STATUS_ALL)
        ->and(CalibrationSessionHistory::sanitizeStatus('unknown'))->toBe(CalibrationSessionHistory::STATUS_ALL)
        ->and(CalibrationSessionHistory::sanitizeStatus(null))->toBe(CalibrationSessionHistory::STATUS_ALL);
});

it('accepts only positive identity identifiers', function () {
    expect(CalibrationSessionHistory::sanitizeIdentityId(3))->toBe(3)
        ->and(CalibrationSessionHistory::sanitizeIdentityId(0))->toBeNull()
        ->and(CalibrationSessionHistory::sanitizeIdentityId(-1))->toBeNull();
});

it('labels persisted session statuses without annual progress terms', function () {
    expect(CalibrationSessionHistory::statusLabel(Calibration::STATUS_IN_PROGRESS))->toBe('In Progress')
        ->and(CalibrationSessionHistory::statusLabel(Calibration::STATUS_COMPLETED))->toBe('Completed')
        ->and(CalibrationSessionHistory::statusLabel(Calibration::STATUS_CANCELLED))->toBe('Cancelled')
        ->and(CalibrationSessionHistory::statusLabel('other'))->toBe('—');
});

it('reuses the 2026 year floor for session history options', function () {
    expect(CalibrationAnnualOverview::yearOptions(2026, 2028))->toBe([2026, 2027, 2028]);
});
