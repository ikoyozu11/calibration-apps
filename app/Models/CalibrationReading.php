<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $calibration_reading_id
 * @property int $calibration_test_result_id
 * @property int $reading_order
 * @property string|null $reference_value
 * @property string|null $instrument_value
 * @property string|null $error_value
 * @property string|null $uncertainty_value
 * @property string|null $unit
 * @property Carbon $created_at
 * @property-read CalibrationTestResult $calibrationTestResult
 */
class CalibrationReading extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'calibration_reading';

    protected $primaryKey = 'calibration_reading_id';

    /** @return BelongsTo<CalibrationTestResult, $this> */
    public function calibrationTestResult(): BelongsTo
    {
        return $this->belongsTo(CalibrationTestResult::class, 'calibration_test_result_id', 'calibration_test_result_id');
    }
}
