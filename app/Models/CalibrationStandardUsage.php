<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $calibration_standard_usage_id
 * @property int $calibration_id
 * @property int $standard_instrument_id
 * @property int $usage_order
 * @property string|null $usage_purpose
 * @property string|null $remarks
 * @property Carbon $created_at
 * @property string|null $standard_range
 * @property string|null $standard_unit
 * @property-read Calibration $calibration
 * @property-read Instrument $instrument
 */
class CalibrationStandardUsage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'calibration_standard_usage';

    protected $primaryKey = 'calibration_standard_usage_id';

    /** @return BelongsTo<Calibration, $this> */
    public function calibration(): BelongsTo
    {
        return $this->belongsTo(Calibration::class, 'calibration_id', 'calibration_id');
    }

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'standard_instrument_id', 'instrument_id');
    }
}
