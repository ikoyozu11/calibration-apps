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

class ServiceReport
{
    /** @var list<array{type_name: string, brand: string, model: string, serial_number: string, max_capacity: ?string, resolution: ?string}>|null */
    private ?array $instrumentRows = null;

    /** @var list<string>|null */
    private ?array $activities = null;

    /** @var list<array{name: string, range: string, serial_number: string}>|null */
    private ?array $standards = null;

    /** @var list<array{label: string, tolerance: ?string, rows: list<array{standard_value: string, average_value: string, correction_value: string, unit: string}>}>|null */
    private ?array $testResultGroups = null;

    public function __construct(public Certificate $certificate) {}

    public static function from(Certificate $certificate): self
    {
        $certificate->load([
            'calibration' => function (BelongsTo $query): void {
                $query->select([
                    'calibration_id',
                    'instrument_id',
                    'customer_id',
                    'action_date',
                    'resume',
                ]);
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
            'calibration.calibrationScopes' => function (HasMany $query): void {
                $query
                    ->select([
                        'calibration_scope_id',
                        'calibration_id',
                        'instrument_type_id',
                        'test_profile_id',
                        'scope_order',
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
                    'serial_number',
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
            'calibration.activities' => function (HasMany $query): void {
                $query
                    ->select([
                        'calibration_activity_id',
                        'calibration_id',
                        'activity_description',
                        'activity_order',
                    ])
                    ->orderBy('activity_order')
                    ->orderBy('calibration_activity_id');
            },
            'calibration.standardUsages' => function (HasMany $query): void {
                $query
                    ->select([
                        'calibration_standard_usage_id',
                        'calibration_id',
                        'standard_instrument_id',
                        'usage_order',
                        'standard_range',
                        'standard_unit',
                    ])
                    ->orderBy('usage_order')
                    ->orderBy('calibration_standard_usage_id');
            },
            'calibration.standardUsages.instrument' => function (BelongsTo $query): void {
                $query->select([
                    'instrument_id',
                    'instrument_name',
                ]);
            },
            'calibration.standardUsages.instrument.instrumentTypes' => function (HasMany $query): void {
                $query->select([
                    'instrument_type_id',
                    'instrument_id',
                    'serial_number',
                ]);
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

    public function customerName(): string
    {
        return $this->text($this->certificate->calibration->customer?->customer_name);
    }

    public function customerAddress(): string
    {
        return $this->text($this->certificate->calibration->customer?->address);
    }

    public function instrumentName(): string
    {
        return $this->text($this->certificate->calibration->instrument->instrument_name);
    }

    public function location(): string
    {
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

    /**
     * @return list<array{type_name: string, brand: string, model: string, serial_number: string, max_capacity: ?string, resolution: ?string}>
     */
    public function instrumentRows(): array
    {
        if ($this->instrumentRows !== null) {
            return $this->instrumentRows;
        }

        $rows = [];

        foreach ($this->certificate->calibration->calibrationScopes as $scope) {
            $type = $scope->instrumentType;

            $rows[] = [
                'type_name' => $this->text($type->type_name),
                'brand' => $this->text($type->brand),
                'model' => $this->text($type->model),
                'serial_number' => $this->text($type->serial_number),
                'max_capacity' => $this->measurement($type->max_capacity, $type->max_capacity_unit),
                'resolution' => $this->measurement($type->resolution, $type->resolution_unit),
            ];
        }

        $this->instrumentRows = $rows;

        return $this->instrumentRows;
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
        if ($this->activities !== null) {
            return $this->activities;
        }

        $this->activities = array_values(
            $this->certificate->calibration->activities
                ->map(fn ($activity): string => $this->text($activity->activity_description))
                ->all()
        );

        return $this->activities;
    }

    /**
     * @return list<array{name: string, range: string, serial_number: string}>
     */
    public function standards(): array
    {
        if ($this->standards !== null) {
            return $this->standards;
        }

        $rows = [];

        foreach ($this->certificate->calibration->standardUsages as $usage) {
            $rows[] = [
                'name' => $this->text($usage->instrument->instrument_name),
                'range' => $this->range($usage),
                'serial_number' => $this->standardSerialNumber($usage->instrument),
            ];
        }

        $this->standards = $rows;

        return $this->standards;
    }

    /**
     * @return list<array{label: string, tolerance: ?string, rows: list<array{standard_value: string, average_value: string, correction_value: string, unit: string}>}>
     */
    public function testResultGroups(): array
    {
        if ($this->testResultGroups !== null) {
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
                'tolerance' => $this->correctionTolerance($scope),
                'rows' => $rows,
            ];
        }

        $this->testResultGroups = $groups;

        return $this->testResultGroups;
    }

    public function resume(): string
    {
        return $this->text($this->certificate->calibration->resume);
    }

    private function standardSerialNumber(?Instrument $instrument): string
    {
        if ($instrument === null) {
            return '—';
        }

        $serialNumbers = $instrument->instrumentTypes
            ->map(fn ($type): ?string => $type->serial_number)
            ->filter(fn (?string $serialNumber): bool => filled($serialNumber))
            ->unique()
            ->values();

        if ($serialNumbers->count() !== 1) {
            return '—';
        }

        return (string) $serialNumbers->first();
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

        return filled($unit) ? $value.' '.$unit : $value;
    }

    private function text(?string $value): string
    {
        return filled($value) ? $value : '—';
    }
}
