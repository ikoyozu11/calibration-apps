<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $calibration_environment_id
 * @property int $calibration_id
 * @property string|null $temperature_value
 * @property string|null $temperature_unit
 * @property string|null $humidity_value
 * @property string|null $humidity_unit
 * @property string|null $pressure_value
 * @property string|null $pressure_unit
 * @property string|null $remarks
 * @property Carbon $created_at
 * @property-read Calibration $calibration
 */
class CalibrationEnvironment extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'calibration_environment';

    protected $primaryKey = 'calibration_environment_id';

    /** @return BelongsTo<Calibration, $this> */
    public function calibration(): BelongsTo
    {
        return $this->belongsTo(Calibration::class, 'calibration_id', 'calibration_id');
    }
}
