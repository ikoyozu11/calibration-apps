<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $certificate_id
 * @property int $calibration_id
 * @property string $certificate_number
 * @property string $certificate_type
 * @property Carbon $issued_date
 * @property string|null $file_path
 * @property string|null $file_name
 * @property string|null $remarks
 * @property Carbon $created_at
 * @property-read Calibration $calibration
 */
class Certificate extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'certificate';

    protected $primaryKey = 'certificate_id';

    /** @return BelongsTo<Calibration, $this> */
    public function calibration(): BelongsTo
    {
        return $this->belongsTo(Calibration::class, 'calibration_id', 'calibration_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_date' => 'date',
        ];
    }
}
