<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $instrument_type_id
 * @property int $instrument_id
 * @property string $type_name
 * @property string|null $description
 * @property Carbon $created_at
 * @property string|null $asset_number
 * @property string|null $max_capacity
 * @property string|null $max_capacity_unit
 * @property string|null $resolution
 * @property string|null $resolution_unit
 * @property string|null $brand
 * @property string|null $model
 * @property int|null $calibration_interval_months
 * @property-read Collection<int, Calibration> $calibrations
 * @property-read Instrument $instrument
 * @property-read Collection<int, InstrumentTypeStandardAssignment> $standardAssignments
 * @property-read Collection<int, TestProfile> $testProfiles
 * @property-read Collection<int, InstrumentTypeRequirement> $requirements
 * @property-read Collection<int, InstrumentTypeEnvironmentParameter> $environmentParameters
 */
class InstrumentType extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'instrument_type';

    protected $primaryKey = 'instrument_type_id';

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'instrument_id', 'instrument_id');
    }

    /** @return HasMany<Calibration, $this> */
    public function calibrations(): HasMany
    {
        return $this->hasMany(Calibration::class, 'instrument_type_id', 'instrument_type_id');
    }

    /** @return HasMany<InstrumentTypeStandardAssignment, $this> */
    public function standardAssignments(): HasMany
    {
        return $this->hasMany(InstrumentTypeStandardAssignment::class, 'instrument_type_id', 'instrument_type_id')
            ->orderBy('assignment_order')
            ->orderBy('instrument_type_standard_assignment_id');
    }

    /** @return HasMany<TestProfile, $this> */
    public function testProfiles(): HasMany
    {
        return $this->hasMany(TestProfile::class, 'instrument_type_id', 'instrument_type_id');
    }

    /** @return HasMany<InstrumentTypeRequirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(InstrumentTypeRequirement::class, 'instrument_type_id', 'instrument_type_id');
    }

    /** @return HasMany<InstrumentTypeEnvironmentParameter, $this> */
    public function environmentParameters(): HasMany
    {
        return $this->hasMany(InstrumentTypeEnvironmentParameter::class, 'instrument_type_id', 'instrument_type_id')
            ->orderBy('display_order')
            ->orderBy('parameter_id');
    }
}
