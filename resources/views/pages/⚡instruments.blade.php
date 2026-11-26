<?php

use App\Authorization\ApplicationCapability;
use App\CalibrationSessions\CalibrationSessionHttpRead;
use App\Models\DetailLocation;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $locationId = '';

    public int $perPage = 10;

    public function mount(): void
    {
        Gate::authorize(ApplicationCapability::InstrumentsView->value);
    }

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

    public function updatedLocationId(): void
    {
        $this->resetPage();
    }

    /** @return Collection<int, Location> */
    #[Computed]
    public function locations(): Collection
    {
        return Location::on($this->readConnectionName())
            ->select(['location_id', 'location_name'])
            ->whereNotNull('location_name')
            ->whereRaw("trim(location_name) <> ''")
            ->orderByRaw('lower(location_name)')
            ->orderBy('location_id')
            ->get();
    }

    /**
     * @return array{instruments: int, types: int, locations: int}
     */
    #[Computed]
    public function totals(): array
    {
        return [
            'instruments' => Instrument::on($this->readConnectionName())->count(),
            'types' => InstrumentType::on($this->readConnectionName())->count(),
            'locations' => $this->filledLocationCount(),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Instrument>
     */
    #[Computed]
    public function instruments(): LengthAwarePaginator
    {
        $query = Instrument::on($this->readConnectionName())
            ->select([
                'instrument.instrument_id',
                'instrument.instrument_name',
                'instrument.detail_location_id',
                'instrument_type.instrument_type_id',
                'instrument_type.type_name',
                'instrument_type.brand',
                'instrument_type.model',
                'instrument.serial_number',
                'instrument_type.asset_number',
            ])
            ->leftJoin('instrument_type', 'instrument_type.instrument_id', '=', 'instrument.instrument_id')
            ->with([
                'detailLocation:detail_location_id,location_id,detail_location_name',
                'detailLocation.location:location_id,location_name',
            ])
            ->when($this->locationId !== '', function (Builder $query): void {
                $query->whereHas('detailLocation', function (Builder $query): void {
                    $query->where('detail_location.location_id', (int) $this->locationId);
                });
            })
            ->whereIdentityMatches($this->search)
            ->orderBy('instrument.instrument_id')
            ->orderBy('instrument_type.instrument_type_id');

        return $query->paginate($this->perPage);
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
        $name = $this->readConnectionName();

        return Location::on($name)
            ->whereNotNull('location_name')
            ->whereRaw("trim(location_name) <> ''")
            ->whereIn('location_id', DetailLocation::on($name)
                ->select('location_id')
                ->whereIn(
                    'detail_location_id',
                    Instrument::on($name)->select('detail_location_id')->whereNotNull('detail_location_id'),
                ))
            ->count();
    }

    private function readConnectionName(): string
    {
        return app(CalibrationSessionHttpRead::class)->connectionName();
    }
};
?>

<div data-instruments-page class="px-4 py-8 sm:px-6 lg:px-10">
    <x-management-shell active="instruments">

        <section data-instruments-panel class="overflow-hidden rounded-2xl border border-line/80 bg-white shadow-[0_1px_2px_rgb(22_49_114/0.06),0_18px_36px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="instruments-heading">
            <div class="flex flex-col gap-3 border-b border-line/80 bg-white px-5 py-4 md:flex-row md:items-center md:justify-between">
                <h2 id="instruments-heading" class="text-base font-semibold tracking-tight text-navy">Instrument List</h2>
                <div data-instruments-toolbar class="grid grid-cols-2 gap-2 md:flex md:items-center">
                    <label for="instrument-location" class="sr-only">Filter by location</label>
                    <select id="instrument-location" wire:model.live="locationId" class="order-2 min-w-0 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-navy md:order-none md:w-48">
                        <option value="">All Locations</option>
                        @foreach ($this->locations as $location)
                            <option value="{{ $location->location_id }}">{{ $location->location_name }}</option>
                        @endforeach
                    </select>
                    <label for="instrument-search" class="sr-only">Search by name, brand, type, or model</label>
                    <input
                        id="instrument-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Name, Type, Brand, Model, Serial Number, Location"
                        class="ui-input order-1 col-span-2 w-full md:order-none md:col-span-1 md:w-64"
                    >
                    <label for="instrument-page-size" class="sr-only">Rows per page</label>
                    <select id="instrument-page-size" wire:model.live="perPage" class="order-3 min-w-0 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-navy md:order-none md:w-auto">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                </div>
            </div>

            @if ($this->instruments->isEmpty())
                <p class="px-5 py-8 text-sm text-navy/70">{{ $this->locationId !== '' || trim($this->search) !== '' ? 'No instruments found for the selected filters.' : 'No instruments found.' }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="management-table">
                        <thead>
                            <tr>
                                <th scope="col">Instrument Name</th>
                                <th scope="col">Brand</th>
                                <th scope="col">Type</th>
                                <th scope="col">Model</th>
                                <th scope="col">Serial Number</th>
                                <th scope="col">Asset Number</th>
                                <th scope="col">Location</th>
                                <th scope="col">Detail Location</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->instruments as $instrument)
                                @php
                                    $row = $this->row($instrument);
                                @endphp
                                <tr wire:key="instrument-{{ $row['key'] }}">
                                    <td class="font-medium text-navy">
                                        <a href="{{ $this->detailUrl($instrument) }}" class="text-navy underline-offset-2 hover:text-brand hover:underline focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2">
                                            {{ $instrument->instrument_name }}
                                        </a>
                                    </td>
                                    <td class="text-navy/80">{{ $row['brand'] }}</td>
                                    <td class="text-navy/80">{{ $row['type'] }}</td>
                                    <td class="text-navy/80">{{ $row['model'] }}</td>
                                    <td class="break-words text-navy/70">{{ $row['serial_number'] }}</td>
                                    <td class="break-words text-navy/70">{{ $row['asset_number'] }}</td>
                                    <td class="text-navy/80">{{ $row['location'] }}</td>
                                    <td class="text-navy/80">{{ $row['detail_location'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div>
                    {{ $this->instruments->links('vendor.pagination.management-tailwind') }}
                </div>
            @endif
        </section>
    </x-management-shell>
</div>
