<?php

namespace App\TestColumns;

readonly class ColumnDefinition
{
    /**
     * @param  list<string>  $formulaInputs
     */
    public function __construct(
        public int $profileId,
        public string $columnKey,
        public string $columnLabel,
        public int $columnOrder,
        public string $dataType,
        public string $valueSource,
        public ?string $unit,
        public ?int $displayScale,
        public ?string $formulaCode,
        public array $formulaInputs,
    ) {}
}
