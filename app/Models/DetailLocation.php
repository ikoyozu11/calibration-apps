<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $detail_location_id
 * @property int $location_id
 * @property string $detail_location_code
 * @property string $detail_location_name
 * @property string|null $description
 * @property Carbon $created_at
 * @property-read Location $location
 */
class DetailLocation extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'detail_location';

    protected $primaryKey = 'detail_location_id';

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }
}
