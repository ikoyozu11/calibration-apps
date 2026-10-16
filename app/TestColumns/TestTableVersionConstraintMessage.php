<?php

namespace App\TestColumns;

class TestTableVersionConstraintMessage
{
    public static function fromDatabaseMessage(string $message): string
    {
        $known = [
            'a draft version already exists for this test table' => 'A draft version already exists for this test table.',
            'uq_test_profile_version_draft' => 'A draft version already exists for this test table.',
            'fork a published version, not a draft' => 'Fork the active version, not a draft.',
            'test table has no draft version to publish' => 'This test table has no draft version to publish.',
            'uq_test_profile_version_active' => 'This test table already has an active version.',
            'published test table versions cannot be edited' => 'This published test table is read-only.',
            'test_profile_version_immutable' => 'This published test table is read-only.',
            'test_profile_version_status' => 'This test table version cannot change status that way.',
            'test profile version' => 'This test table version could not be found.',
            'fk_test_column_version_profile' => 'This test table version could not be found.',
            'fk_test_profile_version_profile' => 'This test table could not be found.',
            'test profile' => 'This test table could not be found.',
        ];

        uksort($known, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($known as $needle => $text) {
            if (str_contains($message, $needle)) {
                return $text;
            }
        }

        return 'The test table version could not be updated. Refresh the page and try again.';
    }
}
