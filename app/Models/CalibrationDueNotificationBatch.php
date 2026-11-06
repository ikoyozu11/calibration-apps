<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $notification_date
 * @property string $timezone
 * @property string $recipient
 * @property string $status
 * @property int $attempts
 * @property Carbon|null $claimed_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_message
 */
class CalibrationDueNotificationBatch extends Model
{
    protected $table = 'calibration_due_notification_batches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'notification_date' => 'date',
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return HasMany<CalibrationDueNotificationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CalibrationDueNotificationItem::class, 'batch_id');
    }
}
