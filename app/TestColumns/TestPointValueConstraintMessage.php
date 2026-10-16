<?php

namespace App\TestColumns;

class TestPointValueConstraintMessage
{
    public static function fromDatabaseMessage(string $message): string
    {
        $known = [
            'test_point_value_template_only' => 'Template values can only be stored on a template column.',
            'test_point_value_numeric_type' => 'Enter a number for this column.',
            'test_point_value_duration_type' => 'Enter a duration as whole seconds.',
            'test_point_value_text_type' => 'Enter text for this column.',
            'ck_test_point_value_text' => 'Enter text for this column, or leave it blank.',
            'ck_test_point_value_one_kind' => 'A cell cannot store both a number and text.',
            'uq_test_point_order' => 'The row order conflicts with another row. Refresh the page and try again.',
            'uq_test_point_value_cell' => 'This cell could not be saved. Refresh the page and try again.',
            'fk_test_point_value_point' => 'This row could not be found.',
            'fk_test_point_value_column' => 'This column could not be found.',
            'fk_calibration_test_result_test_point' => 'This row is used by a calibration result and cannot be deleted.',
            'test profile' => 'This test table could not be found.',
        ];

        uksort($known, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($known as $needle => $text) {
            if (str_contains($message, $needle)) {
                return $text;
            }
        }

        return 'The row could not be saved. Check the values and try again.';
    }
}
