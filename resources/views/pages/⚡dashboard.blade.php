<?php

use App\CalibrationSessions\CalibrationSessionHttpRead;
use App\Models\DetailLocation;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Location;
use App\Models\StandardMaster;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $search = '';

    /** @return array<string, mixed> */
    #[Computed]
    public function inventory(): array
    {
        $name = app(CalibrationSessionHttpRead::class)->connectionName();
        $needle = trim($this->search);
        $instrumentsQuery = Instrument::on($name)
            ->with('detailLocation.location')
            ->orderByDesc('created_at')
            ->orderByDesc('instrument_id');

        if ($needle !== '') {
            $operator = $instrumentsQuery->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $instrumentsQuery->where('instrument_name', $operator, '%'.addcslashes($needle, '%_\\').'%');
        }

        $recent = $instrumentsQuery->limit(10)->get()->map(fn (Instrument $instrument): array => [
            'instrument_id' => (int) $instrument->instrument_id,
            'instrument_name' => $instrument->instrument_name,
            'serial_number' => $this->display($instrument->serial_number),
            'location' => $this->display($instrument->detailLocation?->location?->location_name),
            'detail_location' => $this->display($instrument->detailLocation?->detail_location_name),
        ])->all();

        $distribution = Instrument::on($name)
            ->with('detailLocation.location')
            ->get()
            ->groupBy(fn (Instrument $instrument): string => $instrument->detailLocation?->location?->location_name ?: 'Unassigned')
            ->map(fn ($instruments, string $location): array => ['location' => $location, 'count' => $instruments->count()])
            ->sort(function (array $left, array $right): int {
                $countComparison = $right['count'] <=> $left['count'];

                return $countComparison !== 0
                    ? $countComparison
                    : strnatcasecmp($left['location'], $right['location']);
            })
            ->values()
            ->all();

        return [
            'total' => Instrument::on($name)->count(),
            'types' => InstrumentType::on($name)->count(),
            'locations' => $this->filledLocationCount($name),
            'standards' => StandardMaster::on($name)->count(),
            'distribution' => $distribution,
            'recent' => $recent,
        ];
    }

    private function filledLocationCount(string $connectionName): int
    {
        return Location::on($connectionName)
            ->whereNotNull('location_name')
            ->whereRaw("trim(location_name) <> ''")
            ->whereIn('location_id', DetailLocation::on($connectionName)
                ->select('location_id')
                ->whereIn(
                    'detail_location_id',
                    Instrument::on($connectionName)->select('detail_location_id')->whereNotNull('detail_location_id'),
                ))
            ->count();
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
            <h1 class="text-2xl font-semibold tracking-tight text-navy">Dashboard</h1>
            <p class="mt-1 text-sm text-muted">Instrument inventory and location overview.</p>
        </header>

        <section aria-label="Inventory statistics" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['label' => 'Total Instruments', 'value' => $this->inventory['total'], 'surface' => 'bg-brand shadow-[0_16px_28px_-16px_rgb(30_86_160/0.75)]'],
                ['label' => 'Instrument Types', 'value' => $this->inventory['types'], 'surface' => 'bg-on-track shadow-[0_16px_28px_-16px_rgb(31_122_77/0.75)]'],
                ['label' => 'Locations', 'value' => $this->inventory['locations'], 'surface' => 'bg-due-soon shadow-[0_16px_28px_-16px_rgb(138_90_0/0.75)]'],
                ['label' => 'Active Standards', 'value' => $this->inventory['standards'], 'surface' => 'bg-overdue shadow-[0_16px_28px_-16px_rgb(180_35_24/0.75)]'],
            ] as $card)
                <article class="relative overflow-hidden rounded-2xl p-5 text-white transition duration-200 hover:-translate-y-0.5 {{ $card['surface'] }}">
                    <span class="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/25 via-transparent to-black/15" aria-hidden="true"></span>
                    <div class="relative"><p class="text-sm font-medium text-white/80">{{ $card['label'] }}</p><p class="mt-3 text-4xl font-semibold tracking-tight tabular-nums">{{ $card['value'] }}</p></div>
                </article>
            @endforeach
        </section>

        <section class="ui-card p-6" aria-labelledby="distribution-heading">
            <h2 id="distribution-heading" class="text-base font-semibold tracking-tight text-navy">Instrument Distribution by Location</h2>
            @if ($this->inventory['distribution'] === [])
                <p class="mt-4 text-sm text-muted">No instrument locations found.</p>
            @else
                @php
                    $distribution = $this->inventory['distribution'];
                    $totalInstruments = (int) $this->inventory['total'];
                    $chartColors = ['#20559A', '#3692C7', '#12A99C', '#8365C7', '#E2A347', '#DB748B', '#6287A7', '#7EAA55', '#B583C9', '#9AA6B2'];
                    $locationCount = count($distribution);
                    $chartRadius = 15.915;
                    $chartCircumference = 100;
                    $chartOffset = 25;
                    $locationLabels = [];
                @endphp
                <div class="mt-5 flex flex-col items-center gap-6 md:flex-row md:items-center">
                    <div class="chart-ring relative shrink-0" style="width: min(256px, 100%); aspect-ratio: 1 / 1;" data-location-distribution-donut>
                        <svg viewBox="0 0 42 42" class="size-full drop-shadow-[0_14px_18px_rgb(22_49_114/0.16)]" role="img" aria-label="Instrument distribution by location">
                            <circle class="chart-track" cx="21" cy="21" r="{{ $chartRadius }}" fill="none" stroke="#D6E4F0" stroke-width="6"></circle>
                            @foreach ($distribution as $index => $location)
                                @php
                                    $count = (int) $location['count'];
                                    $percentage = $totalInstruments > 0 ? ($count / $totalInstruments) * 100 : 0;
                                    $segmentLength = max($percentage - min(1.1, $percentage * 0.12), 0.35);
                                    $color = $chartColors[$index % count($chartColors)];
                                    $midpoint = (25 - $chartOffset) + ($segmentLength / 2);
                                    $labelRadius = ($chartRadius / 42) * 100;
                                    $labelAngle = fmod($midpoint, 100);
                                    $labelX = 50 + sin(($labelAngle / 100) * 2 * M_PI) * $labelRadius;
                                    $labelY = 50 - cos(($labelAngle / 100) * 2 * M_PI) * $labelRadius;
                                    $labelIsClear = $percentage < 5;
                                    if (! $labelIsClear) {
                                        foreach ($locationLabels as $existingLabel) {
                                            $angleDifference = abs($labelAngle - $existingLabel['angle']);
                                            $angleDifference = min($angleDifference, 100 - $angleDifference);
                                            if ($angleDifference < 6.5) {
                                                $labelIsClear = true;
                                                break;
                                            }
                                        }
                                    }
                                    if (! $labelIsClear) {
                                        $locationLabels[] = [
                                            'angle' => $labelAngle,
                                            'percent' => number_format($percentage, 1),
                                            'x' => $labelX,
                                            'y' => $labelY,
                                            'index' => count($locationLabels),
                                        ];
                                    }
                                @endphp
                                <circle
                                    class="chart-segment"
                                    style="--segment-index: {{ $index }};"
                                    cx="21"
                                    cy="21"
                                    r="{{ $chartRadius }}"
                                    fill="none"
                                    stroke="{{ $color }}"
                                    stroke-width="6"
                                    stroke-linecap="butt"
                                    stroke-dasharray="{{ $segmentLength }} {{ $chartCircumference - $segmentLength }}"
                                    stroke-dashoffset="{{ $chartOffset }}"
                                    tabindex="0"
                                    role="img"
                                    aria-label="{{ $location['location'] }}: {{ $count }} instruments, {{ number_format($percentage, 1) }} percent"
                                ><title>{{ $location['location'] }}&#10;{{ $count }} Instruments&#10;{{ number_format($percentage, 1) }}% of Total</title></circle>
                                @php $chartOffset -= $percentage; @endphp
                            @endforeach
                        </svg>
                        @foreach ($locationLabels as $label)
                            <span
                                class="chart-label pointer-events-none absolute -translate-x-1/2 -translate-y-1/2 rounded-full bg-white px-1.5 py-0.5 text-[11px] font-semibold leading-none text-navy shadow-sm"
                                style="left: {{ $label['x'] }}%; top: {{ $label['y'] }}%; --segment-index: {{ $label['index'] }}"
                            >{{ $label['percent'] }}%</span>
                        @endforeach
                        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                            <span class="text-3xl font-semibold tracking-tight tabular-nums text-navy">{{ $totalInstruments }}</span>
                            <span class="text-xs font-medium text-muted">Instruments</span>
                            <span class="mt-1 text-[11px] text-muted">{{ $locationCount }} {{ $locationCount === 1 ? 'Location' : 'Locations' }}</span>
                        </div>
                    </div>

                    <ul class="w-full min-w-0 flex-1">
                    @foreach ($distribution as $index => $location)
                        @php
                            $count = (int) $location['count'];
                            $percentage = $totalInstruments > 0 ? ($count / $totalInstruments) * 100 : 0;
                            $color = $chartColors[$index % count($chartColors)];
                        @endphp
                        <li class="flex items-center justify-between gap-4 border-b border-line py-2.5 text-sm last:border-b-0" wire:key="location-distribution-{{ $location['location'] }}">
                            <span class="inline-flex min-w-0 flex-1 items-center gap-2">
                                <span
                                    class="shrink-0 rounded-full"
                                    style="display: inline-block; width: 9px; height: 9px; min-width: 9px; min-height: 9px; flex-shrink: 0; border-radius: 50%; background-color: {{ $color }};"
                                    aria-hidden="true"
                                ></span>
                                <span class="min-w-0 truncate font-medium text-navy">{{ $location['location'] }}</span>
                            </span>
                            <span class="shrink-0 text-right font-semibold tabular-nums text-brand">{{ $count }} <span class="font-normal text-muted">({{ number_format($percentage, 1) }}%)</span></span>
                        </li>
                    @endforeach
                    </ul>
                </div>
            @endif
        </section>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="recent-instruments-heading">
            <div class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                <h2 id="recent-instruments-heading" class="text-base font-semibold tracking-tight text-navy">Recent Instruments</h2>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center"><label for="recent-instrument-search" class="sr-only">Search by name</label><input id="recent-instrument-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search by name" class="ui-input sm:w-64"><a href="{{ route('instruments.index') }}" class="ui-button-primary shrink-0 items-center gap-2"><svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6-9.75-6-9.75-6Z" /><circle cx="12" cy="12" r="2.5" /></svg>View All</a></div>
            </div>

            @if ($this->inventory['recent'] === [])
                <p class="px-5 py-8 text-sm text-navy/70">No instruments found.</p>
            @else
                <div class="overflow-x-auto"><table class="ui-table"><thead class="bg-navy text-xs font-medium text-white"><tr><th scope="col" class="px-5 py-3 font-medium">Instrument Name</th><th scope="col" class="px-5 py-3 font-medium">Serial Number</th><th scope="col" class="px-5 py-3 font-medium">Location</th><th scope="col" class="px-5 py-3 font-medium">Detail Location</th></tr></thead><tbody class="divide-y divide-white/70">
                    @foreach ($this->inventory['recent'] as $instrument)
                        <tr wire:key="recent-instrument-{{ $instrument['instrument_id'] }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec] hover:bg-[#edf4fa]"><td class="px-5 py-3.5 font-medium"><a href="{{ route('instruments.show', $instrument['instrument_id']) }}" class="text-navy hover:text-brand">{{ $instrument['instrument_name'] }}</a></td><td class="px-5 py-3.5 text-navy/70">{{ $instrument['serial_number'] }}</td><td class="px-5 py-3.5 text-navy">{{ $instrument['location'] }}</td><td class="px-5 py-3.5 text-navy">{{ $instrument['detail_location'] }}</td></tr>
                    @endforeach
                </tbody></table></div>
            @endif
        </section>
    </div>
</div>
