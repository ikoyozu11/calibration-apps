<?php

namespace App\TestColumns;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class CalibrationScopePinException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        string $message,
        public string $field = 'calibration',
    ) {
        parent::__construct($message);
    }
}
