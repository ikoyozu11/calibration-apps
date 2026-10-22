<?php

namespace App\Activities;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class CalibrationActivityMasterException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        string $message,
        public string $field = 'activity',
    ) {
        parent::__construct($message);
    }
}
