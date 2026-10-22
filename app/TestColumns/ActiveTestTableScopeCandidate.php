<?php

namespace App\TestColumns;

use stdClass;

readonly class ActiveTestTableScopeCandidate
{
    /**
     * A planned calibration scope. Not a persisted CalibrationScope row.
     *
     * @param  array{
     *     schema_version: 2,
     *     captured_at: string,
     *     test_profile_id: int,
     *     test_profile_version_id: int,
     *     version_number: int,
     *     profile_name: string,
     *     display_title: ?string,
     *     description: ?string,
     *     duration_format: 'hh:mm:ss',
     *     columns: list<array<string, mixed>>,
     *     rows: list<array{
     *         test_point_id: int,
     *         point_order: int,
     *         nominal_value: ?string,
     *         unit: ?string,
     *         tolerance_plus: ?string,
     *         tolerance_minus: ?string,
     *         description: ?string,
     *         template_values: array<string, array{numeric_value: ?string, text_value: ?string, unit: ?string}>|stdClass
     *     }>
     * }  $snapshot
     */
    public function __construct(
        public int $scopeOrder,
        public int $instrumentTypeId,
        public int $testProfileId,
        public int $testProfileVersionId,
        public int $versionNumber,
        public string $profileName,
        public ?string $displayTitle,
        public ?string $description,
        public array $snapshot,
    ) {}
}
