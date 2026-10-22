<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Global Calibration Activity catalog.
 *
 * Future Calibration Session CRUD should offer active() rows for new
 * selection, snapshot activity_name onto calibration_activity, and later add
 * a nullable activity_id FK. Completed sessions must not live-join this table.
 *
 * @property int $activity_id
 * @property string $activity_code
 * @property string $activity_name
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, CalibrationActivity> $calibrationActivities
 */
class CalibrationActivityMaster extends Model
{
    protected $table = 'calibration_activity_master';

    protected $primaryKey = 'activity_id';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'activity_code',
        'activity_name',
        'is_active',
    ];

    /** @return HasMany<CalibrationActivity, $this> */
    public function calibrationActivities(): HasMany
    {
        return $this->hasMany(CalibrationActivity::class, 'activity_id', 'activity_id');
    }

    /**
     * @param  Builder<CalibrationActivityMaster>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<CalibrationActivityMaster>  $query
     */
    #[Scope]
    protected function whereActivityMasterMatches(Builder $query, string $search): void
    {
        $needle = mb_substr(trim($search), 0, 100);

        if ($needle === '') {
            return;
        }

        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $pattern = '%'.addcslashes($needle, '\\%_').'%';

        $query->where(function (Builder $query) use ($like, $pattern): void {
            $query
                ->whereRaw("calibration_activity_master.activity_name {$like} ? escape '\\'", [$pattern])
                ->orWhereRaw("calibration_activity_master.activity_code {$like} ? escape '\\'", [$pattern]);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
