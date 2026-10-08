<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $calibration_scope_id
 * @property int $calibration_id
 * @property int $instrument_type_id
 * @property int|null $test_profile_id
 * @property int $scope_order
 * @property string|null $remarks
 * @property Carbon $created_at
 * @property-read Calibration $calibration
 * @property-read Collection<int, CalibrationTestResult> $calibrationTestResults
 * @property-read InstrumentType $instrumentType
 * @property-read TestProfile|null $testProfile
 */
class CalibrationScope extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'calibration_scope';

    protected $primaryKey = 'calibration_scope_id';

    /** @return BelongsTo<Calibration, $this> */
    public function calibration(): BelongsTo
    {
        return $this->belongsTo(Calibration::class, 'calibration_id', 'calibration_id');
    }

    /** @return BelongsTo<InstrumentType, $this> */
    public function instrumentType(): BelongsTo
    {
        return $this->belongsTo(InstrumentType::class, 'instrument_type_id', 'instrument_type_id');
    }

    /** @return BelongsTo<TestProfile, $this> */
    public function testProfile(): BelongsTo
    {
        return $this->belongsTo(TestProfile::class, 'test_profile_id', 'test_profile_id');
    }

    /** @return HasMany<CalibrationTestResult, $this> */
    public function calibrationTestResults(): HasMany
    {
        return $this->hasMany(CalibrationTestResult::class, 'calibration_scope_id', 'calibration_scope_id');
    }
}
