<?php

namespace App\TestColumns;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class TestColumnWriteException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        string $message,
        public string $field = 'columns',
    ) {
        parent::__construct($message);
    }
}
