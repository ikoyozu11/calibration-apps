<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $test_point_value_id
 * @property int $test_profile_id
 * @property int|null $test_profile_version_id
 * @property int $test_point_id
 * @property int $test_column_id
 * @property string|null $numeric_value
 * @property string|null $text_value
 * @property string|null $unit
 * @property Carbon $created_at
 * @property-read TestProfile $testProfile
 * @property-read TestProfileVersion|null $version
 * @property-read TestPoint $testPoint
 * @property-read TestColumn $testColumn
 */
class TestPointValue extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'test_point_value';

    protected $primaryKey = 'test_point_value_id';

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

    /** @return BelongsTo<TestPoint, $this> */
    public function testPoint(): BelongsTo
    {
        return $this->belongsTo(TestPoint::class, 'test_point_id', 'test_point_id');
    }

    /** @return BelongsTo<TestColumn, $this> */
    public function testColumn(): BelongsTo
    {
        return $this->belongsTo(TestColumn::class, 'test_column_id', 'test_column_id');
    }
}
