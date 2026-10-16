<?php

namespace App\TestColumns;

interface TestPointValueWriter
{
    public function upsert(
        int $profileId,
        int $pointId,
        int $columnId,
        ?string $numericValue,
        ?string $textValue,
        ?string $unit = null,
    ): void;

    public function delete(int $profileId, int $pointId, int $columnId): void;
}
