<?php

use App\Models\Instrument;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /** @return Collection<int, Instrument> */
    #[Computed]
    public function instruments(): Collection
    {
        return Instrument::query()
            ->select([
                'instrument_id',
                'instrument_name',
                'detail_location_id',
                'description',
                'created_at',
            ])
            ->orderBy('instrument_id')
            ->get();
    }
};
?>

<div class="min-h-screen bg-gray-50 px-4 py-8 text-gray-950 sm:px-6 lg:px-8 dark:bg-gray-950 dark:text-gray-100">
    <main class="mx-auto flex max-w-7xl flex-col gap-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Instruments</h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Read-only instrument data from the calibration database.
            </p>
            <div class="mt-3 flex flex-wrap gap-4">
                <a href="{{ route('instruments.export.csv') }}" class="text-sm font-medium text-brand hover:text-navy">
                    Export instrument types (CSV)
                </a>
                <a href="{{ route('calibrations.export.csv') }}" class="text-sm font-medium text-brand hover:text-navy">
                    Export calibration history (CSV)
                </a>
            </div>
        </header>

        <div class="ui-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-800">
                    <thead class="bg-brand-soft text-xs font-medium text-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3">Instrument ID</th>
                            <th scope="col" class="px-4 py-3">Instrument Name</th>
                            <th scope="col" class="px-4 py-3">Detail Location ID</th>
                            <th scope="col" class="px-4 py-3">Description</th>
                            <th scope="col" class="px-4 py-3">Created At</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @forelse ($this->instruments as $instrument)
                            <tr wire:key="instrument-{{ $instrument->instrument_id }}" class="align-top">
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">
                                    {{ $instrument->instrument_id }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium">
                                    <a href="{{ route('instruments.show', $instrument) }}" class="text-brand hover:text-navy">
                                        {{ $instrument->instrument_name }}
                                    </a>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300">
                                    {{ $instrument->detail_location_id ?? '—' }}
                                </td>
                                <td class="min-w-64 px-4 py-3 text-gray-700 dark:text-gray-300">
                                    {{ $instrument->description ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300">
                                    {{ $instrument->created_at->format('Y-m-d H:i:s') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                    No instruments found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>