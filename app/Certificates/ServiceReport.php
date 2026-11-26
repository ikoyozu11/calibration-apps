<?php

namespace App\Certificates;

use App\Models\CalibrationScope;
use App\Models\CalibrationStandardUsage;
use App\Models\Certificate;
use App\Models\Instrument;
use App\Models\TestPoint;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ServiceReport
{
    /** @var list<array{type_name: string, brand: string, model: string, serial_number: string, max_capacity: ?string, resolution: ?string}>|null */
    private ?array $instrumentRows = null;

    /** @var list<array{name: string, type: string, serial_number: string}>|null */
    private ?array $standards = null;

    /** @var list<array{label: string, remarks: string, tolerance: ?string, columns: list<array{label: string, unit: string}>, rows: list<array{cells: list<string>}>}>|null */
    private ?array $testResultGroups = null;

    /** @var Collection<int, object>|null */
    private ?Collection $resultValues = null;

    public function __construct(public Certificate $certificate) {}

    public static function from(Certificate $certificate): self
    {
        $certificate->load([
            'calibration' => function (BelongsTo $query): void {
                $columns = [
                    'calibration_id',
                    'instrument_id',
                    'customer_id',
                    'calibration_number',
                    'action_date',
                    'calibration_date',
                    'calibration_due',
                    'calibration_method',
                    'resume',
                ];
                $schema = Schema::connection($query->getModel()->getConnectionName() ?: config('database.default'));

                foreach ([
                    'snapshot_instrument_name',
                    'snapshot_instrument_type_name',
                    'snapshot_brand',
                    'snapshot_model',
                    'snapshot_serial_number',
                    'snapshot_asset_number',
                    'snapshot_max_capacity',
                    'snapshot_max_capacity_unit',
                    'snapshot_resolution',
                    'snapshot_resolution_unit',
                    'snapshot_location_name',
                    'snapshot_detail_location_name',
                ] as $column) {
                    if ($schema->hasColumn('calibration', $column)) {
                        $columns[] = $column;
                    }
                }

                $query->select($columns);
            },
            'calibration.customer' => function (BelongsTo $query): void {
                $query->select([
                    'customer_id',
                    'customer_name',
                    'address',
                ]);
            },
            'calibration.instrument' => function (BelongsTo $query): void {
                $query->select([
                    'instrument_id',
                    'instrument_name',
                    'serial_number',
                    'detail_location_id',
                ]);
            },
            'calibration.instrument.detailLocation' => function (BelongsTo $query): void {
                $query->select([
                    'detail_location_id',
                    'location_id',
                    'detail_location_code',
                    'detail_location_name',
                ]);
            },
            'calibration.instrument.detailLocation.location' => function (BelongsTo $query): void {
                $query->select([
                    'location_id',
                    'location_code',
                    'location_name',
                ]);
            },
            'calibration.instrumentType' => function (BelongsTo $query): void {
                $query->select([
                    'instrument_type_id',
                    'type_name',
                    'brand',
                    'model',
                    'asset_number',
                    'max_capacity',
                    'max_capacity_unit',
                    'resolution',
                    'resolution_unit',
                ]);
            },
            'calibration.calibrationScopes' => function (HasMany $query): void {
                $query
                    ->select([
                        'calibration_scope_id',
                        'calibration_id',
                        'instrument_type_id',
                        'test_profile_id',
                        'scope_order',
                        'test_table_snapshot',
                    ])
                    ->orderBy('scope_order')
                    ->orderBy('calibration_scope_id');
            },
            'calibration.calibrationScopes.instrumentType' => function (BelongsTo $query): void {
                $query->select([
                    'instrument_type_id',
                    'type_name',
                    'brand',
                    'model',
                    'max_capacity',
                    'max_capacity_unit',
                    'resolution',
                    'resolution_unit',
                ]);
            },
            'calibration.calibrationScopes.testProfile' => function (BelongsTo $query): void {
                $query->select([
                    'test_profile_id',
                    'instrument_type_id',
                    'profile_name',
                    'description',
                ]);
            },
            'calibration.calibrationScopes.calibrationTestResults' => function (HasMany $query): void {
                $query
                    ->select([
                        'calibration_test_result_id',
                        'calibration_scope_id',
                        'test_point_id',
                        'standard_value',
                        'average_value',
                        'correction_value',
                    ])
                    ->orderBy(
                        TestPoint::query()
                            ->select('point_order')
                            ->whereColumn('test_point.test_point_id', 'calibration_test_result.test_point_id')
                    )
                    ->orderBy('calibration_test_result_id');
            },
            'calibration.calibrationScopes.calibrationTestResults.testPoint' => function (BelongsTo $query): void {
                $query->select([
                    'test_point_id',
                    'test_profile_id',
                    'point_order',
                    'unit',
                    'tolerance_plus',
                    'tolerance_minus',
                    'description',
                ]);
            },
            'calibration.environment' => function (HasOne $query): void {
                $query->select([
                    'calibration_environment_id',
                    'calibration_id',
                    'temperature_value',
                    'temperature_unit',
                    'humidity_value',
                    'humidity_unit',
                ]);
            },
            'calibration.standardUsages' => function (HasMany $query): void {
                $columns = [
                    'calibration_standard_usage_id',
                    'calibration_id',
                    'standard_instrument_id',
                    'usage_order',
                    'standard_range',
                    'standard_unit',
                ];
                $schema = Schema::connection($query->getModel()->getConnectionName() ?: config('database.default'));

                foreach (['snapshot_standard_code', 'snapshot_instrument_name', 'snapshot_type_specification', 'snapshot_serial_number'] as $column) {
                    if ($schema->hasColumn('calibration_standard_usage', $column)) {
                        $columns[] = $column;
                    }
                }

                $query->select($columns)
                    ->orderBy('usage_order')
                    ->orderBy('calibration_standard_usage_id');
            },
            'calibration.standardUsages.instrument' => function (BelongsTo $query): void {
                $query->select([
                    'instrument_id',
                    'instrument_name',
                    'serial_number',
                ]);
            },
            'calibration.activities' => function (HasMany $query): void {
                $columns = [
                    'calibration_activity_id',
                    'calibration_id',
                ];
                $schema = Schema::connection($query->getModel()->getConnectionName() ?: config('database.default'));

                foreach (['activity_type', 'activity_description', 'activity_result', 'activity_order', 'activity_id', 'activity_name'] as $column) {
                    if ($schema->hasColumn('calibration_activity', $column)) {
                        $columns[] = $column;
                    }
                }

                $query->select($columns);

                if (in_array('activity_order', $columns, true)) {
                    $query->orderBy('activity_order');
                }

                $query->orderBy('calibration_activity_id');
            },
        ]);

        return new self($certificate);
    }

    public function documentNumber(): string
    {
        return $this->text($this->certificate->certificate_number);
    }

    public function issuedDate(): string
    {
        return $this->certificate->issued_date->format('j F Y');
    }

    public function issuedYear(): string
    {
        return $this->certificate->issued_date->format('Y');
    }

    public function customerName(): string
    {
        if ($this->isTpoLocation()) {
            return 'PT. RESTU SEJATI INTI ABADI';
        }

        return $this->text($this->certificate->calibration->customer?->customer_name ?? 'PT. BENTOEL PRIMA');
    }

    public function customerAddress(): ?string
    {
        $location = $this->normalizedLocation();

        if ($this->isTpoLocation()) {
            return 'Sayung Gg. 4, Kecamatan Sayung, Kota Semarang, Jawa Tengah';
        }

        if ($location === 'thp' || str_starts_with($location, 'thp ')) {
            return 'Jl. Simpang Kavaleri, Randuagung, Singosari, Malang';
        }

        foreach (['diet', 'e-liquid', 'fmd', 'imi', 'instrument', 'pmd', 'ryo', 'smd', 'tpo'] as $knownLocation) {
            if ($location === $knownLocation || str_starts_with($location, $knownLocation.' ')) {
                return 'Jl. Raya Perusahaan, Karanglo, Singosari, Malang';
            }
        }

        return null;
    }

    private function isTpoLocation(): bool
    {
        $location = $this->normalizedLocation();

        return $location === 'tpo' || str_starts_with($location, 'tpo ');
    }

    public function instrumentName(): string
    {
        return $this->text(
            $this->certificate->calibration->snapshot_instrument_name
                ?? $this->certificate->calibration->instrument->instrument_name,
        );
    }

    public function location(): string
    {
        if ($this->hasFrozenIdentity()) {
            return $this->text($this->certificate->calibration->snapshot_location_name);
        }

        $instrument = $this->certificate->calibration->instrument;
        $location = collect([
            $instrument->detailLocation?->location?->location_code,
            $instrument->detailLocation?->detail_location_code,
        ])->filter(fn (?string $value): bool => filled($value))->implode(' / ');

        return $location !== '' ? $location : '—';
    }

    public function actionDate(): string
    {
        return $this->certificate->calibration->action_date?->format('j F Y') ?? '—';
    }

    public function calibrationDate(): string
    {
        return $this->certificate->calibration->calibration_date?->format('j F Y') ?? '—';
    }

    public function calibrationDateShort(): string
    {
        return $this->certificate->calibration->calibration_date?->format('d M Y') ?? '—';
    }

    public function calibrationDue(): string
    {
        return $this->certificate->calibration->calibration_due?->format('j F Y') ?? '—';
    }

    public function hasCalibrationDue(): bool
    {
        return $this->certificate->calibration->calibration_due !== null;
    }

    public function calibrationMethod(): string
    {
        return $this->text($this->certificate->calibration->calibration_method);
    }

    public function calibrationNumber(): string
    {
        return $this->text($this->certificate->calibration->calibration_number);
    }

    /**
     * @return array{name: string, type: string, brand: string, model: string, serial_number: string, asset_number: string, max_capacity: ?string, resolution: ?string}
     */
    public function instrumentIdentity(): array
    {
        $rows = $this->instrumentRows();
        $row = $rows[0] ?? null;

        return [
            'name' => $this->instrumentName(),
            'type' => $row['type_name'] ?? $this->text(null),
            'brand' => $row['brand'] ?? $this->text(null),
            'model' => $row['model'] ?? $this->text(null),
            'serial_number' => $row['serial_number'] ?? $this->text(null),
            'asset_number' => $row['asset_number'] ?? $this->text(null),
            'location' => $this->location(),
            'detail_location' => $this->detailLocation(),
            'max_capacity' => $row['max_capacity'],
            'resolution' => $row['resolution'],
        ];
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return list<array<string, string>>
     */
    public static function identityFieldColumnsFor(array $identity): array
    {
        $labels = [
            'name' => 'Instrument Name',
            'location' => 'Location',
            'detail_location' => 'Detail Location',
            'type' => 'Instrument Type',
            'serial_number' => 'Serial Number',
            'asset_number' => 'Asset Number',
            'brand' => 'Brand',
            'model' => 'Model',
            'max_capacity' => 'Max Capacity',
            'resolution' => 'Resolution',
        ];
        $fields = [];

        foreach ($labels as $key => $label) {
            $value = $identity[$key] ?? null;

            if (self::isEmptyIdentityValue($value)) {
                continue;
            }

            $fields[$label] = (string) $value;
        }

        return array_chunk($fields, 3, true);
    }

    private static function isEmptyIdentityValue(mixed $value): bool
    {
        if ($value === null || ! is_string($value)) {
            return $value === null;
        }

        $value = trim($value);

        if ($value === '' || $value === '-' || $value === '—' || strtoupper($value) === 'N/A') {
            return true;
        }

        return mb_strlen($value) <= 3 && preg_match('/[A-Za-z0-9]/', $value) !== 1;
    }

    public function assetNumber(): string
    {
        if ($this->hasFrozenIdentity()) {
            return $this->text($this->certificate->calibration->snapshot_asset_number);
        }

        return $this->text($this->certificate->calibration->instrumentType?->asset_number);
    }

    public function detailLocation(): string
    {
        if ($this->hasFrozenIdentity()) {
            return $this->text($this->certificate->calibration->snapshot_detail_location_name);
        }

        return $this->text($this->certificate->calibration->instrument?->detailLocation?->detail_location_name);
    }

    /**
     * @return list<array{type_name: string, brand: string, model: string, serial_number: string, max_capacity: ?string, resolution: ?string}>
     */
    public function instrumentRows(): array
    {
        if ($this->instrumentRows !== null) {
            return $this->instrumentRows;
        }

        $rows = [];

        $calibration = $this->certificate->calibration;
        $hasFrozenIdentity = $this->hasFrozenIdentity();
        $scopes = $calibration->calibrationScopes;

        if ($scopes->isEmpty()) {
            $scopes = collect([(object) ['instrumentType' => $calibration->instrumentType]]);
        }

        foreach ($scopes as $scope) {
            $type = $scope->instrumentType;

            $rows[] = [
                'type_name' => $this->text($hasFrozenIdentity ? $calibration->snapshot_instrument_type_name : $type?->type_name),
                'brand' => $this->text($hasFrozenIdentity ? $calibration->snapshot_brand : $type?->brand),
                'model' => $this->text($hasFrozenIdentity ? $calibration->snapshot_model : $type?->model),
                'serial_number' => $this->text($hasFrozenIdentity ? $calibration->snapshot_serial_number : $calibration->instrument?->serial_number),
                'asset_number' => $this->text($hasFrozenIdentity ? $calibration->snapshot_asset_number : $type?->asset_number),
                'max_capacity' => $this->measurement(
                    $hasFrozenIdentity ? $calibration->snapshot_max_capacity : $type?->max_capacity,
                    $hasFrozenIdentity ? $calibration->snapshot_max_capacity_unit : $type?->max_capacity_unit,
                ),
                'resolution' => $this->measurement(
                    $hasFrozenIdentity ? $calibration->snapshot_resolution : $type?->resolution,
                    $hasFrozenIdentity ? $calibration->snapshot_resolution_unit : $type?->resolution_unit,
                ),
            ];
        }

        $this->instrumentRows = $rows;

        return $this->instrumentRows;
    }

    private function hasFrozenIdentity(): bool
    {
        foreach ([
            'snapshot_instrument_name',
            'snapshot_location_name',
            'snapshot_detail_location_name',
            'snapshot_instrument_type_name',
            'snapshot_brand',
            'snapshot_model',
            'snapshot_serial_number',
            'snapshot_asset_number',
            'snapshot_max_capacity',
            'snapshot_resolution',
        ] as $attribute) {
            if (filled($this->certificate->calibration->getAttribute($attribute))) {
                return true;
            }
        }

        return false;
    }

    public function showsMaxCapacity(): bool
    {
        return collect($this->instrumentRows())->contains(fn (array $row): bool => $row['max_capacity'] !== null);
    }

    public function showsResolution(): bool
    {
        return collect($this->instrumentRows())->contains(fn (array $row): bool => $row['resolution'] !== null);
    }

    public function temperature(): string
    {
        $environment = $this->certificate->calibration->environment;

        return $this->measurement($environment?->temperature_value, $environment?->temperature_unit) ?? '—';
    }

    public function humidity(): string
    {
        $environment = $this->certificate->calibration->environment;

        return $this->measurement($environment?->humidity_value, $environment?->humidity_unit) ?? '—';
    }

    /**
     * @return list<string>
     */
    public function activities(): array
    {
        if (! $this->certificate->calibration->relationLoaded('activities')) {
            return [];
        }

        return $this->certificate->calibration->activities
            ->sortBy([['activity_order', 'asc'], ['calibration_activity_id', 'asc']])
            ->map(fn (object $activity): string => $this->text(
                $activity->getAttribute('activity_name') ?? $activity->getAttribute('activity_type'),
            ))
            ->filter(fn (string $activity): bool => $activity !== 'â€”')
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, type: string, serial_number: string}>
     */
    public function standards(): array
    {
        if ($this->standards !== null) {
            return $this->standards;
        }

        $rows = [];

        foreach ($this->certificate->calibration->standardUsages as $usage) {
            $rows[] = [
                'name' => $this->optionalText($usage->getAttribute('snapshot_instrument_name') ?? $usage->instrument?->instrument_name),
                'type' => $this->optionalText($usage->getAttribute('snapshot_type_specification')),
                'serial_number' => $this->optionalText($usage->getAttribute('snapshot_serial_number') ?? $usage->instrument?->serial_number),
            ];
        }

        $this->standards = $rows;

        return $this->standards;
    }

    /**
     * @return list<string>
     */
    public function standardList(): array
    {
        $lines = [];

        foreach ($this->standards() as $standard) {
            $values = [
                $standard['name'] ?? null,
                $standard['type'] ?? null,
                $standard['serial_number'] ?? null,
            ];

            if (collect($values)->every(fn (mixed $value): bool => self::isEmptyIdentityValue($value))) {
                continue;
            }

            $lines[] = implode('; ', array_map(
                fn (mixed $value): string => self::isEmptyIdentityValue($value) ? '-' : trim((string) $value),
                $values,
            ));
        }

        return $lines;
    }

    private function optionalText(mixed $value): string
    {
        return filled($value) ? (string) $value : '';
    }

    /**
     * @return list<array{label: string, remarks: string, tolerance: ?string, rows: list<array{standard_value: string, average_value: string, correction_value: string, unit: string}>}>
     */
    public function testResultGroups(): array
    {
        if ($this->testResultGroups !== null) {
            return $this->testResultGroups;
        }

        $dynamic = $this->dynamicTestResultGroups();

        if ($dynamic !== null) {
            $this->testResultGroups = $dynamic;

            return $this->testResultGroups;
        }

        $groups = [];

        foreach ($this->certificate->calibration->calibrationScopes as $scope) {
            $rows = [];

            foreach ($scope->calibrationTestResults as $result) {
                $rows[] = [
                    'standard_value' => $this->text($result->standard_value),
                    'average_value' => $this->text($result->average_value),
                    'correction_value' => $this->text($result->correction_value),
                    'unit' => $this->text($result->testPoint->unit),
                ];
            }

            $groups[] = [
                'label' => $this->text($scope->instrumentType->type_name),
                'remarks' => $this->snapshotRemarks($scope->getAttribute('test_table_snapshot')),
                'tolerance' => $this->correctionTolerance($scope),
                'columns' => [
                    ['label' => 'Standard Value', 'unit' => $rows[0]['unit'] ?? ''],
                    ['label' => 'Standard Reading Avg', 'unit' => $rows[0]['unit'] ?? ''],
                    ['label' => 'Correction', 'unit' => $rows[0]['unit'] ?? ''],
                ],
                'rows' => array_map(fn (array $row): array => [
                    'cells' => [$row['standard_value'], $row['average_value'], $row['correction_value']],
                ], $rows),
            ];
        }

        $this->testResultGroups = $groups;

        return $this->testResultGroups;
    }

    /**
     * @return list<array{mode: 'full'|'half'|'pair', groups: list<array{label: string, remarks: string, tolerance: ?string, columns: list<array{label: string, unit: string}>, rows: list<array{cells: list<string>}>}>, widths?: list<int> }>
     */
    public function testResultLayouts(): array
    {
        return self::testResultLayoutsFor($this->testResultGroups());
    }

    /**
     * @param  list<array{label: string, remarks: string, tolerance: ?string, columns: list<array{label: string, unit: string}>, rows: list<array{cells: list<string>}>}>  $groups
     * @return list<array{mode: 'full'|'half'|'pair', groups: list<array{label: string, remarks: string, tolerance: ?string, columns: list<array{label: string, unit: string}>, rows: list<array{cells: list<string>}>}>, widths?: list<int> }>
     */
    public static function testResultLayoutsFor(array $groups): array
    {
        $layouts = [];
        $lastPairLeftWidth = null;
        $groupCount = count($groups);

        for ($index = 0; $index < count($groups); $index++) {
            if (! self::testResultGroupFitsHalfWidth($groups[$index])) {
                $layouts[] = ['mode' => 'full', 'groups' => [$groups[$index]]];

                continue;
            }

            if (isset($groups[$index + 1]) && self::testResultGroupsFitPair($groups[$index], $groups[$index + 1])) {
                $leftDemand = self::testResultGroupWidthDemand($groups[$index]);
                $rightDemand = self::testResultGroupWidthDemand($groups[$index + 1]);
                $totalDemand = $leftDemand + $rightDemand;

                $layouts[] = [
                    'mode' => 'pair',
                    'groups' => [$groups[$index], $groups[++$index]],
                    'widths' => [
                        (int) round(($leftDemand / $totalDemand) * 100),
                        (int) round(($rightDemand / $totalDemand) * 100),
                    ],
                ];
                $lastPairLeftWidth = $layouts[array_key_last($layouts)]['widths'][0];

                continue;
            }

            $standaloneWidth = $groupCount === 1 || $lastPairLeftWidth === null
                ? self::testResultStandaloneWidth($groups[$index])
                : (self::testResultGroupWidthDemand($groups[$index]) <= $lastPairLeftWidth
                    ? $lastPairLeftWidth
                    : self::testResultStandaloneWidth($groups[$index]));

            $layouts[] = [
                'mode' => 'half',
                'groups' => [$groups[$index]],
                'widths' => [$standaloneWidth],
            ];
        }

        return $layouts;
    }

    /**
     * @param  array{label: string, remarks: string, tolerance: ?string, columns: list<array{label: string, unit: string}>, rows: list<array{cells: list<string>}>}  $group
     */
    private static function testResultGroupFitsHalfWidth(array $group): bool
    {
        if (count($group['rows']) > 5) {
            return false;
        }

        if (mb_strlen($group['remarks']) > 80 || ($group['tolerance'] !== null && mb_strlen($group['tolerance']) > 36)) {
            return false;
        }

        foreach ($group['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                if (mb_strlen($cell) > 24) {
                    return false;
                }
            }
        }

        return self::testResultGroupWidthDemand($group) <= 52;
    }

    /**
     * @param  array{columns: list<array{label: string, unit: string}>, rows: list<array{cells: list<string>}>}  $left
     * @param  array{columns: list<array{label: string, unit: string}>, rows: list<array{cells: list<string>}>}  $right
     */
    private static function testResultGroupsFitPair(array $left, array $right): bool
    {
        return self::testResultGroupFitsHalfWidth($left)
            && self::testResultGroupFitsHalfWidth($right)
            && self::testResultGroupWidthDemand($left) + self::testResultGroupWidthDemand($right) <= 100;
    }

    /**
     * Estimate the minimum readable width using wrapped headings and cell values.
     * The result is relative to the printable report width, not a pixel width.
     *
     * @param  array{columns: list<array{label: string, unit: string}>, rows: list<array{cells: list<string>}>}  $group
     */
    private static function testResultGroupWidthDemand(array $group): int
    {
        $demand = 0;

        foreach ($group['columns'] as $columnIndex => $column) {
            $headingLength = mb_strlen(trim($column['label'].' '.$column['unit']));
            $cellLength = 0;

            foreach ($group['rows'] as $row) {
                $cellLength = max($cellLength, mb_strlen((string) ($row['cells'][$columnIndex] ?? '')));
            }

            $demand += max(10, min(18, max($cellLength, min(10, $headingLength)) + 1));
        }

        return $demand + max(0, count($group['columns']) - 1);
    }

    /**
     * @param  array{columns: list<array{label: string, unit: string}>, rows: list<array{cells: list<string>}>}  $group
     */
    private static function testResultStandaloneWidth(array $group): int
    {
        $columnCount = count($group['columns']);
        $minimumWidth = match (true) {
            $columnCount >= 4 => 70,
            $columnCount === 3 => 55,
            default => 45,
        };

        return min(90, max($minimumWidth, (int) ceil(self::testResultGroupWidthDemand($group) * 1.35)));
    }

    /**
     * Estimate whether a test-result group is short enough to keep together.
     *
     * Dompdf cannot expose the remaining cursor height while rendering a view,
     * so the estimate includes row text length to account for wrapping and is
     * deliberately conservative for larger groups.
     *
     * @param  array{columns: list<array{label: string, unit: string}>, rows: list<array{cells: list<string>}>, remarks: string, tolerance: ?string}  $group
     */
    public function testResultGroupIsCompact(array $group): bool
    {
        $columnCount = max(1, count($group['columns']));
        $estimatedPoints = 28;
        $charactersPerLine = max(14, (int) floor(92 / $columnCount));

        foreach ($group['rows'] as $row) {
            $cellLengths = array_map(
                static fn (string $cell): int => mb_strlen($cell),
                $row['cells'],
            );
            $longestCell = max($cellLengths ?: [0]);
            $lines = max(1, (int) ceil($longestCell / $charactersPerLine));
            $estimatedPoints += 15 + (($lines - 1) * 8);
        }

        if ($group['remarks'] !== '') {
            $estimatedPoints += 20;
        }

        if ($group['tolerance'] !== null) {
            $estimatedPoints += 20;
        }

        return $estimatedPoints <= 430;
    }

    public function hasActionDate(): bool
    {
        return $this->certificate->calibration->action_date !== null;
    }

    public function hasTemperature(): bool
    {
        return filled($this->certificate->calibration->environment?->temperature_value);
    }

    public function hasHumidity(): bool
    {
        return filled($this->certificate->calibration->environment?->humidity_value);
    }

    public function resume(): string
    {
        return $this->text($this->certificate->calibration->resume);
    }

    /**
     * @return list<array{label: string, remarks: string, tolerance: ?string, columns: list<array{label: string, unit: string}>, rows: list<array{cells: list<string>}>}>|null
     */
    private function dynamicTestResultGroups(): ?array
    {
        $scopes = $this->certificate->calibration->calibrationScopes;
        $hasSnapshot = $scopes->contains(fn (CalibrationScope $scope): bool => is_array($scope->test_table_snapshot));

        if (! $hasSnapshot) {
            return null;
        }

        $values = $this->resultValues();
        $resultMap = [];

        foreach ($values as $value) {
            $resultMap[(int) $value->calibration_test_result_id][(string) $value->column_key] = $value;
        }

        $groups = [];

        foreach ($scopes->sortBy([['scope_order', 'asc'], ['calibration_scope_id', 'asc']]) as $scope) {
            $snapshot = $scope->test_table_snapshot;

            if (! is_array($snapshot) || ! is_array($snapshot['columns'] ?? null) || ! is_array($snapshot['rows'] ?? null)) {
                continue;
            }

            $columns = collect($snapshot['columns'])
                ->filter(fn (mixed $column): bool => is_array($column) && filled($column['column_key'] ?? null))
                ->sortBy(fn (array $column): int => (int) ($column['column_order'] ?? 0))
                ->values()
                ->all();
            $rows = collect($snapshot['rows'])
                ->filter(fn (mixed $row): bool => is_array($row))
                ->sortBy(fn (array $row): int => (int) ($row['point_order'] ?? 0))
                ->values()
                ->all();

            if ($columns === [] || $rows === []) {
                continue;
            }

            $presentedRows = [];
            $hasMeaningfulValue = false;

            foreach ($rows as $row) {
                $templateValues = is_array($row['template_values'] ?? null) ? $row['template_values'] : [];
                $resultId = $this->resultIdForPoint($scope, (int) ($row['test_point_id'] ?? 0));
                $cells = [];

                foreach ($columns as $column) {
                    $key = (string) $column['column_key'];
                    $source = (string) ($column['value_source'] ?? '');
                    $entry = $source === 'template'
                        ? ($templateValues[$key] ?? null)
                        : ($resultMap[$resultId][$key] ?? null);
                    $display = $this->formatDynamicValue($column, $entry);
                    $cells[] = $display;
                    if ($display !== '') {
                        $hasMeaningfulValue = true;
                    }
                    $hasMeaningfulValue = $hasMeaningfulValue || $display !== 'â€”';
                }

                $presentedRows[] = ['cells' => $cells];
            }

            if (! $hasMeaningfulValue) {
                continue;
            }

            $groups[] = [
                'label' => $this->displayTitle($snapshot['display_title'] ?? null),
                'remarks' => $this->dynamicText($snapshot['remarks'] ?? $snapshot['description'] ?? null),
                'tolerance' => null,
                'columns' => array_map(fn (array $column): array => [
                    'label' => $this->dynamicText($column['column_label'] ?? $column['column_key']),
                    'unit' => $this->dynamicText($column['unit'] ?? null),
                ], $columns),
                'rows' => $presentedRows,
            ];
        }

        return $groups;
    }

    private function resultValues(): Collection
    {
        if ($this->resultValues !== null) {
            return $this->resultValues;
        }

        $connection = $this->certificate->calibration->getConnection();
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable('calibration_result_value')) {
            return $this->resultValues = new Collection;
        }

        $resultIds = $this->certificate->calibration->calibrationScopes
            ->flatMap(fn (CalibrationScope $scope): Collection => $scope->calibrationTestResults)
            ->pluck('calibration_test_result_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if ($resultIds === []) {
            return $this->resultValues = new Collection;
        }

        return $this->resultValues = $connection
            ->table('calibration_result_value')
            ->whereIn('calibration_test_result_id', $resultIds)
            ->orderBy('calibration_result_value_id')
            ->get();
    }

    private function resultIdForPoint(CalibrationScope $scope, int $pointId): int
    {
        return (int) ($scope->calibrationTestResults
            ->first(fn (object $result): bool => (int) $result->test_point_id === $pointId)
            ?->calibration_test_result_id ?? 0);
    }

    private function formatDynamicValue(array $column, mixed $entry): string
    {
        if (is_object($entry)) {
            $numeric = $entry->numeric_value ?? null;
            $text = $entry->text_value ?? null;
        } else {
            $numeric = is_array($entry) ? ($entry['numeric_value'] ?? null) : null;
            $text = is_array($entry) ? ($entry['text_value'] ?? null) : null;
        }

        if (($column['data_type'] ?? null) === 'text') {
            return $this->displayValue($text);
        }

        if ($numeric === null || $numeric === '') {
            return 'â€”';
        }

        $value = (string) $numeric;

        if (($column['data_type'] ?? null) === 'duration' && preg_match('/^-?\d+$/', $value) === 1) {
            $minutes = intdiv((int) $value, 60);
            $seconds = abs((int) $value) % 60;

            return sprintf('%s%02d:%02d', (int) $value < 0 ? '-' : '', abs($minutes), $seconds);
        }

        $scale = array_key_exists('display_scale', $column) && $column['display_scale'] !== null
            ? (int) $column['display_scale']
            : null;

        if ($scale !== null && is_numeric($value)) {
            return number_format((float) $value, $scale, '.', '');
        }

        return $value;
    }

    public function hasResume(): bool
    {
        return filled($this->certificate->calibration->resume);
    }

    private function normalizedLocation(): string
    {
        $location = (string) ($this->certificate->calibration->snapshot_location_name ?? '');

        if (trim($location) === '') {
            $location = trim(explode('/', $this->location(), 2)[0]);
        }

        return strtolower(trim(preg_replace('/\s+/', ' ', $location) ?? ''));
    }

    private function displayValue(mixed $value): string
    {
        return filled($value) ? (string) $value : 'â€”';
    }

    private function dynamicText(mixed $value): string
    {
        return filled($value) ? (string) $value : '';
    }

    private function displayTitle(mixed $value): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : '';
    }

    private function snapshotRemarks(mixed $snapshot): string
    {
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }

        return is_array($snapshot) && is_string($snapshot['remarks'] ?? null)
            ? $this->text($snapshot['remarks'])
            : '';
    }

    private function standardSerialNumber(?Instrument $instrument): string
    {
        return $this->text($instrument?->serial_number);
    }

    private function correctionTolerance(CalibrationScope $scope): ?string
    {
        $descriptions = $scope->calibrationTestResults
            ->map(fn ($result): ?string => $result->testPoint->description)
            ->push($scope->testProfile?->description)
            ->filter(fn (?string $description): bool => is_string($description) && preg_match('/tolerance/i', $description) === 1)
            ->map(fn (string $description): string => $this->toleranceText($description))
            ->unique()
            ->values();

        if ($descriptions->count() === 1) {
            return $descriptions->first();
        }

        if ($descriptions->isNotEmpty()) {
            return null;
        }

        $pairs = $scope->calibrationTestResults
            ->map(function ($result): ?string {
                $point = $result->testPoint;

                if ($point->tolerance_plus === null && $point->tolerance_minus === null) {
                    return null;
                }

                $pair = $this->text($point->tolerance_plus).' / '.$this->text($point->tolerance_minus);

                return filled($point->unit) ? $pair.' '.$point->unit : $pair;
            })
            ->filter(fn (?string $pair): bool => is_string($pair))
            ->unique()
            ->values();

        if ($pairs->count() === 1) {
            return $pairs->first();
        }

        return null;
    }

    private function toleranceText(string $description): string
    {
        if (preg_match('/^correction tolerance\s*(.+)$/i', trim($description), $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($description);
    }

    private function range(CalibrationStandardUsage $usage): string
    {
        $range = trim(implode(' ', array_filter([
            filled($usage->standard_range) ? $usage->standard_range : null,
            filled($usage->standard_unit) ? $usage->standard_unit : null,
        ])));

        return $range !== '' ? $range : '—';
    }

    private function measurement(?string $value, ?string $unit): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $amount = $value;

        if (is_numeric($amount) && str_contains($amount, '.')) {
            $amount = rtrim(rtrim($amount, '0'), '.');
            $amount = $amount === '' || $amount === '-' ? '0' : $amount;
        }

        return filled($unit) ? $amount.' '.$unit : $amount;
    }

    private function text(?string $value): string
    {
        return filled($value) ? $value : '—';
    }
}
