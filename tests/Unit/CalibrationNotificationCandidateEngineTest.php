<?php

use App\CalibrationDueNotifications\CalibrationNotificationCandidateEngine;
use Carbon\CarbonImmutable;

function notificationCandidateSource(array $overrides = []): array
{
    return array_merge([
        'instrument_id' => 10,
        'instrument_type_id' => 20,
        'latest_calibration_id' => 30,
        'calibration_due' => '2026-11-04',
        'instrument_name' => 'Pressure Tester',
        'instrument_type' => 'Drop Tester',
        'serial_number' => 'SN-10',
    ], $overrides);
}

beforeEach(function (): void {
    $this->engine = new CalibrationNotificationCandidateEngine;
    $this->evaluationDate = CarbonImmutable::parse('2026-11-04', 'Asia/Jakarta');
});

it('creates an overdue candidate before the due date evaluation boundary', function () {
    $candidate = $this->engine->fromSource(
        notificationCandidateSource(['calibration_due' => '2026-11-03']),
        $this->evaluationDate,
    );

    expect($candidate?->eventType)->toBe(CalibrationNotificationCandidateEngine::EVENT_OVERDUE);
});

it('creates due soon candidates on today and the sixty-day boundary', function () {
    $today = $this->engine->fromSource(notificationCandidateSource(), $this->evaluationDate);
    $boundary = $this->engine->fromSource(
        notificationCandidateSource(['calibration_due' => '2027-01-03']),
        $this->evaluationDate,
    );

    expect($today?->eventType)->toBe(CalibrationNotificationCandidateEngine::EVENT_DUE_SOON)
        ->and($boundary?->eventType)->toBe(CalibrationNotificationCandidateEngine::EVENT_DUE_SOON);
});

it('does not create candidates beyond sixty days or without a due date', function () {
    expect($this->engine->fromSource(
        notificationCandidateSource(['calibration_due' => '2027-01-04']),
        $this->evaluationDate,
    ))->toBeNull()
        ->and($this->engine->fromSource(
            notificationCandidateSource(['calibration_due' => null]),
            $this->evaluationDate,
        ))->toBeNull();
});

it('produces a stable key for repeated evaluation', function () {
    $source = notificationCandidateSource(['calibration_due' => '2026-12-01']);

    $first = $this->engine->fromSource($source, $this->evaluationDate);
    $second = $this->engine->fromSource($source, $this->evaluationDate);

    expect($first?->eventKey)->toBe($second?->eventKey);
});

it('keeps instrument types and calibration cycles distinct', function () {
    $typeA = $this->engine->fromSource(notificationCandidateSource(), $this->evaluationDate);
    $typeB = $this->engine->fromSource(
        notificationCandidateSource(['instrument_type_id' => 21]),
        $this->evaluationDate,
    );
    $cycleB = $this->engine->fromSource(
        notificationCandidateSource(['latest_calibration_id' => 31]),
        $this->evaluationDate,
    );

    expect($typeA?->eventKey)->not->toBe($typeB?->eventKey)
        ->and($typeA?->eventKey)->not->toBe($cycleB?->eventKey);
});

it('changes the key when the due date changes and separates due soon from overdue', function () {
    $dueSoon = $this->engine->fromSource(
        notificationCandidateSource(['calibration_due' => '2026-11-10']),
        $this->evaluationDate,
    );
    $overdue = $this->engine->fromSource(
        notificationCandidateSource(['calibration_due' => '2026-11-03']),
        $this->evaluationDate,
    );

    expect($dueSoon?->eventKey)->not->toBe($overdue?->eventKey);
});

it('keeps the event key independent of display snapshots', function () {
    $original = $this->engine->fromSource(notificationCandidateSource(), $this->evaluationDate);
    $renamed = $this->engine->fromSource(
        notificationCandidateSource([
            'instrument_name' => 'Renamed Pressure Tester',
            'instrument_type' => 'Renamed Drop Tester',
        ]),
        $this->evaluationDate,
    );

    expect($original?->eventKey)->toBe($renamed?->eventKey)
        ->and($renamed?->instrumentNameSnapshot)->toBe('Renamed Pressure Tester');
});

it('fails safely for missing or inconsistent identifiers', function () {
    expect($this->engine->fromSource(notificationCandidateSource(['instrument_id' => 0]), $this->evaluationDate))->toBeNull()
        ->and($this->engine->fromSource(notificationCandidateSource(['instrument_type_id' => 'invalid']), $this->evaluationDate))->toBeNull()
        ->and($this->engine->fromSource(notificationCandidateSource(['latest_calibration_id' => null]), $this->evaluationDate))->toBeNull()
        ->and($this->engine->fromSource(notificationCandidateSource(['instrument_name' => '']), $this->evaluationDate))->toBeNull();
});

it('does not treat in-progress or cancelled rows without a completed source as candidates', function () {
    expect($this->engine->fromSource(notificationCandidateSource([
        'session_status' => 'in_progress',
        'latest_calibration_id' => null,
    ]), $this->evaluationDate))->toBeNull()
        ->and($this->engine->fromSource(notificationCandidateSource([
            'session_status' => 'cancelled',
            'latest_calibration_id' => null,
        ]), $this->evaluationDate))->toBeNull();
});

it('does not mutate the supplied source data', function () {
    $source = notificationCandidateSource(['calibration_due' => '2026-11-10']);
    $original = $source;

    $this->engine->fromSource($source, $this->evaluationDate);

    expect($source)->toBe($original);
});

it('keeps multiple pair candidates instead of collapsing them to instrument level', function () {
    $candidates = $this->engine->fromSources([
        notificationCandidateSource(['instrument_type_id' => 20]),
        notificationCandidateSource(['instrument_type_id' => 21]),
    ], $this->evaluationDate);

    expect($candidates)->toHaveCount(2)
        ->and($candidates->pluck('instrumentTypeId')->sort()->values()->all())->toBe([20, 21]);
});
