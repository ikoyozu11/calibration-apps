<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * @property int $test_profile_id
 * @property int $instrument_type_id
 * @property string $profile_name
 * @property string|null $display_title
 * @property string|null $description
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property-read InstrumentType $instrumentType
 * @property-read Collection<int, TestColumn> $testColumns
 * @property-read Collection<int, TestPoint> $testPoints
 * @property-read Collection<int, TestProfileVersion> $versions
 */
class TestProfile extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'test_profile';

    protected $primaryKey = 'test_profile_id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'instrument_type_id',
        'profile_name',
        'display_title',
        'description',
    ];

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    #[Scope]
    protected function notArchived(Builder $query): void
    {
        if (! Schema::hasColumn($this->getTable(), 'archived_at')) {
            return;
        }

        $query->whereNull('archived_at');
    }

    /** @return BelongsTo<InstrumentType, $this> */
    public function instrumentType(): BelongsTo
    {
        return $this->belongsTo(InstrumentType::class, 'instrument_type_id', 'instrument_type_id');
    }

    /** @return HasMany<TestColumn, $this> */
    public function testColumns(): HasMany
    {
        return $this->hasMany(TestColumn::class, 'test_profile_id', 'test_profile_id');
    }

    /** @return HasMany<TestPoint, $this> */
    public function testPoints(): HasMany
    {
        return $this->hasMany(TestPoint::class, 'test_profile_id', 'test_profile_id');
    }

    /** @return HasMany<TestProfileVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TestProfileVersion::class, 'test_profile_id', 'test_profile_id')
            ->orderBy('version_number')
            ->orderBy('test_profile_version_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }
}
