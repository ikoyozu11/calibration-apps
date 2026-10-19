<?php

namespace App\TestColumns;

class TestProfileLifecycleConstraintMessage
{
    public static function fromDatabaseMessage(string $message): string
    {
        $known = [
            'this test table is used by a calibration and cannot be deleted' => 'This test table is used by a calibration and cannot be deleted.',
            'fk_calibration_scope_test_profile' => 'This test table is used by a calibration and cannot be deleted.',
            'test profile' => 'This test table could not be found.',
            'fk_test_profile_version_profile' => 'This test table could not be found.',
        ];

        uksort($known, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($known as $needle => $text) {
            if (str_contains(mb_strtolower($message), $needle)) {
                return $text;
            }
        }

        return 'The test table could not be updated. Refresh the page and try again.';
    }
}
