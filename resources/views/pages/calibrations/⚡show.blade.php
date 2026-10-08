<?php

use App\Models\Calibration;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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