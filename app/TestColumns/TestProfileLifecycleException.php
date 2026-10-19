<?php

namespace App\TestColumns;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class TestProfileLifecycleException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        string $message,
        public string $field = 'testTable',
    ) {
        parent::__construct($message);
    }
}
