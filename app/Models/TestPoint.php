<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $test_point_id
 * @property int $test_profile_id
 * @property int $point_order
 * @property string|null $nominal_value
 * @property string|null $unit
 * @property string|null $tolerance_plus
 * @property string|null $tolerance_minus
 * @property string|null $description
 * @property Carbon $created_at
 * @property-read TestProfile $testProfile
 */
class TestPoint extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'test_point';

    protected $primaryKey = 'test_point_id';

    /** @return BelongsTo<TestProfile, $this> */
    public function testProfile(): BelongsTo
    {
        return $this->belongsTo(TestProfile::class, 'test_profile_id', 'test_profile_id');
    }
}
