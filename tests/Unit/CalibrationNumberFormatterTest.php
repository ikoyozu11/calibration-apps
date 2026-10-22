<?php

use App\CalibrationSessions\CalibrationNumberFormatter;
use InvalidArgumentException;

it('formats a four-digit global sequence with asia jakarta month and year', function () {
    $instant = new DateTimeImmutable('2026-10-20 17:00:00', new DateTimeZone('UTC'));

    expect(CalibrationNumberFormatter::format(1, $instant))->toBe('CAL.VT0001/10/26');
});

it('keeps using the same sprintf pattern above 9999', function () {
    $instant = new DateTimeImmutable('2026-01-15 00:00:00', new DateTimeZone('Asia/Jakarta'));

    expect(CalibrationNumberFormatter::format(10000, $instant))->toBe('CAL.VT10000/01/26');
});

it('parses matching numbers and ignores malformed or legacy values', function () {
    expect(CalibrationNumberFormatter::parseSequence('CAL.VT0007/10/26'))->toBe(7)
        ->and(CalibrationNumberFormatter::parseSequence('CAL-STATUS-completed'))->toBeNull()
        ->and(CalibrationNumberFormatter::parseSequence('CAL.VT12/10/26'))->toBeNull()
        ->and(CalibrationNumberFormatter::parseSequence(null))->toBeNull();
});

it('starts the allocator above the highest matching sequence', function () {
    expect(CalibrationNumberFormatter::nextSequenceStart([
        'legacy-1',
        'CAL.VT0009/03/25',
        'CAL.VT0003/12/24',
        'not-a-number',
    ]))->toBe(10);
});

it('starts at one when no matching numbers exist', function () {
    expect(CalibrationNumberFormatter::nextSequenceStart([]))->toBe(1);
});

it('rejects matching numbers that overflow bigint', function () {
    expect(fn () => CalibrationNumberFormatter::nextSequenceStart([
        'CAL.VT9223372036854775808/10/26',
    ]))->toThrow(InvalidArgumentException::class);
});
