<?php

use App\Models\Calibration;
use App\Models\TestPoint;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Livewire\Component;

new class extends Component
{
    public Calibration $calibration;

    public function mount(Calibration $calibration): void
    {
        $this->calibration = $calibration;

        $this->calibration->load([
            'calibrationScopes' => function (HasMany $query): void {
                $query
                    ->select([
                        'calibration_scope_id',
                        'calibration_id',
                        'instrument_type_id',
                        'test_profile_id',
                        'scope_order',
                        'remarks',
                        'created_at',
                    ])
                    ->orderBy('scope_order')
                    ->orderBy('calibration_scope_id');
            },
            'calibrationScopes.instrumentType' => function (BelongsTo $query): void {
                $query->select([
                    'instrument_type_id',
                    'type_name',
                ]);
            },
            'calibrationScopes.testProfile' => function (BelongsTo $query): void {
                $query->select([
                    'test_profile_id',
                    'instrument_type_id',
                    'profile_name',
                    'description',
                    'created_at',
                ]);
            },
            'calibrationScopes.testProfile.testPoints' => function (HasMany $query): void {
                $query
                    ->select([
                        'test_point_id',
                        'test_profile_id',
                        'point_order',
                        'nominal_value',
                        'unit',
                        'tolerance_plus',
                        'tolerance_minus',
                        'description',
                        'created_at',
                    ])
                    ->orderBy('point_order')
                    ->orderBy('test_point_id');
            },
            'calibrationScopes.calibrationTestResults' => function (HasMany $query): void {
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
            'calibrationScopes.calibrationTestResults.testPoint' => function (BelongsTo $query): void {
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
            'calibrationScopes.calibrationTestResults.calibrationReadings' => function (HasMany $query): void {
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
            'environment' => function (HasOne $query): void {
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
            'activities' => function (HasMany $query): void {
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
            'standardUsages' => function (HasMany $query): void {
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
            'standardUsages.instrument' => function (BelongsTo $query): void {
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
    <main class="mx-auto flex max-w-7xl flex-col gap-6">
        <header class="flex flex-col gap-3">
            <a href="{{ route('instruments.show', $calibration->instrument_id) }}" class="w-fit text-sm font-medium text-blue-600 hover:text-blue-700 hover:underline dark:text-blue-400 dark:hover:text-blue-300">
                &larr; Back to instrument
            </a>

            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $calibration->calibration_number }}</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Read-only calibration information and scope.
                </p>
            </div>
        </header>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h2 class="text-lg font-semibold">Calibration Information</h2>
            </div>

            <dl class="grid gap-x-6 gap-y-5 px-5 py-5 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Calibration ID</dt>
                    <dd class="mt-1 font-mono text-sm">{{ $calibration->calibration_id }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Calibration Number</dt>
                    <dd class="mt-1 text-sm font-medium">{{ $calibration->calibration_number }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</dt>
                    <dd class="mt-1">
                        @if (is_null($calibration->calibration_due))
                            —
                        @elseif (today()->gt($calibration->calibration_due))
                            <span class="inline-flex rounded-full bg-red-100 px-2 py-1 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-300">
                                Overdue
                            </span>
                        @else
                            <span class="inline-flex rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-700 dark:bg-green-950 dark:text-green-300">
                                On Track
                            </span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Calibration Date</dt>
                    <dd class="mt-1 text-sm">{{ $calibration->calibration_date?->format('Y-m-d') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Calibration Due</dt>
                    <dd class="mt-1 text-sm">{{ $calibration->calibration_due?->format('Y-m-d') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Calibration Method</dt>
                    <dd class="mt-1 text-sm">{{ $calibration->calibration_method ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Calibration Result</dt>
                    <dd class="mt-1 text-sm">{{ $calibration->calibration_result ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Certificate Number</dt>
                    <dd class="mt-1 text-sm">{{ $calibration->certificate_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Action Date</dt>
                    <dd class="mt-1 text-sm">{{ $calibration->action_date?->format('Y-m-d') ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Remarks</dt>
                    <dd class="mt-1 text-sm">{{ $calibration->remarks ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Resume</dt>
                    <dd class="mt-1 text-sm">{{ $calibration->resume ?? '—' }}</dd>
                </div>
            </dl>
        </section>

        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h2 class="text-lg font-semibold">Environment</h2>
            </div>

            @if ($calibration->environment)
                <dl class="grid gap-x-6 gap-y-5 px-5 py-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Temperature</dt>
                        <dd class="mt-1 text-sm">
                            @if (is_null($calibration->environment->temperature_value))
                                —
                            @else
                                {{ $calibration->environment->temperature_value }}{{ filled($calibration->environment->temperature_unit) ? ' '.$calibration->environment->temperature_unit : '' }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Humidity</dt>
                        <dd class="mt-1 text-sm">
                            @if (is_null($calibration->environment->humidity_value))
                                —
                            @else
                                {{ $calibration->environment->humidity_value }}{{ filled($calibration->environment->humidity_unit) ? ' '.$calibration->environment->humidity_unit : '' }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Pressure</dt>
                        <dd class="mt-1 text-sm">
                            @if (is_null($calibration->environment->pressure_value))
                                —
                            @else
                                {{ $calibration->environment->pressure_value }}{{ filled($calibration->environment->pressure_unit) ? ' '.$calibration->environment->pressure_unit : '' }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Remarks</dt>
                        <dd class="mt-1 text-sm">{{ $calibration->environment->remarks ?? '—' }}</dd>
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
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Activity Type</th>
                            <th scope="col" class="min-w-64 px-4 py-3">Description</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Result</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @forelse ($calibration->activities as $activity)
                            <tr wire:key="calibration-activity-{{ $activity->calibration_activity_id }}">
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
                <h2 class="text-lg font-semibold">Standard Used</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead class="bg-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        <tr>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">No.</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Standard Instrument</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Serial Number</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Asset Number</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Usage Purpose</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Standard Range</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Unit</th>
                            <th scope="col" class="min-w-64 px-4 py-3">Remarks</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @forelse ($calibration->standardUsages as $standardUsage)
                            <tr wire:key="standard-usage-{{ $standardUsage->calibration_standard_usage_id }}">
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">
                                    {{ $standardUsage->usage_order }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium">
                                    {{ $standardUsage->instrument?->instrument_name ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">—</td>
                                <td class="whitespace-nowrap px-4 py-3">—</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $standardUsage->usage_purpose ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $standardUsage->standard_range ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $standardUsage->standard_unit ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $standardUsage->remarks ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
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
                <h2 class="text-lg font-semibold">Calibration Scope</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead class="bg-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        <tr>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Scope Order</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Instrument Type</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Test Profile ID</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Remarks</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @forelse ($calibration->calibrationScopes as $calibrationScope)
                            <tr wire:key="calibration-scope-{{ $calibrationScope->calibration_scope_id }}" class="align-top">
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">
                                    {{ $calibrationScope->scope_order }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="font-medium">{{ $calibrationScope->instrumentType?->type_name ?? '—' }}</span>
                                    @if ($calibrationScope->instrumentType)
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                                            ID: {{ $calibrationScope->instrumentType->instrument_type_id }}
                                        </span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $calibrationScope->test_profile_id ?? '—' }}</td>
                                <td class="min-w-64 px-4 py-3">{{ $calibrationScope->remarks ?? '—' }}</td>
                            </tr>
                            <tr wire:key="calibration-scope-details-{{ $calibrationScope->calibration_scope_id }}">
                                <td colspan="4" class="bg-gray-50 px-4 py-4 dark:bg-gray-800">
                                    <div class="mb-4">
                                        <h3 class="text-sm font-semibold text-gray-950 dark:text-gray-100">
                                            Test Profile:
                                            {{ $calibrationScope->testProfile?->profile_name ?? '—' }}
                                        </h3>
                                        @if ($calibrationScope->testProfile)
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                ID: {{ $calibrationScope->testProfile->test_profile_id }}
                                                &middot;
                                                {{ $calibrationScope->testProfile->description ?? '—' }}
                                            </p>
                                        @endif
                                    </div>

                                    @if ($calibrationScope->testProfile)
                                        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                                            <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                                                <thead class="bg-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                                    <tr>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Point</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Nominal</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Unit</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">+Tol</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">-Tol</th>
                                                        <th scope="col" class="min-w-64 px-3 py-2">Description</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                                    @forelse ($calibrationScope->testProfile->testPoints as $testPoint)
                                                        <tr wire:key="test-point-{{ $testPoint->test_point_id }}">
                                                            <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-gray-600 dark:text-gray-400">
                                                                {{ $testPoint->point_order }}
                                                            </td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $testPoint->nominal_value ?? '—' }}</td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $testPoint->unit ?? '—' }}</td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $testPoint->tolerance_plus ?? '—' }}</td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $testPoint->tolerance_minus ?? '—' }}</td>
                                                            <td class="px-3 py-2">{{ $testPoint->description ?? '—' }}</td>
                                                        </tr>
                                                    @empty
                                                        <tr>
                                                            <td colspan="6" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">
                                                                No test points found.
                                                            </td>
                                                        </tr>
                                                    @endforelse
                                                </tbody>
                                            </table>
                                        </div>
                                    @endif

                                    <div class="mt-6">
                                        <h4 class="mb-2 text-sm font-semibold text-gray-950 dark:text-gray-100">Test Results</h4>

                                        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                                            <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                                                <thead class="bg-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                                    <tr>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Point</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Nominal</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Unit</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Result</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Standard</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Average</th>
                                                        <th scope="col" class="whitespace-nowrap px-3 py-2">Correction</th>
                                                        <th scope="col" class="min-w-64 px-3 py-2">Remarks</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                                    @forelse ($calibrationScope->calibrationTestResults as $calibrationTestResult)
                                                        <tr wire:key="test-result-{{ $calibrationTestResult->calibration_test_result_id }}">
                                                            <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-gray-600 dark:text-gray-400">
                                                                {{ $calibrationTestResult->testPoint?->point_order ?? '—' }}
                                                            </td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $calibrationTestResult->testPoint?->nominal_value ?? '—' }}</td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $calibrationTestResult->testPoint?->unit ?? '—' }}</td>
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
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $calibrationTestResult->standard_value ?? '—' }}</td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $calibrationTestResult->average_value ?? '—' }}</td>
                                                            <td class="whitespace-nowrap px-3 py-2">{{ $calibrationTestResult->correction_value ?? '—' }}</td>
                                                            <td class="px-3 py-2">{{ $calibrationTestResult->remarks ?? '—' }}</td>
                                                        </tr>
                                                        <tr wire:key="test-result-readings-{{ $calibrationTestResult->calibration_test_result_id }}">
                                                            <td colspan="8" class="bg-gray-50 px-3 py-3 dark:bg-gray-950">
                                                                <h5 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                                                                    Readings
                                                                </h5>
                                                                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                                                                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                                                                        <thead class="bg-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                                                            <tr>
                                                                                <th scope="col" class="whitespace-nowrap px-3 py-2">Reading</th>
                                                                                <th scope="col" class="whitespace-nowrap px-3 py-2">Reference</th>
                                                                                <th scope="col" class="whitespace-nowrap px-3 py-2">Instrument</th>
                                                                                <th scope="col" class="whitespace-nowrap px-3 py-2">Error</th>
                                                                                <th scope="col" class="whitespace-nowrap px-3 py-2">Uncertainty</th>
                                                                                <th scope="col" class="whitespace-nowrap px-3 py-2">Unit</th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                                                            @forelse ($calibrationTestResult->calibrationReadings as $calibrationReading)
                                                                                <tr wire:key="calibration-reading-{{ $calibrationReading->calibration_reading_id }}">
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
                                                            <td colspan="8" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">
                                                                No test results found.
                                                            </td>
                                                        </tr>
                                                    @endforelse
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                    No calibration scopes found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>