<?php

use App\Dashboard\CalibrationStanding;
use App\Models\Calibration;
use App\Models\Instrument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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
     * @return array{month: int, pass: int, fail: int, missingCertificate: int}
     */
    #[Computed]
    public function totals(): array
    {
        return [
            'month' => Calibration::query()
                ->completed()
                ->whereBetween('calibration_date', [
                    today()->startOfMonth()->toDateString(),
                    today()->endOfMonth()->toDateString(),
                ])
                ->count(),
            'pass' => Calibration::query()->completed()->where('calibration_result', 'PASS')->count(),
            'fail' => Calibration::query()->completed()->where('calibration_result', 'FAIL')->count(),
            'missingCertificate' => Calibration::query()
                ->completed()
                ->where(function (Builder $query): void {
                    $query->whereNull('certificate_number')
                        ->orWhereRaw("trim(certificate_number) = ''");
                })
                ->count(),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, array{key: string, name: string, brand: string, type: string, model: string, serial_number: string, asset_number: string, calibration_id: int|null, date: string, due: string, status: string}>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $instruments = Instrument::query()
            ->select([
                'instrument.instrument_id',
                'instrument.instrument_name',
                'instrument_type.instrument_type_id',
                'instrument_type.type_name',
                'instrument_type.brand',
                'instrument_type.model',
                'instrument.serial_number',
                'instrument_type.asset_number',
            ])
            ->leftJoin('instrument_type', 'instrument_type.instrument_id', '=', 'instrument.instrument_id')
            ->whereIdentityMatches($this->search)
            ->orderBy('instrument.instrument_id')
            ->orderBy('instrument_type.instrument_type_id')
            ->paginate($this->perPage);

        $latest = $this->latestByType(
            $instruments->getCollection()
                ->pluck('instrument_type_id')
                ->filter(fn (mixed $id): bool => $id !== null)
                ->map(fn (mixed $id): int => (int) $id)
                ->all(),
        );

        return $instruments->through(function (Instrument $instrument) use ($latest): array {
            $typeId = $instrument->getAttribute('instrument_type_id');
            $calibration = $typeId === null ? null : ($latest[(int) $typeId] ?? null);

            return [
                'key' => $instrument->instrument_id.'-'.($typeId ?? 'none'),
                'name' => $this->display($instrument->instrument_name),
                'brand' => $this->display($instrument->getAttribute('brand')),
                'type' => $this->display($instrument->getAttribute('type_name')),
                'model' => $this->display($instrument->getAttribute('model')),
                'serial_number' => $this->display($instrument->getAttribute('serial_number')),
                'asset_number' => $this->display($instrument->getAttribute('asset_number')),
                'calibration_id' => $calibration?->calibration_id,
                'date' => $calibration?->calibration_date?->format('Y-m-d') ?? '—',
                'due' => $calibration?->calibration_due?->format('Y-m-d') ?? '—',
                'status' => CalibrationStanding::fromLatest($calibration)->value,
            ];
        });
    }

    /**
     * @param  list<int>  $typeIds
     * @return array<int, Calibration>
     */
    private function latestByType(array $typeIds): array
    {
        if ($typeIds === []) {
            return [];
        }

        $latest = [];

        Calibration::query()
            ->completed()
            ->select([
                'calibration.calibration_id',
                'calibration.instrument_id',
                'calibration.instrument_type_id',
                'calibration.calibration_date',
                'calibration.calibration_due',
            ])
            ->whereIn('calibration.instrument_type_id', $typeIds)
            ->get()
            ->groupBy(fn (Calibration $calibration): int => (int) $calibration->instrument_type_id)
            ->each(function (Collection $calibrations, int|string $typeId) use (&$latest): void {
                $calibration = $calibrations
                    ->sortByDesc(fn (Calibration $calibration): array => [
                        $calibration->calibration_date?->getTimestamp() ?? PHP_INT_MIN,
                        $calibration->calibration_id,
                    ])
                    ->first();

                if ($calibration instanceof Calibration) {
                    $latest[(int) $typeId] = $calibration;
                }
            });

        return $latest;
    }

    private function display(mixed $value): string
    {
        return filled($value) ? (string) $value : '—';
    }
};
?>

