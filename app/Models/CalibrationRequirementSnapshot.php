<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Frozen requirement mode for one Calibration Session category.
 *
 * resolved_by_user_id and configuration_confirmed_by_user_id reference
 * app_user.app_user_id. There is no AppUser Eloquent model yet.
 *
 * @property int $calibration_requirement_snapshot_id
 * @property int $calibration_id
 * @property string $requirement_category
 * @property string $frozen_mode
 * @property string|null $resolved_mode
 * @property Carbon|null $resolved_at
 * @property string|null $resolved_reason
 * @property int|null $resolved_by_user_id
 * @property Carbon|null $configuration_confirmed_at
 * @property string|null $configuration_confirmed_reason
 * @property int|null $configuration_confirmed_by_user_id
 * @property-read Calibration $calibration
 */
class CalibrationRequirementSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'calibration_requirement_snapshot';

    protected $primaryKey = 'calibration_requirement_snapshot_id';

    /** @return BelongsTo<Calibration, $this> */
    public function calibration(): BelongsTo
    {
        return $this->belongsTo(Calibration::class, 'calibration_id', 'calibration_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'configuration_confirmed_at' => 'datetime',
        ];
    }
}
