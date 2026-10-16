<?php

use App\TestColumns\TestColumnConstraintMessage;

it('maps postgresql column errors to a readable message', function () {
    expect(TestColumnConstraintMessage::fromDatabaseMessage('violates check constraint "ck_test_column_formula_inputs"'))
        ->toBe('Formula inputs must be different columns and cannot include this column.')
        ->and(TestColumnConstraintMessage::fromDatabaseMessage('every formula input must already exist on the same profile'))
        ->toBe('Choose formula columns that already exist on this test table.')
        ->and(TestColumnConstraintMessage::fromDatabaseMessage('stored cell does not match the column source or data type'))
        ->toBe('This column already has template values, so its type or source cannot change.')
        ->and(TestColumnConstraintMessage::fromDatabaseMessage('something unexpected'))
        ->toBe('The column could not be saved. Check the column settings and try again.');
});
