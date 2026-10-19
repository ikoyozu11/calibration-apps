<?php

namespace App\Standards;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class StandardMasterException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        string $message,
        public string $field = 'standard',
    ) {
        parent::__construct($message);
    }
}
