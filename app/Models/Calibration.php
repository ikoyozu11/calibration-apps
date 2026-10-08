<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $calibration_id
 * @property int $instrument_id
 * @property int $calibration_provider_id
 * @property string $calibration_number
 * @property Carbon|null $calibration_date
 * @property Carbon|null $calibration_due
 * @property string $calibration_method
 * @property string|null $calibration_result
 * @property string|null $certificate_number
 * @property string|null $remarks
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $action_date
 * @property string|null $resume
 * @property int|null $customer_id
 * @property-read Collection<int, CalibrationScope> $calibrationScopes
 */
class Calibration extends Model
{
    protected $table = 'calibration';

    protected $primaryKey = 'calibration_id';

    /** @return HasMany<CalibrationScope, $this> */
    public function calibrationScopes(): HasMany
    {
        return $this->hasMany(CalibrationScope::class, 'calibration_id', 'calibration_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'calibration_date' => 'date',
            'calibration_due' => 'date',
            'action_date' => 'date',
        ];
    }
}
