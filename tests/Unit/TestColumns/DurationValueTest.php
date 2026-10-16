<?php

use App\TestColumns\DurationValue;

it('parses hh:mm:ss and the unambiguous dotted legacy form as whole seconds', function () {
    expect(DurationValue::parse('00:03:00'))->toBe(180)
        ->and(DurationValue::parse('0.03.00'))->toBe(180)
        ->and(DurationValue::parse('+00:00:01'))->toBe(1)
        ->and(DurationValue::parse('-00:00:01'))->toBe(-1);
});

it('rejects invalid minutes, seconds, and ambiguous duration text', function () {
    expect(DurationValue::tryParse('00:60:00'))->toBeNull()
        ->and(DurationValue::tryParse('00:00:60'))->toBeNull()
        ->and(DurationValue::tryParse('3 minutes'))->toBeNull()
        ->and(fn () => DurationValue::parse('00:03:99'))->toThrow(InvalidArgumentException::class);
});

it('formats whole seconds as hh:mm:ss and signed correction values', function () {
    expect(DurationValue::format(180))->toBe('00:03:00')
        ->and(DurationValue::format(181))->toBe('00:03:01')
        ->and(DurationValue::format(1, alwaysSigned: true))->toBe('+00:00:01')
        ->and(DurationValue::format(-1))->toBe('-00:00:01');
});
