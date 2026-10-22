<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Vendor PDF metadata for an External Calibration Session.
 *
 * Files live on the private calibration_documents disk. storage_path is hidden
 * from array/JSON serialization so it is not exposed as a public URL.
 *
 * uploaded_by_user_id references app_user.app_user_id. There is no AppUser
 * Eloquent model yet.
 *
 * @property int $document_id
 * @property int $calibration_id
 * @property string $storage_uuid
 * @property string $storage_path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $byte_size
 * @property string $sha256
 * @property bool $is_active
 * @property Carbon $uploaded_at
 * @property Carbon|null $superseded_at
 * @property int|null $uploaded_by_user_id
 * @property-read Calibration $calibration
 */
class CalibrationExternalDocument extends Model
{
    public $timestamps = false;

    protected $table = 'calibration_external_document';

    protected $primaryKey = 'document_id';

    /**
     * @var list<string>
     */
    protected $hidden = [
        'storage_path',
    ];

    /** @return BelongsTo<Calibration, $this> */
    public function calibration(): BelongsTo
    {
        return $this->belongsTo(Calibration::class, 'calibration_id', 'calibration_id');
    }

    /**
     * @param  Builder<CalibrationExternalDocument>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'byte_size' => 'integer',
            'uploaded_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }
}
