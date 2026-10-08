<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $instrument_id
 * @property string $instrument_name
 * @property int|null $detail_location_id
 * @property string|null $description
 * @property Carbon $created_at
 * @property-read Collection<int, Calibration> $calibrations
 * @property-read DetailLocation|null $detailLocation
 * @property-read Collection<int, InstrumentType> $instrumentTypes
 */
class Instrument extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'instrument';

    protected $primaryKey = 'instrument_id';

    /** @return HasMany<Calibration, $this> */
    public function calibrations(): HasMany
    {
        return $this->hasMany(Calibration::class, 'instrument_id', 'instrument_id');
    }

    /** @return BelongsTo<DetailLocation, $this> */
    public function detailLocation(): BelongsTo
    {
        return $this->belongsTo(DetailLocation::class, 'detail_location_id', 'detail_location_id');
    }

    /** @return HasMany<InstrumentType, $this> */
    public function instrumentTypes(): HasMany
    {
        return $this->hasMany(InstrumentType::class, 'instrument_id', 'instrument_id');
    }
}
