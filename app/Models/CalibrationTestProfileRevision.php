<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable Test Profile switch archive for one Calibration Session scope.
 *
 * previous_test_profile_id, previous_test_profile_version_id,
 * new_test_profile_id, and new_test_profile_version_id are stored identifiers
 * only. The foundation SQL does not declare foreign keys to test_profile or
 * test_profile_version, so those belongsTo relations are not defined.
 *
 * engineer_user_id references app_user.app_user_id. There is no AppUser
 * Eloquent model yet.
 *
 * @property int $revision_id
 * @property int $calibration_id
 * @property int $calibration_scope_id
 * @property int|null $previous_test_profile_id
 * @property int|null $previous_test_profile_version_id
 * @property int|null $new_test_profile_id
 * @property int|null $new_test_profile_version_id
 * @property array<string, mixed> $previous_structure
 * @property array<string, mixed> $previous_results
 * @property array<string, mixed> $proposed_mappings
 * @property array<string, mixed> $confirmed_mappings
 * @property list<mixed> $compatible_result_ids
 * @property list<mixed> $incompatible_result_ids
 * @property string $reason
 * @property Carbon $revised_at
 * @property int|null $engineer_user_id
 * @property-read Calibration $calibration
 * @property-read CalibrationScope $calibrationScope
 */
class CalibrationTestProfileRevision extends Model
{
    public $timestamps = false;

    protected $table = 'calibration_test_profile_revision';

    protected $primaryKey = 'revision_id';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'compatible_result_ids' => '[]',
        'incompatible_result_ids' => '[]',
    ];

    /** @return BelongsTo<Calibration, $this> */
    public function calibration(): BelongsTo
    {
        return $this->belongsTo(Calibration::class, 'calibration_id', 'calibration_id');
    }

    /** @return BelongsTo<CalibrationScope, $this> */
    public function calibrationScope(): BelongsTo
    {
        return $this->belongsTo(CalibrationScope::class, 'calibration_scope_id', 'calibration_scope_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_structure' => 'array',
            'previous_results' => 'array',
            'proposed_mappings' => 'array',
            'confirmed_mappings' => 'array',
            'compatible_result_ids' => 'array',
            'incompatible_result_ids' => 'array',
            'revised_at' => 'datetime',
        ];
    }
}
