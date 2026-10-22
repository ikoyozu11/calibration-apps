<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Extensible Calibration Environment parameter master.
 *
 * @property int $parameter_id
 * @property string $parameter_code
 * @property string $parameter_name
 * @property string $unit
 * @property string $data_type
 * @property bool $is_system
 * @property Carbon $created_at
 * @property-read Collection<int, InstrumentTypeEnvironmentParameter> $instrumentTypeConfigurations
 * @property-read Collection<int, CalibrationEnvironmentParameterSnapshot> $calibrationSnapshots
 * @property-read Collection<int, CalibrationEnvironmentValue> $calibrationValues
 */
class EnvironmentParameter extends Model
{
    public const UPDATED_AT = null;

    public const CODE_TEMPERATURE = 'temperature';

    public const CODE_RELATIVE_HUMIDITY = 'relative_humidity';

    public const DATA_TYPE_NUMERIC = 'numeric';

    public const DATA_TYPE_TEXT = 'text';

    protected $table = 'environment_parameter';

    protected $primaryKey = 'parameter_id';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_system' => false,
    ];

    /** @return HasMany<InstrumentTypeEnvironmentParameter, $this> */
    public function instrumentTypeConfigurations(): HasMany
    {
        return $this->hasMany(InstrumentTypeEnvironmentParameter::class, 'parameter_id', 'parameter_id')
            ->orderBy('display_order')
            ->orderBy('instrument_type_id');
    }

    /** @return HasMany<CalibrationEnvironmentParameterSnapshot, $this> */
    public function calibrationSnapshots(): HasMany
    {
        return $this->hasMany(CalibrationEnvironmentParameterSnapshot::class, 'parameter_id', 'parameter_id');
    }

    /** @return HasMany<CalibrationEnvironmentValue, $this> */
    public function calibrationValues(): HasMany
    {
        return $this->hasMany(CalibrationEnvironmentValue::class, 'parameter_id', 'parameter_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }
}
