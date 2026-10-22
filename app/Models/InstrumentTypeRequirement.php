<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Instrument Type requirement configuration. Missing rows mean not_configured.
 *
 * Composite primary key: instrument_type_id + requirement_category.
 *
 * @property int $instrument_type_id
 * @property string $requirement_category
 * @property string $requirement_mode
 * @property Carbon $updated_at
 * @property-read InstrumentType $instrumentType
 */
class InstrumentTypeRequirement extends Model
{
    public const CREATED_AT = null;

    public const CATEGORY_STANDARDS = 'standards';

    public const CATEGORY_ACTIVITY = 'activity';

    public const CATEGORY_ENVIRONMENT = 'environment';

    public const CATEGORY_TEST_PROFILE = 'test_profile';

    /**
     * @var list<string>
     */
    public const CATEGORIES = [
        self::CATEGORY_STANDARDS,
        self::CATEGORY_ACTIVITY,
        self::CATEGORY_ENVIRONMENT,
        self::CATEGORY_TEST_PROFILE,
    ];

    public const MODE_REQUIRED = 'required';

    public const MODE_OPTIONAL = 'optional';

    public const MODE_NOT_CONFIGURED = 'not_configured';

    public $incrementing = false;

    protected $table = 'instrument_type_requirement';

    protected $primaryKey = 'instrument_type_id';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'requirement_mode' => self::MODE_NOT_CONFIGURED,
    ];

    /** @return BelongsTo<InstrumentType, $this> */
    public function instrumentType(): BelongsTo
    {
        return $this->belongsTo(InstrumentType::class, 'instrument_type_id', 'instrument_type_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query
            ->where($this->qualifyColumn('instrument_type_id'), $this->getAttribute('instrument_type_id'))
            ->where($this->qualifyColumn('requirement_category'), $this->getAttribute('requirement_category'));
    }
}
