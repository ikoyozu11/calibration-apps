<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $test_profile_id
 * @property int $instrument_type_id
 * @property string $profile_name
 * @property string|null $description
 * @property Carbon $created_at
 * @property-read InstrumentType $instrumentType
 * @property-read Collection<int, TestPoint> $testPoints
 */
class TestProfile extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'test_profile';

    protected $primaryKey = 'test_profile_id';

    /** @return BelongsTo<InstrumentType, $this> */
    public function instrumentType(): BelongsTo
    {
        return $this->belongsTo(InstrumentType::class, 'instrument_type_id', 'instrument_type_id');
    }

    /** @return HasMany<TestPoint, $this> */
    public function testPoints(): HasMany
    {
        return $this->hasMany(TestPoint::class, 'test_profile_id', 'test_profile_id');
    }
}
