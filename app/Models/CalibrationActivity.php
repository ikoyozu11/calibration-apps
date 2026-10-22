<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $calibration_activity_id
 * @property int $calibration_id
 * @property string|null $activity_type
 * @property int|null $activity_id
 * @property string|null $activity_name
 * @property string|null $activity_description
 * @property string|null $activity_result
 * @property int $activity_order
 * @property Carbon $created_at
 * @property-read Calibration $calibration
 * @property-read CalibrationActivityMaster|null $activityMaster
 */
class CalibrationActivity extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'calibration_activity';

    protected $primaryKey = 'calibration_activity_id';

    /** @return BelongsTo<Calibration, $this> */
    public function calibration(): BelongsTo
    {
        return $this->belongsTo(Calibration::class, 'calibration_id', 'calibration_id');
    }

    /** @return BelongsTo<CalibrationActivityMaster, $this> */
    public function activityMaster(): BelongsTo
    {
        return $this->belongsTo(CalibrationActivityMaster::class, 'activity_id', 'activity_id');
    }
}
