<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Session-wide settings such as external_pdf_max_bytes.
 *
 * @property string $setting_key
 * @property string $setting_value
 */
class CalibrationSessionSetting extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'calibration_session_setting';

    protected $primaryKey = 'setting_key';

    protected $keyType = 'string';
}
