<?php

use App\Models\DetailLocation;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public int $perPage = 10;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(mixed $value): void
    {
        $perPage = (int) $value;

        $this->perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;
        $this->resetPage();
    }

    /**
     * @return array{instruments: int, types: int, locations: int}
     */
    #[Computed]
    public function totals(): array
    {
        return [
            'instruments' => Instrument::query()->count(),
            'types' => InstrumentType::query()->count(),
            'locations' => $this->filledLocationCount(),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Instrument>
     */
    #[Computed]
    public function instruments(): LengthAwarePaginator
    {
        $needle = mb_substr(trim($this->search), 0, 100);

        return Instrument::query()
            ->select([
                'instrument.instrument_id',
                'instrument.instrument_name',
                'instrument.detail_location_id',
                'instrument_type.instrument_type_id',
                'instrument_type.type_name',
                'instrument_type.brand',
                'instrument_type.model',
                'instrument_type.serial_number',
                'instrument_type.asset_number',
            ])
            ->leftJoin('instrument_type', 'instrument_type.instrument_id', '=', 'instrument.instrument_id')
            ->with([
                'detailLocation:detail_location_id,location_id,detail_location_name',
                'detailLocation.location:location_id,location_name',
            ])
            ->when($needle !== '', function (Builder $query) use ($needle): void {
                $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $pattern = '%'.addcslashes($needle, '\\%_').'%';

                $query->whereRaw("instrument.instrument_name {$like} ? escape '\\'", [$pattern]);
            })
            ->orderBy('instrument.instrument_id')
            ->orderBy('instrument_type.instrument_type_id')
            ->paginate($this->perPage);
    }

    /**
     * @return array{key: string, brand: string, type: string, model: string, serial_number: string, asset_number: string, location: string, detail_location: string}
     */
    public function row(Instrument $instrument): array
    {
        $location = $instrument->detailLocation?->location?->location_name;
        $detailLocation = $instrument->detailLocation?->detail_location_name;
        $typeId = $instrument->getAttribute('instrument_type_id');

        return [
            'key' => $instrument->instrument_id.'-'.($typeId ?? 'none'),
            'brand' => $this->display($instrument->getAttribute('brand')),
            'type' => $this->display($instrument->getAttribute('type_name')),
            'model' => $this->display($instrument->getAttribute('model')),
            'serial_number' => $this->display($instrument->getAttribute('serial_number')),
            'asset_number' => $this->display($instrument->getAttribute('asset_number')),
            'location' => $this->display($location),
            'detail_location' => $this->display($detailLocation),
        ];
    }

    public function detailUrl(Instrument $instrument): string
    {
        $typeId = $instrument->getAttribute('instrument_type_id');

        if ($typeId === null) {
            return route('instruments.show', $instrument);
        }

        return route('instruments.types.show', [
            'instrument' => $instrument->instrument_id,
            'instrumentType' => $typeId,
        ]);
    }

    private function display(mixed $value): string
    {
        return filled($value) ? (string) $value : '—';
    }

    private function filledLocationCount(): int
    {
        return Location::query()
            ->whereNotNull('location_name')
            ->whereRaw("trim(location_name) <> ''")
            ->whereIn('location_id', DetailLocation::query()
                ->select('location_id')
                ->whereIn(
                    'detail_location_id',
                    Instrument::query()->select('detail_location_id')->whereNotNull('detail_location_id'),
                ))
            ->count();
    }
};
?>

<div class="px-4 py-8 sm:px-6 lg:px-10">
    <div class="mx-auto flex max-w-6xl flex-col gap-8">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight text-navy">Instruments</h1>
            <p class="mt-1 text-sm text-muted">Browse instruments by name, type, and location.</p>
        </header>

        <section aria-label="Instrument totals" class="grid gap-4 sm:grid-cols-3">
            @foreach ([
                ['label' => 'Instruments', 'value' => $this->totals['instruments'], 'surface' => 'bg-on-track shadow-[0_16px_28px_-16px_rgb(31_122_77/0.75)]'],
                ['label' => 'Types', 'value' => $this->totals['types'], 'surface' => 'bg-overdue shadow-[0_16px_28px_-16px_rgb(180_35_24/0.75)]'],
                ['label' => 'Locations', 'value' => $this->totals['locations'], 'surface' => 'bg-due-soon shadow-[0_16px_28px_-16px_rgb(138_90_0/0.75)]'],
            ] as $card)
                <article class="relative overflow-hidden rounded-2xl p-5 text-white transition duration-200 hover:-translate-y-0.5 {{ $card['surface'] }}">
                    <span class="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/25 via-transparent to-black/15" aria-hidden="true"></span>
                    <div class="relative">
                        <p class="text-sm font-medium text-white/80">{{ $card['label'] }}</p>
                        <p class="mt-3 text-4xl font-semibold tracking-tight tabular-nums">{{ $card['value'] }}</p>
                    </div>
                </article>
            @endforeach
        </section>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="instruments-heading">
            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 id="instruments-heading" class="text-base font-semibold tracking-tight text-navy">Instrument List</h2>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <label for="instrument-search" class="sr-only">Search by name</label>
                    <input
                        id="instrument-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search by name"
                        class="ui-input sm:w-64"
                    >
                    <label for="instrument-page-size" class="sr-only">Rows per page</label>
                    <select id="instrument-page-size" wire:model.live="perPage" class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-navy">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                </div>
            </div>

            @if ($this->instruments->isEmpty())
                <p class="px-5 py-8 text-sm text-navy/70">No instruments found.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead class="bg-navy text-xs font-medium text-white">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-medium">Instrument Name</th>
                                <th scope="col" class="px-5 py-3 font-medium">Brand</th>
                                <th scope="col" class="px-5 py-3 font-medium">Type</th>
                                <th scope="col" class="px-5 py-3 font-medium">Model</th>
                                <th scope="col" class="px-5 py-3 font-medium">Serial Number</th>
                                <th scope="col" class="px-5 py-3 font-medium">Asset Number</th>
                                <th scope="col" class="px-5 py-3 font-medium">Location</th>
                                <th scope="col" class="px-5 py-3 font-medium">Detail Location</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/70">
                            @foreach ($this->instruments as $instrument)
                                @php
                                    $row = $this->row($instrument);
                                @endphp
                                <tr wire:key="instrument-{{ $row['key'] }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec] hover:bg-[#edf4fa]">
                                    <td class="px-5 py-3.5 font-medium">
                                        <a href="{{ $this->detailUrl($instrument) }}" class="text-navy hover:text-brand">
                                            {{ $instrument->instrument_name }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-3.5 text-navy">{{ $row['brand'] }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ $row['type'] }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ $row['model'] }}</td>
                                    <td class="px-5 py-3.5 text-navy/70">{{ $row['serial_number'] }}</td>
                                    <td class="px-5 py-3.5 text-navy/70">{{ $row['asset_number'] }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ $row['location'] }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ $row['detail_location'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="px-5 py-4">
                    {{ $this->instruments->links() }}
                </div>
            @endif
        </section>
    </div>
</div>
