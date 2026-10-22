<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Canonical Calibration Environment value. Temperature and Relative Humidity
 * are projected into calibration_environment by PostgreSQL triggers. Do not
 * write legacy Temperature/RH columns for canonical sessions.
 *
 * @property int $calibration_environment_value_id
 * @property int $calibration_id
 * @property int $parameter_id
 * @property string|null $numeric_value
 * @property string|null $text_value
 * @property Carbon $recorded_at
 * @property-read Calibration $calibration
 * @property-read EnvironmentParameter $parameter
 * @property-read CalibrationEnvironmentParameterSnapshot|null $parameterSnapshot
 */
class CalibrationEnvironmentValue extends Model
{
    public $timestamps = false;

    protected $table = 'calibration_environment_value';

    protected $primaryKey = 'calibration_environment_value_id';

    /** @return BelongsTo<Calibration, $this> */
    public function calibration(): BelongsTo
    {
        return $this->belongsTo(Calibration::class, 'calibration_id', 'calibration_id');
    }

    /** @return BelongsTo<EnvironmentParameter, $this> */
    public function parameter(): BelongsTo
    {
        return $this->belongsTo(EnvironmentParameter::class, 'parameter_id', 'parameter_id');
    }

    /**
     * Composite FK (calibration_id, parameter_id) onto the frozen snapshot.
     * Lazy-load only; do not eager-load this relation.
     *
     * @return BelongsTo<CalibrationEnvironmentParameterSnapshot, $this>
     */
    public function parameterSnapshot(): BelongsTo
    {
        return $this->belongsTo(CalibrationEnvironmentParameterSnapshot::class, 'parameter_id', 'parameter_id')
            ->where('calibration_environment_parameter_snapshot.calibration_id', $this->calibration_id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
        ];
    }
}
