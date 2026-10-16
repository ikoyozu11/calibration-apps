<?php

use App\TestColumns\NumericDisplay;

it('pads trailing zeros to the configured decimal precision', function () {
    expect(NumericDisplay::format('99', 1))->toBe('99.0')
        ->and(NumericDisplay::format('8', 3))->toBe('8.000')
        ->and(NumericDisplay::format('56.9521', 4))->toBe('56.9521');
});

it('does not round a stored value that already has more decimal places', function () {
    expect(NumericDisplay::format('56.9521', 2))->toBe('56.9521')
        ->and(NumericDisplay::format('10.5', null))->toBe('10.5');
});
