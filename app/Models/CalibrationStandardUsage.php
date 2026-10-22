<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $calibration_standard_usage_id
 * @property int $calibration_id
 * @property int|null $standard_instrument_id
 * @property int|null $standard_id
 * @property int $usage_order
 * @property string|null $usage_purpose
 * @property string|null $remarks
 * @property Carbon $created_at
 * @property string|null $standard_range
 * @property string|null $standard_unit
 * @property string|null $snapshot_standard_code
 * @property string|null $snapshot_instrument_name
 * @property string|null $snapshot_type_specification
 * @property string|null $snapshot_serial_number
 * @property-read Calibration $calibration
 * @property-read Instrument|null $instrument
 * @property-read StandardMaster|null $standardMaster
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

    /** @return BelongsTo<StandardMaster, $this> */
    public function standardMaster(): BelongsTo
    {
        return $this->belongsTo(StandardMaster::class, 'standard_id', 'standard_id');
    }
}
