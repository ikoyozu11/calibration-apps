<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $instrument_type_standard_assignment_id
 * @property int $instrument_type_id
 * @property int|null $standard_id
 * @property int|null $standard_instrument_id
 * @property int $assignment_order
 * @property string|null $standard_display_name
 * @property string|null $default_range
 * @property string|null $default_unit
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read InstrumentType $instrumentType
 * @property-read StandardMaster|null $standard
 * @property-read Instrument|null $standardInstrument
 */
class InstrumentTypeStandardAssignment extends Model
{
    protected $table = 'instrument_type_standard_assignment';

    protected $primaryKey = 'instrument_type_standard_assignment_id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'instrument_type_id',
        'standard_id',
        'standard_instrument_id',
        'assignment_order',
        'standard_display_name',
        'default_range',
        'default_unit',
    ];

    /** @return BelongsTo<InstrumentType, $this> */
    public function instrumentType(): BelongsTo
    {
        return $this->belongsTo(InstrumentType::class, 'instrument_type_id', 'instrument_type_id');
    }

    /** @return BelongsTo<StandardMaster, $this> */
    public function standard(): BelongsTo
    {
        return $this->belongsTo(StandardMaster::class, 'standard_id', 'standard_id');
    }

    /** @return BelongsTo<Instrument, $this> */
    public function standardInstrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'standard_instrument_id', 'instrument_id');
    }

    public function instrumentName(): string
    {
        return (string) ($this->loadedStandard()?->instrument_name ?? '');
    }

    public function typeSpecification(): string
    {
        return (string) ($this->loadedStandard()?->type_specification ?? '');
    }

    public function serialNumber(): string
    {
        return (string) ($this->loadedStandard()?->serial_number ?? '');
    }

    public function standardCode(): string
    {
        return (string) ($this->loadedStandard()?->standard_code ?? '');
    }

    private function loadedStandard(): ?StandardMaster
    {
        if (! $this->relationLoaded('standard')) {
            $this->load('standard');
        }

        return $this->standard;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assignment_order' => 'integer',
        ];
    }
}
