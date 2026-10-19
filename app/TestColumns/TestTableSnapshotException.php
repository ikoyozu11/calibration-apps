<?php

namespace App\TestColumns;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class TestTableSnapshotException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        string $message,
        public string $field = 'testTable',
    ) {
        parent::__construct($message);
    }
}
