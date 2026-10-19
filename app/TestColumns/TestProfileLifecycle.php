<?php

namespace App\TestColumns;

interface TestProfileLifecycle
{
    public function isUsedInCalibration(int $profileId): bool;

    public function deleteUnused(int $profileId): void;

    public function archive(int $profileId): void;

    public function restore(int $profileId): void;
}
