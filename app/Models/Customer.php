<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $customer_id
 * @property string $customer_name
 * @property string|null $address
 * @property Carbon $created_at
 */
class Customer extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'customer';

    protected $primaryKey = 'customer_id';
}
