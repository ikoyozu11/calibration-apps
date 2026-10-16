<?php

use App\TestColumns\ColumnFormula;
use App\TestColumns\DurationValue;

it('subtracts duration seconds for both correction formulas', function () {
    $standard = DurationValue::parse('00:03:00');
    $reading = DurationValue::parse('00:03:01');

    expect(ColumnFormula::durationDifference(ColumnFormula::StandardMinusReading, [$standard, $reading]))->toBe(-1)
        ->and(DurationValue::format(-1, alwaysSigned: true))->toBe('-00:00:01')
        ->and(ColumnFormula::durationDifference(ColumnFormula::ReadingMinusStandard, [$reading, $standard]))->toBe(1)
        ->and(DurationValue::format(1, alwaysSigned: true))->toBe('+00:00:01');
});

it('keeps deviation as first minus second for numeric and duration values', function () {
    expect(ColumnFormula::numericResult(ColumnFormula::Deviation, ['10.5', '8']))->toBe('2.500000000000')
        ->and(ColumnFormula::durationDifference(ColumnFormula::Deviation, [180, 181]))->toBe(-1);
});

it('averages numeric values with decimal arithmetic', function () {
    expect(ColumnFormula::numericResult(ColumnFormula::Average, ['8.000', '8.002']))->toBe('8.001000000000');
});
