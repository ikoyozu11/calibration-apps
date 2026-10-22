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
 * @property int|null $calibration_provider_id
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
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_reason
 * @property int|null $cancelled_by_user_id
 * @property string|null $snapshot_instrument_name
 * @property string|null $snapshot_instrument_type_name
 * @property string|null $snapshot_brand
 * @property string|null $snapshot_model
 * @property string|null $snapshot_serial_number
 * @property string|null $snapshot_asset_number
 * @property string|null $snapshot_max_capacity
 * @property string|null $snapshot_max_capacity_unit
 * @property string|null $snapshot_resolution
 * @property string|null $snapshot_resolution_unit
 * @property int|null $snapshot_location_id
 * @property int|null $snapshot_detail_location_id
 * @property string|null $snapshot_location_code
 * @property string|null $snapshot_location_name
 * @property string|null $snapshot_detail_location_code
 * @property string|null $snapshot_detail_location_name
 * @property-read Collection<int, CalibrationActivity> $activities
 * @property-read Collection<int, CalibrationScope> $calibrationScopes
 * @property-read Customer|null $customer
 * @property-read CalibrationEnvironment|null $environment
 * @property-read Instrument $instrument
 * @property-read InstrumentType|null $instrumentType
 * @property-read Collection<int, CalibrationStandardUsage> $standardUsages
 * @property-read Collection<int, CalibrationRequirementSnapshot> $requirementSnapshots
 * @property-read Collection<int, CalibrationEnvironmentParameterSnapshot> $environmentParameterSnapshots
 * @property-read Collection<int, CalibrationEnvironmentValue> $environmentValues
 * @property-read Collection<int, CalibrationExternalDocument> $externalDocuments
 * @property-read CalibrationExternalDocument|null $activeExternalDocument
 * @property-read Collection<int, CalibrationTestProfileRevision> $testProfileRevisions
 * @property-read Location|null $snapshotLocation
 * @property-read DetailLocation|null $snapshotDetailLocation
 */
class Calibration extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const METHOD_INTERNAL = 'INTERNAL';

    public const METHOD_EXTERNAL = 'EXTERNAL';

    protected $table = 'calibration';

    protected $primaryKey = 'calibration_id';

    /**
     * Historical Calibration History rows omit session_status. The database
     * column and this model default remain completed so those inserts stay
     * compatible. Phase 2B Start Calibration must assign session_status =
     * in_progress explicitly. Do not allocate calibration_number here.
     *
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

    /**
     * Legacy certificate Temperature/RH row. Canonical values are environmentValues().
     *
     * @return HasOne<CalibrationEnvironment, $this>
     */
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

    /** @return HasMany<CalibrationRequirementSnapshot, $this> */
    public function requirementSnapshots(): HasMany
    {
        return $this->hasMany(CalibrationRequirementSnapshot::class, 'calibration_id', 'calibration_id');
    }

    /** @return HasMany<CalibrationEnvironmentParameterSnapshot, $this> */
    public function environmentParameterSnapshots(): HasMany
    {
        return $this->hasMany(CalibrationEnvironmentParameterSnapshot::class, 'calibration_id', 'calibration_id')
            ->orderBy('display_order')
            ->orderBy('parameter_id');
    }

    /** @return HasMany<CalibrationEnvironmentValue, $this> */
    public function environmentValues(): HasMany
    {
        return $this->hasMany(CalibrationEnvironmentValue::class, 'calibration_id', 'calibration_id');
    }

    /** @return HasMany<CalibrationExternalDocument, $this> */
    public function externalDocuments(): HasMany
    {
        return $this->hasMany(CalibrationExternalDocument::class, 'calibration_id', 'calibration_id')
            ->orderBy('uploaded_at')
            ->orderBy('document_id');
    }

    /** @return HasOne<CalibrationExternalDocument, $this> */
    public function activeExternalDocument(): HasOne
    {
        return $this->hasOne(CalibrationExternalDocument::class, 'calibration_id', 'calibration_id')
            ->where('is_active', true);
    }

    /** @return HasMany<CalibrationTestProfileRevision, $this> */
    public function testProfileRevisions(): HasMany
    {
        return $this->hasMany(CalibrationTestProfileRevision::class, 'calibration_id', 'calibration_id')
            ->orderBy('revised_at')
            ->orderBy('revision_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function snapshotLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'snapshot_location_id', 'location_id');
    }

    /** @return BelongsTo<DetailLocation, $this> */
    public function snapshotDetailLocation(): BelongsTo
    {
        return $this->belongsTo(DetailLocation::class, 'snapshot_detail_location_id', 'detail_location_id');
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
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
