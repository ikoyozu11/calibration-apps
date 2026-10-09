<?php

use App\Dashboard\InstrumentCalibrationOverview;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $search = '';

    #[Computed]
    public function overview(): InstrumentCalibrationOverview
    {
        return InstrumentCalibrationOverview::current($this->search);
    }
};
?>

<div class="px-4 py-8 sm:px-6 lg:px-10">
    <div class="mx-auto flex max-w-6xl flex-col gap-8">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight text-navy">Dashboard</h1>
            <p class="mt-1 text-sm text-muted">Overview of instrument calibration</p>
        </header>

        <section aria-label="Calibration statistics" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="relative overflow-hidden rounded-2xl bg-brand p-5 text-white shadow-[0_16px_28px_-16px_rgb(30_86_160/0.75)] transition duration-200 hover:-translate-y-0.5">
                <span class="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/25 via-transparent to-black/15" aria-hidden="true"></span>
                <div class="relative">
                    <p class="text-sm font-medium text-white/80">Total Instruments</p>
                    <p class="mt-3 text-4xl font-semibold tracking-tight tabular-nums">{{ $this->overview->total }}</p>
                </div>
            </article>
            <article class="relative overflow-hidden rounded-2xl bg-on-track p-5 text-white shadow-[0_16px_28px_-16px_rgb(31_122_77/0.75)] transition duration-200 hover:-translate-y-0.5">
                <span class="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/25 via-transparent to-black/15" aria-hidden="true"></span>
                <div class="relative">
                    <p class="text-sm font-medium text-white/80">On Track</p>
                    <p class="mt-3 text-4xl font-semibold tracking-tight tabular-nums">{{ $this->overview->onTrack }}</p>
                </div>
            </article>
            <article class="relative overflow-hidden rounded-2xl bg-overdue p-5 text-white shadow-[0_16px_28px_-16px_rgb(180_35_24/0.75)] transition duration-200 hover:-translate-y-0.5">
                <span class="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/25 via-transparent to-black/15" aria-hidden="true"></span>
                <div class="relative">
                    <p class="text-sm font-medium text-white/80">Overdue</p>
                    <p class="mt-3 text-4xl font-semibold tracking-tight tabular-nums">{{ $this->overview->overdue }}</p>
                </div>
            </article>
            <article class="relative overflow-hidden rounded-2xl bg-due-soon p-5 text-white shadow-[0_16px_28px_-16px_rgb(138_90_0/0.75)] transition duration-200 hover:-translate-y-0.5">
                <span class="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/25 via-transparent to-black/15" aria-hidden="true"></span>
                <div class="relative">
                    <p class="text-sm font-medium text-white/80">Due Soon</p>
                    <p class="mt-3 text-4xl font-semibold tracking-tight tabular-nums">{{ $this->overview->dueSoon }}</p>
                </div>
            </article>
        </section>

        <section class="ui-card p-6" aria-labelledby="calibration-status-heading">
            <h2 id="calibration-status-heading" class="text-base font-semibold tracking-tight text-navy">Calibration Status</h2>

            @if ($this->overview->total === 0)
                <p class="mt-4 text-sm text-muted">No instruments found.</p>
            @else
                <div class="mt-4 flex flex-col gap-6 md:flex-row md:items-center">
                    <svg viewBox="0 0 42 42" class="chart-ring mx-auto size-52 shrink-0 drop-shadow-[0_14px_18px_rgb(22_49_114/0.16)]" role="img" aria-labelledby="calibration-status-heading">
                        @php
                            $offset = 25;
                            $drawn = 0;
                            $gradients = [
                                'On Track' => ['#6EE7B7', '#12B76A'],
                                'Due Soon' => ['#FDE68A', '#F5A524'],
                                'Overdue' => ['#FDA29B', '#F04438'],
                                'Not Calibrated' => ['#D6E4F0', '#7C93B0'],
                            ];
                        @endphp
                        <defs>
                            @foreach ($gradients as $label => $stops)
                                <linearGradient id="chart-{{ \Illuminate\Support\Str::slug($label) }}" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0%" stop-color="{{ $stops[0] }}"></stop>
                                    <stop offset="100%" stop-color="{{ $stops[1] }}"></stop>
                                </linearGradient>
                            @endforeach
                        </defs>
                        <circle class="chart-track" cx="21" cy="21" r="15.915" fill="none" stroke="#D6E4F0" stroke-width="6"></circle>
                        @foreach ($this->overview->segments() as $segment)
                            @if ($segment['count'] > 0)
                                @php
                                    $raw = ($segment['count'] / $this->overview->total) * 100;
                                    $gap = min(1.8, $raw * 0.22);
                                    $length = max($raw - $gap, 0.6);
                                @endphp
                                <circle
                                    class="chart-segment"
                                    style="--segment-index: {{ $drawn }}; stroke-dasharray: {{ $length }} {{ 100 - $length }}; stroke-dashoffset: {{ $offset }}"
                                    cx="21"
                                    cy="21"
                                    r="15.915"
                                    fill="none"
                                    stroke="url(#chart-{{ \Illuminate\Support\Str::slug($segment['label']) }})"
                                    stroke-width="6"
                                ></circle>
                                @php
                                    $offset -= $raw;
                                    $drawn++;
                                @endphp
                            @endif
                        @endforeach
                    </svg>

                    <ul class="flex flex-1 flex-col">
                        @foreach ($this->overview->segments() as $segment)
                            <li class="flex items-center justify-between gap-4 border-b border-line py-3 text-sm last:border-b-0">
                                <span class="inline-flex items-center gap-2 text-ink">
                                    <span class="size-2 rounded-full" style="background-color: {{ $segment['color'] }}" aria-hidden="true"></span>
                                    {{ $segment['label'] }}
                                </span>
                                <span class="font-medium tabular-nums text-navy">{{ $segment['count'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="recent-instruments-heading">
            <div class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                <h2 id="recent-instruments-heading" class="text-base font-semibold tracking-tight text-navy">Recent Instruments</h2>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <label for="recent-instrument-search" class="sr-only">Search by name</label>
                    <input
                        id="recent-instrument-search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search by name"
                        class="ui-input sm:w-64"
                    >
                    <a href="{{ route('instruments.index') }}" class="ui-button-primary shrink-0">View All</a>
                </div>
            </div>

            @if ($this->overview->recent === [])
                <p class="px-5 py-8 text-sm text-navy/70">No instruments found.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead class="bg-navy text-xs font-medium text-white">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-medium">Instrument Name</th>
                                <th scope="col" class="px-5 py-3 font-medium">Serial Number</th>
                                <th scope="col" class="px-5 py-3 font-medium">Latest Calibration Date</th>
                                <th scope="col" class="px-5 py-3 font-medium">Calibration Due</th>
                                <th scope="col" class="px-5 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/70">
                            @foreach ($this->overview->recent as $instrument)
                                <tr wire:key="recent-instrument-{{ $instrument['instrument_id'] }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec] hover:bg-[#edf4fa]">
                                    <td class="px-5 py-3.5 font-medium">
                                        <a href="{{ route('instruments.show', $instrument['instrument_id']) }}" class="text-navy hover:text-brand">
                                            {{ $instrument['instrument_name'] }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-3.5 text-navy/70">{{ $instrument['serial_number'] }}</td>
                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ $instrument['calibration_date'] }}</td>
                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ $instrument['calibration_due'] }}</td>
                                    <td class="px-5 py-3.5">
                                        <x-status-badge :status="$instrument['status']" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</div>
