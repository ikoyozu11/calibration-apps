<?php

use App\TestColumns\TestPointValueConstraintMessage;

it('maps postgresql template value errors to a readable message', function () {
    expect(TestPointValueConstraintMessage::fromDatabaseMessage('violates check constraint "test_point_value_template_only"'))
        ->toBe('Template values can only be stored on a template column.')
        ->and(TestPointValueConstraintMessage::fromDatabaseMessage('insert or update on table "test_point" violates foreign key constraint "fk_calibration_test_result_test_point"'))
        ->toBe('This row is used by a calibration result and cannot be deleted.')
        ->and(TestPointValueConstraintMessage::fromDatabaseMessage('something unexpected'))
        ->toBe('The row could not be saved. Check the values and try again.');
});
