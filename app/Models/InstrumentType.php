<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $instrument_type_id
 * @property int $instrument_id
 * @property string $type_name
 * @property string|null $description
 * @property Carbon $created_at
 * @property string|null $asset_number
 * @property string|null $serial_number
 * @property string|null $max_capacity
 * @property string|null $max_capacity_unit
 * @property string|null $resolution
 * @property string|null $resolution_unit
 * @property string|null $brand
 * @property string|null $model
 * @property-read Collection<int, TestProfile> $testProfiles
 */
class InstrumentType extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'instrument_type';

    protected $primaryKey = 'instrument_type_id';

    /** @return HasMany<TestProfile, $this> */
    public function testProfiles(): HasMany
    {
        return $this->hasMany(TestProfile::class, 'instrument_type_id', 'instrument_type_id');
    }
}
