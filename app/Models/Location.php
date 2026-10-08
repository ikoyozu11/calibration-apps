<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $location_id
 * @property string $location_code
 * @property string $location_name
 * @property string|null $description
 * @property Carbon $created_at
 */
class Location extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'location';

    protected $primaryKey = 'location_id';
}
