<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $calibration_id
 * @property int $instrument_id
 * @property int|null $instrument_type_id
 * @property int $calibration_provider_id
 * @property string $calibration_number
 * @property Carbon|null $calibration_date
 * @property Carbon|null $calibration_due
 * @property string $calibration_method
 * @property string|null $calibration_result
 * @property string $session_status
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
 * @property-read InstrumentType|null $instrumentType
 * @property-read Collection<int, CalibrationStandardUsage> $standardUsages
 */
class Calibration extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'calibration';

    protected $primaryKey = 'calibration_id';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'session_status' => self::STATUS_COMPLETED,
    ];

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

    /** @return BelongsTo<InstrumentType, $this> */
    public function instrumentType(): BelongsTo
    {
        return $this->belongsTo(InstrumentType::class, 'instrument_type_id', 'instrument_type_id');
    }

    /**
     * @param  Builder<Calibration>  $query
     */
    #[Scope]
    protected function completed(Builder $query): void
    {
        $query->where($query->qualifyColumn('session_status'), self::STATUS_COMPLETED);
    }

    /**
     * @param  Builder<Calibration>  $query
     */
    #[Scope]
    protected function inProgress(Builder $query): void
    {
        $query->where($query->qualifyColumn('session_status'), self::STATUS_IN_PROGRESS);
    }

    /**
     * @param  Builder<Calibration>  $query
     */
    #[Scope]
    protected function cancelled(Builder $query): void
    {
        $query->where($query->qualifyColumn('session_status'), self::STATUS_CANCELLED);
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
