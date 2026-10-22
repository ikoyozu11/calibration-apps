<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $standard_id
 * @property string $standard_code
 * @property string $instrument_name
 * @property string|null $type_specification
 * @property string|null $serial_number
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, InstrumentTypeStandardAssignment> $assignments
 * @property-read Collection<int, CalibrationStandardUsage> $calibrationUsages
 */
class StandardMaster extends Model
{
    protected $table = 'standard_master';

    protected $primaryKey = 'standard_id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'standard_code',
        'instrument_name',
        'type_specification',
        'serial_number',
    ];

    /** @return HasMany<InstrumentTypeStandardAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(InstrumentTypeStandardAssignment::class, 'standard_id', 'standard_id')
            ->orderBy('assignment_order')
            ->orderBy('instrument_type_standard_assignment_id');
    }

    /** @return HasMany<CalibrationStandardUsage, $this> */
    public function calibrationUsages(): HasMany
    {
        return $this->hasMany(CalibrationStandardUsage::class, 'standard_id', 'standard_id');
    }

    /**
     * @param  Builder<StandardMaster>  $query
     */
    #[Scope]
    protected function whereStandardMasterMatches(Builder $query, string $search): void
    {
        $needle = mb_substr(trim($search), 0, 100);

        if ($needle === '') {
            return;
        }

        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $pattern = '%'.addcslashes($needle, '\\%_').'%';

        $query->where(function (Builder $query) use ($like, $pattern): void {
            $query
                ->whereRaw("standard_master.instrument_name {$like} ? escape '\\'", [$pattern])
                ->orWhereRaw("coalesce(standard_master.type_specification, '') {$like} ? escape '\\'", [$pattern])
                ->orWhereRaw("coalesce(standard_master.serial_number, '') {$like} ? escape '\\'", [$pattern])
                ->orWhereRaw("standard_master.standard_code {$like} ? escape '\\'", [$pattern]);
        });
    }
}
