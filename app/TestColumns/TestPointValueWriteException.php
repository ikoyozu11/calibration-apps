<?php

namespace App\TestColumns;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class TestPointValueWriteException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        string $message,
        public string $field = 'rows',
    ) {
        parent::__construct($message);
    }
}
