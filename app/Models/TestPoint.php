<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $test_point_id
 * @property int $test_profile_id
 * @property int|null $test_profile_version_id
 * @property int $point_order
 * @property string|null $nominal_value
 * @property string|null $unit
 * @property string|null $tolerance_plus
 * @property string|null $tolerance_minus
 * @property string|null $description
 * @property Carbon $created_at
 * @property-read TestProfile $testProfile
 * @property-read TestProfileVersion|null $version
 * @property-read Collection<int, TestPointValue> $testPointValues
 */
class TestPoint extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'test_point';

    protected $primaryKey = 'test_point_id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'test_profile_id',
        'test_profile_version_id',
        'point_order',
        'nominal_value',
        'unit',
        'tolerance_plus',
        'tolerance_minus',
        'description',
    ];

    /** @return BelongsTo<TestProfile, $this> */
    public function testProfile(): BelongsTo
    {
        return $this->belongsTo(TestProfile::class, 'test_profile_id', 'test_profile_id');
    }

    /** @return BelongsTo<TestProfileVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(TestProfileVersion::class, 'test_profile_version_id', 'test_profile_version_id');
    }

    /** @return HasMany<TestPointValue, $this> */
    public function testPointValues(): HasMany
    {
        return $this->hasMany(TestPointValue::class, 'test_point_id', 'test_point_id');
    }
}
