<?php

namespace App\Standards;

class InstrumentTypeStandardAssignmentConstraintMessage
{
    public static function fromDatabaseMessage(string $message): string
    {
        $known = [
            'an instrument cannot be assigned as its own standard' => 'This instrument cannot be assigned as its own standard.',
            'chk_instrument_type_standard_assignment_not_self' => 'This instrument cannot be assigned as its own standard.',
            'uq_instrument_type_standard_assignment_order' => 'The standard order could not be updated. Refresh the page and try again.',
            'uq_instrument_type_standard_assignment_master' => 'This standard is already assigned.',
            'uq_instrument_type_standard_assignment' => 'This standard is already assigned.',
            'fk_instrument_type_standard_assignment_master' => 'The selected standard could not be found.',
            'fk_instrument_type_standard_assignment_instrument' => 'The selected standard could not be found.',
            'fk_instrument_type_standard_assignment_type' => 'This instrument type could not be found.',
            'chk_instrument_type_standard_assignment_order' => 'The standard order could not be updated. Refresh the page and try again.',
            'unique constraint failed: instrument_type_standard_assignment.instrument_type_id, instrument_type_standard_assignment.standard_id' => 'This standard is already assigned.',
            'unique constraint failed: instrument_type_standard_assignment.instrument_type_id, instrument_type_standard_assignment.standard_instrument_id' => 'This standard is already assigned.',
            'unique constraint failed: instrument_type_standard_assignment.instrument_type_id, instrument_type_standard_assignment.assignment_order' => 'The standard order could not be updated. Refresh the page and try again.',
        ];

        uksort($known, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($known as $needle => $text) {
            if (str_contains(mb_strtolower($message), mb_strtolower($needle))) {
                return $text;
            }
        }

        return 'The standard assignment could not be updated. Refresh the page and try again.';
    }
}
