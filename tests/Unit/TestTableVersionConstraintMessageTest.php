<?php

use App\TestColumns\TestTableVersionConstraintMessage;

it('maps postgresql version errors to a readable message', function () {
    expect(TestTableVersionConstraintMessage::fromDatabaseMessage('a draft version already exists for this test table'))
        ->toBe('A draft version already exists for this test table.')
        ->and(TestTableVersionConstraintMessage::fromDatabaseMessage('fork a published version, not a draft'))
        ->toBe('Fork the active version, not a draft.')
        ->and(TestTableVersionConstraintMessage::fromDatabaseMessage('test table has no draft version to publish'))
        ->toBe('This test table has no draft version to publish.')
        ->and(TestTableVersionConstraintMessage::fromDatabaseMessage('published test table versions cannot be edited'))
        ->toBe('This published test table is read-only.')
        ->and(TestTableVersionConstraintMessage::fromDatabaseMessage('test profile version 9 does not exist'))
        ->toBe('This test table version could not be found.')
        ->and(TestTableVersionConstraintMessage::fromDatabaseMessage('something unexpected'))
        ->toBe('The test table version could not be updated. Refresh the page and try again.');
});
