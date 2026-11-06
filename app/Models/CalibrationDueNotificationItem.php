<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $batch_id
 * @property int $instrument_id
 * @property int $instrument_type_id
 * @property int $latest_calibration_id
 * @property string $calibration_method
 * @property string $instrument_name_snapshot
 * @property string $instrument_type_snapshot
 * @property string|null $serial_number_snapshot
 * @property string|null $location_snapshot
 * @property Carbon $due_date_snapshot
 * @property string $milestone_code
 * @property Carbon $milestone_date
 * @property string $recipient
 * @property string $status
 * @property int $attempts
 * @property Carbon|null $last_attempt_at
 * @property string|null $failure_message
 */
class CalibrationDueNotificationItem extends Model
{
    protected $table = 'calibration_due_notification_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_date_snapshot' => 'date',
            'milestone_date' => 'date',
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CalibrationDueNotificationBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(CalibrationDueNotificationBatch::class, 'batch_id');
    }
}
