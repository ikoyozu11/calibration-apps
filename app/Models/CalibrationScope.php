<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
}
