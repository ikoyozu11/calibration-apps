<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Frozen environment parameter definition for one Calibration Session.
 *
 * Composite primary key: calibration_id + parameter_id.
 *
 * @property int $calibration_id
 * @property int $parameter_id
 * @property string $parameter_code
 * @property string $parameter_name
 * @property string $unit
 * @property string $data_type
 * @property string $requirement_mode
 * @property int $display_order
 * @property Carbon $included_at
 * @property bool $inclusion_confirmed
 * @property string|null $inclusion_reason
 * @property-read Calibration $calibration
 * @property-read EnvironmentParameter $parameter
 * @property-read CalibrationEnvironmentValue|null $value
 */
class CalibrationEnvironmentParameterSnapshot extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'calibration_environment_parameter_snapshot';

    protected $primaryKey = 'calibration_id';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'inclusion_confirmed' => true,
    ];

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
     * Composite FK (calibration_id, parameter_id). Lazy-load only; Eloquent
     * cannot eager-load the parent calibration_id through whereColumn.
     *
     * @return HasOne<CalibrationEnvironmentValue, $this>
     */
    public function value(): HasOne
    {
        return $this->hasOne(CalibrationEnvironmentValue::class, 'parameter_id', 'parameter_id')
            ->where('calibration_environment_value.calibration_id', $this->calibration_id);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query
            ->where($this->qualifyColumn('calibration_id'), $this->getAttribute('calibration_id'))
            ->where($this->qualifyColumn('parameter_id'), $this->getAttribute('parameter_id'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'included_at' => 'datetime',
            'inclusion_confirmed' => 'boolean',
        ];
    }
}
