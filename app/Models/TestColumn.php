<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $test_column_id
 * @property int $test_profile_id
 * @property int|null $test_profile_version_id
 * @property string $column_key
 * @property string $column_label
 * @property int $column_order
 * @property string $data_type
 * @property string $value_source
 * @property string|null $unit
 * @property int|null $display_scale
 * @property string|null $formula_code
 * @property string|array<int, string>|null $formula_inputs
 * @property Carbon $created_at
 * @property-read TestProfile $testProfile
 * @property-read TestProfileVersion|null $version
 * @property-read Collection<int, TestPointValue> $testPointValues
 */
class TestColumn extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'test_column';

    protected $primaryKey = 'test_column_id';

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
        return $this->hasMany(TestPointValue::class, 'test_column_id', 'test_column_id');
    }

    /**
     * @return list<string>
     */
    public function inputKeys(): array
    {
        $raw = $this->getAttribute('formula_inputs');

        if (is_array($raw)) {
            return array_values(array_map(strval(...), $raw));
        }

        if (! is_string($raw) || trim($raw) === '' || trim($raw) === '{}') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return array_values(array_map(strval(...), $decoded));
        }

        $inner = trim($raw, '{}');

        if ($inner === '') {
            return [];
        }

        return str_getcsv($inner);
    }
}
