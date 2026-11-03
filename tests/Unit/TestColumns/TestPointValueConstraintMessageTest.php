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

it('maps delete failures to delete-specific messages', function () {
    expect(TestPointValueConstraintMessage::fromDeleteDatabaseMessage('violates unique constraint "uq_test_point_order"'))
        ->toBe('The remaining row order could not be normalized. Refresh the page and try again.')
        ->and(TestPointValueConstraintMessage::fromDeleteDatabaseMessage('unexpected delete failure'))
        ->toBe('The row could not be deleted. Check the draft row and try again.');
});