<div class="px-4 py-8 sm:px-6 lg:px-10">
    <div class="mx-auto flex max-w-6xl flex-col gap-8">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight text-navy">Calibrations</h1>
            <p class="mt-1 text-sm text-muted">Calibration results and the current standing of each instrument type.</p>
        </header>

        <section aria-label="Calibration totals" class="grid gap-4 sm:grid-cols-3">
            <article class="relative overflow-hidden rounded-2xl bg-brand p-5 text-white shadow-[0_16px_28px_-16px_rgb(30_86_160/0.75)] transition duration-200 hover:-translate-y-0.5">
                <span class="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/25 via-transparent to-black/15" aria-hidden="true"></span>
                <div class="relative">
                    <p class="text-sm font-medium text-white/80">This month</p>
                    <p class="mt-3 text-4xl font-semibold tracking-tight tabular-nums">{{ $this->totals['month'] }}</p>
                </div>
            </article>
            <article class="relative overflow-hidden rounded-2xl bg-on-track p-5 text-white shadow-[0_16px_28px_-16px_rgb(31_122_77/0.75)] transition duration-200 hover:-translate-y-0.5">
                <span class="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/25 via-transparent to-black/15" aria-hidden="true"></span>
                <div class="relative">
                    <p class="text-sm font-medium text-white/80">Pass / Fail</p>
                    <p class="mt-3 text-4xl font-semibold tracking-tight tabular-nums">
                        {{ $this->totals['pass'] }}<span class="px-2 text-2xl font-medium text-white/70">/</span>{{ $this->totals['fail'] }}
                    </p>
                </div>
            </article>
            <article class="relative overflow-hidden rounded-2xl bg-overdue p-5 text-white shadow-[0_16px_28px_-16px_rgb(180_35_24/0.75)] transition duration-200 hover:-translate-y-0.5">
                <span class="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/25 via-transparent to-black/15" aria-hidden="true"></span>
                <div class="relative">
                    <p class="text-sm font-medium text-white/80">No certificate</p>
                    <p class="mt-3 text-4xl font-semibold tracking-tight tabular-nums">{{ $this->totals['missingCertificate'] }}</p>
                </div>
            </article>
        </section>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="calibrations-heading">
            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 id="calibrations-heading" class="text-base font-semibold tracking-tight text-navy">Calibration List</h2>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <label for="calibration-search" class="sr-only">Search by name, brand, type, or model</label>
                    <input
                        id="calibration-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Name, brand, type, or model"
                        class="ui-input sm:w-64"
                    >
                    <label for="calibration-page-size" class="sr-only">Rows per page</label>
                    <select id="calibration-page-size" wire:model.live="perPage" class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-navy">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                </div>
            </div>

            @if ($this->rows->isEmpty())
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
                                <th scope="col" class="px-5 py-3 font-medium">Calibration Date</th>
                                <th scope="col" class="px-5 py-3 font-medium">Calibration Due</th>
                                <th scope="col" class="px-5 py-3 font-medium">Calibration Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/70">
                            @foreach ($this->rows as $row)
                                <tr wire:key="calibration-row-{{ $row['key'] }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec] hover:bg-[#edf4fa]">
                                    <td class="px-5 py-3.5 font-medium text-navy">
                                        @if ($row['calibration_id'] === null)
                                            {{ $row['name'] }}
                                        @else
                                            <a href="{{ route('calibrations.show', $row['calibration_id']) }}" class="text-navy hover:text-brand">
                                                {{ $row['name'] }}
                                            </a>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 text-navy">{{ $row['brand'] }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ $row['type'] }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ $row['model'] }}</td>
                                    <td class="px-5 py-3.5 text-navy/70">{{ $row['serial_number'] }}</td>
                                    <td class="px-5 py-3.5 text-navy/70">{{ $row['asset_number'] }}</td>
                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ $row['date'] }}</td>
                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ $row['due'] }}</td>
                                    <td class="px-5 py-3.5">
                                        <x-status-badge :status="$row['status']" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($this->rows->hasPages())
                    <div class="px-5 py-4">
                        {{ $this->rows->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</div>
