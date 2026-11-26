<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $calibration_notification_id
 * @property int $recipient_user_id
 * @property int|null $instrument_id
 * @property int|null $instrument_type_id
 * @property int|null $source_calibration_id
 * @property string $event_type
 * @property string $event_key
 * @property int $occurrence_revision
 * @property string $instrument_name_snapshot
 * @property string $instrument_type_snapshot
 * @property string|null $serial_number_snapshot
 * @property Carbon $due_date_snapshot
 * @property Carbon|null $read_at
 * @property Carbon|null $resolved_at
 * @property int|null $resolution_calibration_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'instrument_id',
    'instrument_type_id',
    'source_calibration_id',
    'event_type',
    'event_key',
    'instrument_name_snapshot',
    'instrument_type_snapshot',
    'serial_number_snapshot',
    'due_date_snapshot',
    'read_at',
    'resolved_at',
    'resolution_calibration_id',
])]
class CalibrationNotification extends Model
{
    protected $table = 'calibration_notifications';

    protected $primaryKey = 'calibration_notification_id';

    protected function casts(): array
    {
        return [
            'recipient_user_id' => 'integer',
            'instrument_id' => 'integer',
            'instrument_type_id' => 'integer',
            'source_calibration_id' => 'integer',
            'resolution_calibration_id' => 'integer',
            'occurrence_revision' => 'integer',
            'due_date_snapshot' => 'date',
            'read_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('resolved_at'));
    }

    /** @param Builder<self> $query */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('read_at'));
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id', 'id');
    }

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'instrument_id', 'instrument_id');
    }

    /** @return BelongsTo<InstrumentType, $this> */
    public function instrumentType(): BelongsTo
    {
        return $this->belongsTo(InstrumentType::class, 'instrument_type_id', 'instrument_type_id');
    }

    /** @return BelongsTo<Calibration, $this> */
    public function sourceCalibration(): BelongsTo
    {
        return $this->belongsTo(Calibration::class, 'source_calibration_id', 'calibration_id');
    }

    /** @return BelongsTo<Calibration, $this> */
    public function resolutionCalibration(): BelongsTo
    {
        return $this->belongsTo(Calibration::class, 'resolution_calibration_id', 'calibration_id');
    }
}
