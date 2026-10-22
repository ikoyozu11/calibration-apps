<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Instrument Type environment parameter configuration.
 *
 * Composite primary key: instrument_type_id + parameter_id.
 *
 * @property int $instrument_type_id
 * @property int $parameter_id
 * @property string $requirement_mode
 * @property int $display_order
 * @property-read InstrumentType $instrumentType
 * @property-read EnvironmentParameter $parameter
 */
class InstrumentTypeEnvironmentParameter extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'instrument_type_environment_parameter';

    protected $primaryKey = 'instrument_type_id';

    /** @return BelongsTo<InstrumentType, $this> */
    public function instrumentType(): BelongsTo
    {
        return $this->belongsTo(InstrumentType::class, 'instrument_type_id', 'instrument_type_id');
    }

    /** @return BelongsTo<EnvironmentParameter, $this> */
    public function parameter(): BelongsTo
    {
        return $this->belongsTo(EnvironmentParameter::class, 'parameter_id', 'parameter_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query
            ->where($this->qualifyColumn('instrument_type_id'), $this->getAttribute('instrument_type_id'))
            ->where($this->qualifyColumn('parameter_id'), $this->getAttribute('parameter_id'));
    }
}
