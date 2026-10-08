<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $calibration_test_result_id
 * @property int $calibration_scope_id
 * @property int $test_point_id
 * @property string|null $result_status
 * @property string|null $remarks
 * @property Carbon $created_at
 * @property string|null $standard_value
 * @property string|null $average_value
 * @property string|null $correction_value
 * @property-read CalibrationScope $calibrationScope
 * @property-read TestPoint $testPoint
 * @property-read Collection<int, CalibrationReading> $calibrationReadings
 */
class CalibrationTestResult extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'calibration_test_result';

    protected $primaryKey = 'calibration_test_result_id';

    /** @return BelongsTo<CalibrationScope, $this> */
    public function calibrationScope(): BelongsTo
    {
        return $this->belongsTo(CalibrationScope::class, 'calibration_scope_id', 'calibration_scope_id');
    }

    /** @return BelongsTo<TestPoint, $this> */
    public function testPoint(): BelongsTo
    {
        return $this->belongsTo(TestPoint::class, 'test_point_id', 'test_point_id');
    }

    /** @return HasMany<CalibrationReading, $this> */
    public function calibrationReadings(): HasMany
    {
        return $this->hasMany(CalibrationReading::class, 'calibration_test_result_id', 'calibration_test_result_id');
    }
}
