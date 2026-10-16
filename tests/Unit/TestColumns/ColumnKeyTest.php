<?php

use App\TestColumns\ColumnKey;

it('builds a permanent key from the column label', function () {
    expect(ColumnKey::fromLabel('Set Point', []))->toBe('set_point')
        ->and(ColumnKey::fromLabel('1st Reading', []))->toBe('col_1st_reading')
        ->and(ColumnKey::fromLabel('+Tol', []))->toBe('tol')
        ->and(ColumnKey::fromLabel('Set Point', ['set_point']))->toBe('set_point_2');
});
