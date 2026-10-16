<?php

namespace App\TestColumns;

interface TestTableVersionWriter
{
    public function fork(int $sourceVersionId): int;

    public function publish(int $profileId): int;
}
