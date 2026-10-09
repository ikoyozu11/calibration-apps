<?php

use App\Models\Instrument;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Livewire\Component;

new class extends Component
{
    public Instrument $instrument;

    public function mount(Instrument $instrument): void
    {
        $this->instrument = $instrument;

        $this->instrument->load([
            'calibrations' => function (HasMany $query): void {
                $query
                    ->select([
                        'calibration_id',
                        'instrument_id',
                        'calibration_number',
                        'calibration_date',
                        'calibration_due',
                        'calibration_method',
                        'calibration_result',
                        'certificate_number',
                        'remarks',
                        'action_date',
                        'resume',
                        'customer_id',
                        'calibration_provider_id',
                        'created_at',
                        'updated_at',
                    ])
                    ->orderByDesc('calibration_date')
                    ->orderByDesc('calibration_id');
            },
            'instrumentTypes' => function (HasMany $query): void {
                $query
                    ->select([
                        'instrument_type_id',
                        'instrument_id',
                        'type_name',
                        'serial_number',
                        'asset_number',
                        'brand',
                        'model',
                        'max_capacity',
                        'max_capacity_unit',
                        'resolution',
                        'resolution_unit',
                        'description',
                    ])
                    ->orderBy('instrument_type_id');
            },
        ]);
    }
};
?>

<div class="min-h-screen bg-gray-50 px-4 py-8 text-gray-950 sm:px-6 lg:px-8 dark:bg-gray-950 dark:text-gray-100">
    <main class="mx-auto flex max-w-7xl flex-col gap-6">
        <header class="flex flex-col gap-3">
            <a href="{{ route('instruments.index') }}" class="w-fit text-sm font-medium text-brand hover:text-navy">
                &larr; Back to instruments
            </a>

            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ $instrument->instrument_name }}</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    Read-only instrument identity, type, and calibration information.
                </p>
            </div>
        </header>

        <section class="ui-card overflow-hidden">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h2 class="text-lg font-semibold">Instrument Identity</h2>
            </div>

            <dl class="grid gap-x-6 gap-y-5 px-5 py-5 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium text-muted">Instrument ID</dt>
                    <dd class="mt-1 font-mono text-sm">{{ $instrument->instrument_id }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-muted">Instrument Name</dt>
                    <dd class="mt-1 text-sm font-medium">{{ $instrument->instrument_name }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-muted">Detail Location ID</dt>
                    <dd class="mt-1 text-sm">{{ $instrument->detail_location_id ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium text-muted">Description</dt>
                    <dd class="mt-1 text-sm">{{ $instrument->description ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-muted">Created At</dt>
                    <dd class="mt-1 text-sm">{{ $instrument->created_at->format('Y-m-d H:i:s') }}</dd>
                </div>
            </dl>
        </section>

        <section class="ui-card overflow-hidden">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h2 class="text-lg font-semibold">Instrument Types</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead class="bg-brand-soft text-xs font-medium text-navy">
                        <tr>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Type ID</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Type Name</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Serial Number</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Asset Number</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Brand</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Model</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Max Capacity</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Max Capacity Unit</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Resolution</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Resolution Unit</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Description</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @forelse ($instrument->instrumentTypes as $instrumentType)
                            <tr wire:key="instrument-type-{{ $instrumentType->instrument_type_id }}" class="align-top">
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">
                                    {{ $instrumentType->instrument_type_id }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium">{{ $instrumentType->type_name }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $instrumentType->serial_number ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $instrumentType->asset_number ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $instrumentType->brand ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $instrumentType->model ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $instrumentType->max_capacity ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $instrumentType->max_capacity_unit ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $instrumentType->resolution ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $instrumentType->resolution_unit ?? '—' }}</td>
                                <td class="min-w-64 px-4 py-3">{{ $instrumentType->description ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                    No instrument types found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="ui-card overflow-hidden">
            <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <h2 class="text-lg font-semibold">Calibration History</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead class="bg-brand-soft text-xs font-medium text-navy">
                        <tr>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Calibration Number</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Calibration Date</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Calibration Due</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Status</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Method</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Result</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Certificate Number</th>
                            <th scope="col" class="whitespace-nowrap px-4 py-3">Remarks</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @forelse ($instrument->calibrations as $calibration)
                            <tr wire:key="calibration-{{ $calibration->calibration_id }}" class="align-top">
                                <td class="whitespace-nowrap px-4 py-3 font-medium">
                                    <a href="{{ route('calibrations.show', $calibration) }}" class="text-brand hover:text-navy">
                                        {{ $calibration->calibration_number }}
                                    </a>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    {{ $calibration->calibration_date?->format('Y-m-d') ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    {{ $calibration->calibration_due?->format('Y-m-d') ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
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
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $calibration->calibration_method ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $calibration->calibration_result ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $calibration->certificate_number ?? '—' }}</td>
                                <td class="min-w-64 px-4 py-3">{{ $calibration->remarks ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                    No calibration history found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>