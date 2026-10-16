<?php

namespace App\TestColumns;

class TestColumnConstraintMessage
{
    public static function fromDatabaseMessage(string $message): string
    {
        $known = [
            'uq_test_column_key' => 'A column with this key already exists on this test table.',
            'uq_test_column_order' => 'The column order conflicts with another column. Refresh the page and try again.',
            'ck_test_column_key' => 'The column key is not valid.',
            'ck_test_column_label' => 'Enter a column label.',
            'ck_test_column_order' => 'Column order must start at 1.',
            'ck_test_column_data_type' => 'Choose numeric, text, or duration.',
            'ck_test_column_value_source' => 'Choose template, engineer, or calculated.',
            'ck_test_column_scale' => 'Decimal precision must be a whole number from 0 to 6 for a numeric column.',
            'ck_test_column_formula' => 'A calculated column needs a formula that matches its data type.',
            'ck_test_column_formula_inputs' => 'Formula inputs must be different columns and cannot include this column.',
            'test_column_formula_reference' => 'Choose formula columns that already exist on this test table.',
            'every formula input must already exist' => 'Choose formula columns that already exist on this test table.',
            'test_column_formula_input_type' => 'Formula columns must be template or engineer columns of the same type on this test table.',
            'must be a matching template or engineer column' => 'Formula columns must be template or engineer columns of the same type on this test table.',
            'must be a numeric template or engineer column' => 'Formula columns must be template or engineer columns of the same type on this test table.',
            'test_column_cell_conflict' => 'This column already has template values, so its type or source cannot change.',
            'stored cell does not match' => 'This column already has template values, so its type or source cannot change.',
            'test_column_immutable' => 'The column key cannot be changed.',
            'column_key and test_profile_id cannot be changed' => 'The column key cannot be changed.',
            'test_column_reorder' => 'The new order must include every column of this test table once.',
            'column reorder must list every column' => 'The new order must include every column of this test table once.',
            'fk_test_column_test_profile' => 'This test table could not be found.',
            'test profile' => 'This test table could not be found.',
            'test column' => 'This column could not be found.',
        ];

        uksort($known, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($known as $needle => $text) {
            if (str_contains($message, $needle)) {
                return $text;
            }
        }

        return 'The column could not be saved. Check the column settings and try again.';
    }
}
