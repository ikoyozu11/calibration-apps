<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $test_profile_version_id
 * @property int $test_profile_id
 * @property int $version_number
 * @property string $status
 * @property string $profile_name
 * @property string|null $display_title
 * @property string|null $description
 * @property Carbon|null $published_at
 * @property Carbon $created_at
 * @property-read TestProfile $testProfile
 * @property-read Collection<int, TestColumn> $testColumns
 * @property-read Collection<int, TestPoint> $testPoints
 * @property-read Collection<int, TestPointValue> $testPointValues
 */
class TestProfileVersion extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    protected $table = 'test_profile_version';

    protected $primaryKey = 'test_profile_version_id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'test_profile_id',
        'version_number',
        'status',
        'profile_name',
        'display_title',
        'description',
        'published_at',
    ];

    /** @return BelongsTo<TestProfile, $this> */
    public function testProfile(): BelongsTo
    {
        return $this->belongsTo(TestProfile::class, 'test_profile_id', 'test_profile_id');
    }

    /** @return HasMany<TestColumn, $this> */
    public function testColumns(): HasMany
    {
        return $this->hasMany(TestColumn::class, 'test_profile_version_id', 'test_profile_version_id');
    }

    /** @return HasMany<TestPoint, $this> */
    public function testPoints(): HasMany
    {
        return $this->hasMany(TestPoint::class, 'test_profile_version_id', 'test_profile_version_id');
    }

    /** @return HasMany<TestPointValue, $this> */
    public function testPointValues(): HasMany
    {
        return $this->hasMany(TestPointValue::class, 'test_profile_version_id', 'test_profile_version_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }
}
