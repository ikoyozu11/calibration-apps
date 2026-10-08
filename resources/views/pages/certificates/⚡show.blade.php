<?php

use App\Models\Certificate;
use App\Models\TestPoint;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Livewire\Component;

new class extends Component
{
    public Certificate $certificate;

    public function mount(Certificate $certificate): void
    {
        $this->certificate = $certificate;

        $this->certificate->load([
            'calibration' => function (BelongsTo $query): void {
                $query->select([
                    'calibration_id',
                    'instrument_id',
                    'customer_id',
                    'calibration_number',
                    'calibration_date',
                    'calibration_due',
                    'calibration_method',
                    'calibration_result',
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
                    'asset_number',
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
                        'result_status',
                        'remarks',
                        'standard_value',
                        'average_value',
                        'correction_value',
                        'created_at',
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
                    'nominal_value',
                    'unit',
                    'tolerance_plus',
                    'tolerance_minus',
                    'description',
                    'created_at',
                ]);
            },
            'calibration.calibrationScopes.calibrationTestResults.calibrationReadings' => function (HasMany $query): void {
                $query
                    ->select([
                        'calibration_reading_id',
                        'calibration_test_result_id',
                        'reading_order',
                        'reference_value',
                        'instrument_value',
                        'error_value',
                        'uncertainty_value',
                        'unit',
                        'created_at',
                    ])
                    ->orderBy('reading_order')
                    ->orderBy('calibration_reading_id');
            },
            'calibration.environment' => function (HasOne $query): void {
                $query->select([
                    'calibration_environment_id',
                    'calibration_id',
                    'temperature_value',
                    'temperature_unit',
                    'humidity_value',
                    'humidity_unit',
                    'pressure_value',
                    'pressure_unit',
                    'remarks',
                    'created_at',
                ]);
            },
            'calibration.activities' => function (HasMany $query): void {
                $query
                    ->select([
                        'calibration_activity_id',
                        'calibration_id',
                        'activity_type',
                        'activity_description',
                        'activity_result',
                        'activity_order',
                        'created_at',
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
                        'usage_purpose',
                        'remarks',
                        'standard_range',
                        'standard_unit',
                        'created_at',
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
        ]);
    }
};
?>

<div class="min-h-screen bg-gray-50 px-4 py-8 text-gray-950 sm:px-6 lg:px-8 dark:bg-gray-950 dark:text-gray-100">
    <main class="mx-auto flex max-w-4xl flex-col gap-6">
        <header class="flex flex-col gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Certificate Preview</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Read-only certificate information.
                </p>
            </div>
        </header>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h2 class="text-lg font-semibold">Certificate Information</h2>
            </div>

            <dl class="grid gap-x-6 gap-y-5 px-5 py-5 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Certificate Number</dt>
                    <dd class="mt-1 text-sm font-medium">{{ $certificate->certificate_number }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Certificate Type</dt>
                    <dd class="mt-1 text-sm">{{ $certificate->certificate_type }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Issued Date</dt>
                    <dd class="mt-1 text-sm">{{ $certificate->issued_date->format('j F Y') }}</dd>
                </div>
            </dl>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h2 class="text-lg font-semibold">Customer</h2>
            </div>

            <dl class="grid gap-x-6 gap-y-5 px-5 py-5 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Customer Name</dt>
                    <dd class="mt-1 text-sm font-medium">{{ $certificate->calibration?->customer?->customer_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Address</dt>
                    <dd class="mt-1 text-sm">{{ $certificate->calibration?->customer?->address ?? '—' }}</dd>
                </div>
            </dl>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h2 class="text-lg font-semibold">Instrument Identification</h2>
            </div>

            <dl class="grid gap-x-6 gap-y-5 px-5 py-5 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Instrument Name</dt>
                    <dd class="mt-1 text-sm font-medium">{{ $certificate->calibration?->instrument?->instrument_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Location</dt>
                    <dd class="mt-1 text-sm">
                        {{ collect([
                            $certificate->calibration?->instrument?->detailLocation?->location?->location_code,
                            $certificate->calibration?->instrument?->detailLocation?->detail_location_code,
                        ])->filter()->implode(' / ') ?: '—' }}
                    </dd>
                </div>
            </dl>

            <div class="overflow-x-auto border-t border-gray-200 dark:border-gray-800">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead class="bg-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        <tr>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Type</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Brand</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Model</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Serial Number</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Asset Number</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @forelse ($certificate->calibration?->calibrationScopes ?? [] as $calibrationScope)
                            <tr wire:key="certificate-scope-type-{{ $calibrationScope->calibration_scope_id }}">
                                <td class="whitespace-nowrap px-4 py-3 font-medium">{{ $calibrationScope->instrumentType?->type_name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $calibrationScope->instrumentType?->brand ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $calibrationScope->instrumentType?->model ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $calibrationScope->instrumentType?->serial_number ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $calibrationScope->instrumentType?->asset_number ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                    No instrument types found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h2 class="text-lg font-semibold">Calibration Information</h2>
            </div>

            <dl class="grid gap-x-6 gap-y-5 px-5 py-5 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Calibration Number</dt>
                    <dd class="mt-1 text-sm font-medium">{{ $certificate->calibration?->calibration_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Calibration Date</dt>
                    <dd class="mt-1 text-sm">{{ $certificate->calibration?->calibration_date?->format('j F Y') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Calibration Due</dt>
                    <dd class="mt-1 text-sm">{{ $certificate->calibration?->calibration_due?->format('j F Y') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Calibration Method</dt>
                    <dd class="mt-1 text-sm">{{ $certificate->calibration?->calibration_method ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Calibration Result</dt>
                    <dd class="mt-1 text-sm">{{ $certificate->calibration?->calibration_result ?? '—' }}</dd>
                </div>
            </dl>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h2 class="text-lg font-semibold">Environment</h2>
            </div>

            @if ($certificate->calibration?->environment)
                <dl class="grid gap-x-6 gap-y-5 px-5 py-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Temperature</dt>
                        <dd class="mt-1 text-sm">
                            @if (is_null($certificate->calibration->environment->temperature_value))
                                —
                            @else
                                {{ $certificate->calibration->environment->temperature_value }}{{ filled($certificate->calibration->environment->temperature_unit) ? ' '.$certificate->calibration->environment->temperature_unit : '' }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Humidity</dt>
                        <dd class="mt-1 text-sm">
                            @if (is_null($certificate->calibration->environment->humidity_value))
                                —
                            @else
                                {{ $certificate->calibration->environment->humidity_value }}{{ filled($certificate->calibration->environment->humidity_unit) ? ' '.$certificate->calibration->environment->humidity_unit : '' }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Pressure</dt>
                        <dd class="mt-1 text-sm">
                            @if (is_null($certificate->calibration->environment->pressure_value))
                                —
                            @else
                                {{ $certificate->calibration->environment->pressure_value }}{{ filled($certificate->calibration->environment->pressure_unit) ? ' '.$certificate->calibration->environment->pressure_unit : '' }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Remarks</dt>
                        <dd class="mt-1 text-sm">{{ $certificate->calibration->environment->remarks ?? '—' }}</dd>
                    </div>
                </dl>
            @else
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No environment data found.
                </p>
            @endif
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h2 class="text-lg font-semibold">Activity</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead class="bg-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        <tr>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">No.</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Type</th>
                            <th scope="col" class="min-w-64 px-4 py-3">Description</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Result</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @forelse ($certificate->calibration?->activities ?? [] as $activity)
                            <tr wire:key="certificate-activity-{{ $activity->calibration_activity_id }}">
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">
                                    {{ $activity->activity_order }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium">{{ $activity->activity_type }}</td>
                                <td class="px-4 py-3">{{ $activity->activity_description ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $activity->activity_result ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                    No activities found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h2 class="text-lg font-semibold">Standard(s) Used</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead class="bg-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        <tr>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">No.</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Instrument</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Purpose</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Range</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Unit</th>
                            <th scope="col" class="min-w-64 px-4 py-3">Remarks</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @forelse ($certificate->calibration?->standardUsages ?? [] as $standardUsage)
                            <tr wire:key="certificate-standard-{{ $standardUsage->calibration_standard_usage_id }}">
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">
                                    {{ $standardUsage->usage_order }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium">
                                    {{ $standardUsage->instrument?->instrument_name ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $standardUsage->usage_purpose ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $standardUsage->standard_range ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $standardUsage->standard_unit ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $standardUsage->remarks ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                    No standards used.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h2 class="text-lg font-semibold">Test Result</h2>
            </div>

            @forelse ($certificate->calibration?->calibrationScopes ?? [] as $calibrationScope)
                <div wire:key="certificate-test-result-scope-{{ $calibrationScope->calibration_scope_id }}" class="border-b border-gray-200 px-5 py-5 last:border-b-0 dark:border-gray-800">
                    <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-gray-100">
                        {{ $calibrationScope->instrumentType?->type_name ?? '—' }}
                        @if ($calibrationScope->testProfile)
                            <span class="font-normal text-gray-500 dark:text-gray-400">
                                · {{ $calibrationScope->testProfile->profile_name }}
                            </span>
                        @endif
                    </h3>

                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                        <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                            <thead class="bg-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                <tr>
                                    <th scope="col" class="whitespace-nowrap px-3 py-2">Test Point / Nominal</th>
                                    <th scope="col" class="whitespace-nowrap px-3 py-2">Unit</th>
                                    <th scope="col" class="whitespace-nowrap px-3 py-2">Standard Value</th>
                                    <th scope="col" class="whitespace-nowrap px-3 py-2">Average Value</th>
                                    <th scope="col" class="whitespace-nowrap px-3 py-2">Correction</th>
                                    <th scope="col" class="whitespace-nowrap px-3 py-2">Result</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                @forelse ($calibrationScope->calibrationTestResults as $calibrationTestResult)
                                    <tr wire:key="certificate-test-result-{{ $calibrationTestResult->calibration_test_result_id }}">
                                        <td class="whitespace-nowrap px-3 py-2">
                                            {{ $calibrationTestResult->testPoint?->point_order ?? '—' }}
                                            /
                                            {{ $calibrationTestResult->testPoint?->nominal_value ?? '—' }}
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2">{{ $calibrationTestResult->testPoint?->unit ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-2">{{ $calibrationTestResult->standard_value ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-2">{{ $calibrationTestResult->average_value ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-2">{{ $calibrationTestResult->correction_value ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-2">
                                            @if (is_null($calibrationTestResult->result_status))
                                                —
                                            @elseif ($calibrationTestResult->result_status === 'PASS')
                                                <span class="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700 dark:bg-green-950 dark:text-green-300">
                                                    {{ $calibrationTestResult->result_status }}
                                                </span>
                                            @elseif ($calibrationTestResult->result_status === 'FAIL')
                                                <span class="inline-flex rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-300">
                                                    {{ $calibrationTestResult->result_status }}
                                                </span>
                                            @else
                                                {{ $calibrationTestResult->result_status }}
                                            @endif
                                        </td>
                                    </tr>
                                    <tr wire:key="certificate-test-result-readings-{{ $calibrationTestResult->calibration_test_result_id }}">
                                        <td colspan="6" class="bg-gray-50 px-3 py-3 dark:bg-gray-950">
                                            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                                                Readings
                                            </h4>
                                            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                                                <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                                                    <thead class="bg-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                                        <tr>
                                                            <th scope="col" class="whitespace-nowrap px-3 py-2">Reading No.</th>
                                                            <th scope="col" class="whitespace-nowrap px-3 py-2">Reference Value</th>
                                                            <th scope="col" class="whitespace-nowrap px-3 py-2">Instrument Value</th>
                                                            <th scope="col" class="whitespace-nowrap px-3 py-2">Error Value</th>
                                                            <th scope="col" class="whitespace-nowrap px-3 py-2">Uncertainty Value</th>
                                                            <th scope="col" class="whitespace-nowrap px-3 py-2">Unit</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                                        @forelse ($calibrationTestResult->calibrationReadings as $calibrationReading)
                                                            <tr wire:key="certificate-reading-{{ $calibrationReading->calibration_reading_id }}">
                                                                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-gray-600 dark:text-gray-400">
                                                                    {{ $calibrationReading->reading_order }}
                                                                </td>
                                                                <td class="whitespace-nowrap px-3 py-2">{{ $calibrationReading->reference_value ?? '—' }}</td>
                                                                <td class="whitespace-nowrap px-3 py-2">{{ $calibrationReading->instrument_value ?? '—' }}</td>
                                                                <td class="whitespace-nowrap px-3 py-2">{{ $calibrationReading->error_value ?? '—' }}</td>
                                                                <td class="whitespace-nowrap px-3 py-2">{{ $calibrationReading->uncertainty_value ?? '—' }}</td>
                                                                <td class="whitespace-nowrap px-3 py-2">{{ $calibrationReading->unit ?? '—' }}</td>
                                                            </tr>
                                                        @empty
                                                            <tr>
                                                                <td colspan="6" class="px-3 py-4 text-center text-gray-500 dark:text-gray-400">
                                                                    No readings found.
                                                                </td>
                                                            </tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">
                                            No test results found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <p class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No test results found.
                </p>
            @endforelse
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h2 class="text-lg font-semibold">Resume</h2>
            </div>

            <p class="px-5 py-5 text-sm">
                {{ filled($certificate->calibration?->resume) ? $certificate->calibration->resume : '—' }}
            </p>
        </section>
    </main>
</div>
