<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $calibration_id
 * @property int $instrument_id
 * @property int $calibration_provider_id
 * @property string $calibration_number
 * @property Carbon|null $calibration_date
 * @property Carbon|null $calibration_due
 * @property string $calibration_method
 * @property string|null $calibration_result
 * @property string|null $certificate_number
 * @property string|null $remarks
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $action_date
 * @property string|null $resume
 * @property int|null $customer_id
 * @property-read Collection<int, CalibrationActivity> $activities
 * @property-read Collection<int, CalibrationScope> $calibrationScopes
 * @property-read Customer|null $customer
 * @property-read CalibrationEnvironment|null $environment
 * @property-read Instrument $instrument
 * @property-read Collection<int, CalibrationStandardUsage> $standardUsages
 */
class Calibration extends Model
{
    protected $table = 'calibration';

    protected $primaryKey = 'calibration_id';

    /** @return HasMany<CalibrationScope, $this> */
    public function calibrationScopes(): HasMany
    {
        return $this->hasMany(CalibrationScope::class, 'calibration_id', 'calibration_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'instrument_id', 'instrument_id');
    }

    /** @return HasOne<CalibrationEnvironment, $this> */
    public function environment(): HasOne
    {
        return $this->hasOne(CalibrationEnvironment::class, 'calibration_id', 'calibration_id');
    }

    /** @return HasMany<CalibrationActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(CalibrationActivity::class, 'calibration_id', 'calibration_id');
    }

    /** @return HasMany<CalibrationStandardUsage, $this> */
    public function standardUsages(): HasMany
    {
        return $this->hasMany(CalibrationStandardUsage::class, 'calibration_id', 'calibration_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'calibration_date' => 'date',
            'calibration_due' => 'date',
            'action_date' => 'date',
        ];
    }
}
