<?php

use App\CalibrationSessions\CalibrationSessionHttpRead;
use App\Dashboard\HomeOperationalViewModel;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    private const NEEDS_ATTENTION_PER_PAGE = 3;

    private const RECENT_ACTIVITY_PER_PAGE = 5;

    public int $needsAttentionPage = 1;

    public int $recentActivityPage = 1;

    /** @return HomeOperationalViewModel */
    #[Computed]
    public function operational(): HomeOperationalViewModel
    {
        return HomeOperationalViewModel::current(app(CalibrationSessionHttpRead::class)->connection());
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function needsAttentionPageItems(): array
    {
        return array_slice(
            $this->operational->needsAttention,
            ($this->needsAttentionPage - 1) * self::NEEDS_ATTENTION_PER_PAGE,
            self::NEEDS_ATTENTION_PER_PAGE,
        );
    }

    #[Computed]
    public function needsAttentionPageCount(): int
    {
        return max(1, (int) ceil(count($this->operational->needsAttention) / self::NEEDS_ATTENTION_PER_PAGE));
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function recentActivityPageItems(): array
    {
        return array_slice(
            $this->operational->recentActivity,
            ($this->recentActivityPage - 1) * self::RECENT_ACTIVITY_PER_PAGE,
            self::RECENT_ACTIVITY_PER_PAGE,
        );
    }

    #[Computed]
    public function recentActivityPageCount(): int
    {
        return max(1, (int) ceil(count($this->operational->recentActivity) / self::RECENT_ACTIVITY_PER_PAGE));
    }

    public function nextNeedsAttentionPage(): void
    {
        $this->needsAttentionPage = min($this->needsAttentionPage + 1, $this->needsAttentionPageCount());
    }

    public function previousNeedsAttentionPage(): void
    {
        $this->needsAttentionPage = max(1, $this->needsAttentionPage - 1);
    }

    public function nextRecentActivityPage(): void
    {
        $this->recentActivityPage = min($this->recentActivityPage + 1, $this->recentActivityPageCount());
    }

    public function previousRecentActivityPage(): void
    {
        $this->recentActivityPage = max(1, $this->recentActivityPage - 1);
    }
};
?>

<div data-dashboard-page class="px-4 py-8 sm:px-6 lg:px-10">
    <div class="mx-auto flex max-w-[1760px] flex-col gap-6 xl:gap-8">
        <section data-dashboard-hero class="overflow-hidden rounded-2xl bg-gradient-to-br from-[#14263F] via-[#1D4E73] to-[#365F83] p-4 text-white shadow-[0_22px_44px_-28px_rgb(20_38_63/0.8)] sm:p-6">
            <header data-dashboard-header>
                <h1 class="text-2xl font-semibold tracking-tight text-white">Dashboard</h1>
                <p class="mt-1 text-sm text-blue-100/75">Instrument calibration and operational overview.</p>
            </header>

        @php
            $operational = $this->operational;
            $cards = [
                ['label' => 'Total Instruments', 'value' => $operational->totalInstruments, 'iconSurface' => 'bg-white/15'],
                ['label' => 'On Track', 'value' => $operational->onTrack, 'iconSurface' => 'bg-emerald-400/20'],
                ['label' => 'Due Soon', 'value' => $operational->dueSoon, 'iconSurface' => 'bg-amber-400/20'],
                ['label' => 'Overdue', 'value' => $operational->overdue, 'iconSurface' => 'bg-rose-400/20'],
            ];
        @endphp

            <div class="mt-5 h-px bg-white/20" aria-hidden="true"></div>

            <section data-dashboard-stats aria-label="Calibration statistics" class="mt-4 grid grid-cols-2 gap-y-4 sm:gap-y-5 xl:grid-cols-4 xl:gap-y-0">
            @foreach ($cards as $card)
                <article data-dashboard-stat class="relative flex min-w-0 flex-col items-center gap-2 px-2 py-1 text-center sm:px-4 xl:px-6 xl:first:pl-0 xl:last:pr-0">
                    @if ($loop->index > 0)
                        <span class="pointer-events-none absolute left-0 top-1/2 hidden h-10 w-px -translate-y-1/2 bg-white/15 xl:block" aria-hidden="true"></span>
                    @endif
                    @if ($loop->index % 2 === 1)
                        <span class="pointer-events-none absolute left-0 top-0 h-full w-px bg-white/15 xl:hidden" aria-hidden="true"></span>
                    @endif
                    <div class="flex items-center gap-2">
                        <span class="size-2 shrink-0 rounded-full {{ $card['iconSurface'] }}" aria-hidden="true"></span>
                        <p class="text-sm font-medium leading-tight text-blue-50/80">{{ $card['label'] }}</p>
                    </div>
                    <p class="text-3xl font-semibold tracking-tight tabular-nums text-white sm:text-4xl" data-dashboard-counter="{{ $card['value'] }}">{{ $card['value'] }}</p>
                </article>
            @endforeach
            </section>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(22rem,0.8fr)] xl:gap-8">
            <section data-dashboard-content class="ui-card min-w-0 p-5 sm:p-6" aria-labelledby="calibration-health-heading">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="calibration-health-heading" class="text-base font-semibold tracking-tight text-navy">Calibration Health</h2>
                        <p class="mt-1 text-sm text-muted">Current operational standing across all instruments.</p>
                    </div>
                    <span class="hidden rounded-full bg-brand/10 px-3 py-1 text-xs font-semibold text-brand sm:inline-flex">{{ $operational->calibrationHealth['total'] }} total</span>
                </div>

                @php
                    $healthSegments = $operational->calibrationHealth['segments'];
                    $healthTotal = (int) $operational->calibrationHealth['total'];
                    $chartRadius = 15.915;
                    $chartOffset = 25;
                @endphp
                <div class="mt-5 flex flex-col items-center gap-5 lg:flex-row lg:items-center lg:justify-center lg:gap-5 xl:justify-start">
                    <div class="chart-ring relative size-44 shrink-0 xl:size-48" data-calibration-health-donut data-location-distribution-donut>
                        <svg viewBox="0 0 42 42" class="size-full drop-shadow-[0_14px_18px_rgb(22_49_114/0.12)]" role="img" aria-label="Calibration health distribution">
                            <circle class="chart-track" cx="21" cy="21" r="{{ $chartRadius }}" fill="none" stroke="#E6EDF4" stroke-width="6"></circle>
                            @foreach ($healthSegments as $index => $segment)
                                @php
                                    $percentage = (float) rtrim($segment['percent'], '%');
                                    $segmentLength = $healthTotal > 0 ? max($percentage - min(1.1, $percentage * 0.12), 0.35) : 0;
                                @endphp
                                <circle
                                    class="chart-segment"
                                    data-dashboard-chart-segment
                                    data-dashboard-segment-index="{{ $index }}"
                                    data-segment-length="{{ $segmentLength }}"
                                    cx="21"
                                    cy="21"
                                    r="{{ $chartRadius }}"
                                    fill="none"
                                    stroke="{{ $segment['color'] }}"
                                    stroke-width="6"
                                    stroke-linecap="butt"
                                    stroke-dasharray="{{ $segmentLength }} {{ 100 - $segmentLength }}"
                                    stroke-dashoffset="{{ $chartOffset }}"
                                    role="img"
                                    aria-label="{{ $segment['label'] }}: {{ $segment['count'] }} instruments, {{ $segment['percent'] }} percent"
                                ><title>{{ $segment['label'] }}: {{ $segment['count'] }} instruments, {{ $segment['percent'] }} of total</title></circle>
                                @php $chartOffset -= $percentage; @endphp
                            @endforeach
                        </svg>
                        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                            <span class="text-3xl font-semibold tracking-tight tabular-nums text-navy" data-dashboard-counter="{{ $healthTotal }}">{{ $healthTotal }}</span>
                            <span class="text-xs font-medium text-muted">Instruments</span>
                        </div>
                    </div>

                    <ul class="w-full min-w-0 max-w-lg divide-y divide-line xl:max-w-sm">
                        @foreach ($healthSegments as $index => $segment)
                            <li data-dashboard-distribution-row="{{ $index }}" class="flex items-center justify-between gap-4 py-3" wire:key="health-segment-{{ $segment['label'] }}">
                                <span class="inline-flex min-w-0 items-center gap-2">
                                    <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $segment['color'] }}" aria-hidden="true"></span>
                                    <span class="truncate text-sm font-medium text-navy">{{ $segment['label'] }}</span>
                                </span>
                                <span class="shrink-0 text-right text-sm font-semibold tabular-nums text-navy"><span data-dashboard-counter="{{ $segment['count'] }}">{{ $segment['count'] }}</span> <span class="font-normal text-muted">(<span data-dashboard-percentage="{{ (float) rtrim($segment['percent'], '%') }}">{{ $segment['percent'] }}</span>)</span></span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>

            <section data-dashboard-content class="ui-card min-w-0 p-5 sm:p-6" aria-labelledby="needs-attention-heading">
                <div class="flex items-start justify-between gap-4 border-b border-line pb-4">
                    <div>
                        <h2 id="needs-attention-heading" class="text-base font-semibold tracking-tight text-navy">Needs Attention</h2>
                        <p class="mt-1 text-sm text-muted">Priority instruments requiring calibration follow-up.</p>
                    </div>
                    <a href="{{ route('calibrations.index') }}" class="shrink-0 text-sm font-semibold text-brand hover:text-navy">View reports</a>
                </div>
                @if ($operational->needsAttention === [])
                    <div class="mt-7 flex flex-col items-center rounded-xl bg-on-track/5 px-4 py-7 text-center">
                        <span class="flex size-11 items-center justify-center rounded-full bg-on-track/10 text-on-track" aria-hidden="true">
                            <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m7 12 3.2 3.2L17.5 8M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </span>
                        <h3 class="mt-3 text-sm font-semibold text-navy">All Clear</h3>
                        <p class="mt-1 max-w-xs text-sm leading-6 text-muted">No overdue or due-soon instruments at the moment.</p>
                    </div>
                @else
                    <ul data-dashboard-swipe-list class="min-h-[13rem] divide-y divide-line">
                        @foreach ($this->needsAttentionPageItems as $item)
                            <li wire:key="attention-{{ $item['instrument_id'] }}">
                                <a href="{{ $item['detail_url'] }}" class="group flex cursor-pointer items-start justify-between gap-4 rounded-lg py-3 first:pt-4 last:pb-0 hover:bg-slate-50/80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                                    <span class="min-w-0 break-words">
                                    <span class="block text-sm font-semibold text-navy group-hover:text-brand">{{ $item['instrument_name'] }}</span>
                                    <span class="mt-1 block break-words text-xs text-muted">{{ $item['serial_number'] }}</span>
                                    @if (false)
                                    <span class="mt-1 block truncate text-xs text-muted">Due {{ $item['calibration_due'] }} · {{ $item['serial_number'] }}</span>
                                    @endif
                                    </span>
                                <span class="flex min-w-[5.75rem] shrink-0 flex-col items-end gap-1 text-right">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $item['status'] === 'Overdue' ? 'bg-overdue/10 text-overdue' : 'bg-due-soon/15 text-due-soon' }}">{{ $item['status'] }}</span>
                                    <time class="text-xs tabular-nums text-muted" datetime="{{ $item['calibration_due'] }}">{{ $item['calibration_due'] }}</time>
                                </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    @if ($this->needsAttentionPageCount > 1)
                        <div data-dashboard-pagination class="mt-3 flex items-center justify-between border-t border-line pt-3">
                            <button type="button" wire:click="previousNeedsAttentionPage" @disabled($this->needsAttentionPage === 1) aria-label="Previous Needs Attention page" class="inline-flex items-center gap-1 rounded-md px-2 py-1.5 text-xs font-semibold text-muted transition-colors hover:bg-slate-100 hover:text-navy disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-3-3 3-3" /></svg>
                                Previous
                            </button>
                            <span class="text-xs tabular-nums text-muted" aria-live="polite">Page {{ $this->needsAttentionPage }} of {{ $this->needsAttentionPageCount }}</span>
                            <button type="button" wire:click="nextNeedsAttentionPage" @disabled($this->needsAttentionPage === $this->needsAttentionPageCount) aria-label="Next Needs Attention page" class="inline-flex items-center gap-1 rounded-md px-2 py-1.5 text-xs font-semibold text-muted transition-colors hover:bg-slate-100 hover:text-navy disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                                Next
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" /></svg>
                            </button>
                        </div>
                    @endif
                @endif
            </section>
        </div>

        <div class="grid gap-6 xl:grid-cols-2 xl:gap-8">
            <section data-dashboard-content class="min-w-0 rounded-2xl bg-gradient-to-br from-[#14263F] via-[#1D4E73] to-[#365F83] p-5 text-white shadow-[0_18px_36px_-22px_rgb(20_38_63/0.8)] sm:p-6" aria-labelledby="location-overview-heading">
                <div>
                    <h2 id="location-overview-heading" class="text-base font-semibold tracking-tight text-white">Location Overview</h2>
                    <p class="mt-1 text-sm text-blue-100/70">Instrument distribution by registered location.</p>
                </div>
                @if ($operational->locationOverview === [])
                    <p class="mt-8 rounded-xl bg-[#10243B] px-4 py-6 text-center text-sm text-blue-100/70">No instrument locations found.</p>
                @else
                    @php
                        $maxLocationCount = max(array_column($operational->locationOverview, 'count') ?: [0]);
                    @endphp
                    <ul class="mt-5">
                        @foreach ($operational->locationOverview as $location)
                            @php
                                $locationBarWidth = $maxLocationCount > 0 ? ($location['count'] / $maxLocationCount) * 100 : 0;
                            @endphp
                            <li class="py-3 first:pt-0 last:pb-0" wire:key="location-overview-{{ $location['location'] }}">
                                <div class="flex items-center justify-between gap-4">
                                    <span class="min-w-0 break-words text-sm font-medium text-blue-50">{{ $location['location'] }}</span>
                                    <span class="shrink-0 text-sm font-semibold tabular-nums text-white">{{ $location['count'] }}</span>
                                </div>
                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-[#10243B]" role="progressbar" aria-label="{{ $location['location'] }} relative instrument count" aria-valuemin="0" aria-valuemax="{{ $maxLocationCount }}" aria-valuenow="{{ $location['count'] }}">
                                    <div class="h-full rounded-full bg-[#8FAFC8]" style="width: {{ $locationBarWidth }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section data-dashboard-content class="ui-card flex min-w-0 flex-col p-5 sm:p-6" aria-labelledby="recent-activity-heading">
                <div class="border-b border-line pb-4">
                    <h2 id="recent-activity-heading" class="text-base font-semibold tracking-tight text-navy">Recent Activity</h2>
                    <p class="mt-1 text-sm text-muted">Latest calibration session events.</p>
                </div>
                @if ($operational->recentActivity === [])
                    <p class="mt-8 rounded-xl bg-canvas px-4 py-6 text-center text-sm text-muted">No calibration activity found.</p>
                @else
                    <ul data-dashboard-swipe-list class="min-h-[20rem] flex-1 divide-y divide-line">
                        @foreach ($this->recentActivityPageItems as $activity)
                            <li wire:key="activity-{{ $activity['key'] }}">
                                <a href="{{ $activity['detail_url'] }}" class="group flex cursor-pointer items-start justify-between gap-4 rounded-lg py-4 hover:bg-slate-50/80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                                    <span class="min-w-0 break-words">
                                        <span class="block text-sm font-semibold text-navy group-hover:text-brand">{{ $activity['instrument_name'] }}</span>
                                        <span class="mt-2 inline-flex rounded-full bg-brand/10 px-2 py-1 text-xs font-semibold text-brand">{{ $activity['event'] }}</span>
                                        <span class="mt-1 block text-xs text-muted">Status: {{ $activity['status'] }}</span>
                                    </span>
                                    <span class="flex min-w-[5.5rem] shrink-0 flex-col items-end gap-1 text-right">
                                        <time class="text-xs font-medium tabular-nums text-navy" datetime="{{ $activity['timestamp'] }}">{{ $activity['timestamp_label'] }}</time>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    @if ($this->recentActivityPageCount > 1)
                        <div data-dashboard-pagination class="mt-3 flex shrink-0 items-center justify-between border-t border-line pt-3">
                            <button type="button" wire:click="previousRecentActivityPage" @disabled($this->recentActivityPage === 1) aria-label="Previous Recent Activity page" class="inline-flex items-center gap-1 rounded-md px-2 py-1.5 text-xs font-semibold text-muted transition-colors hover:bg-slate-100 hover:text-navy disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-3-3 3-3" /></svg>
                                Previous
                            </button>
                            <span class="text-xs tabular-nums text-muted" aria-live="polite">Page {{ $this->recentActivityPage }} of {{ $this->recentActivityPageCount }}</span>
                            <button type="button" wire:click="nextRecentActivityPage" @disabled($this->recentActivityPage === $this->recentActivityPageCount) aria-label="Next Recent Activity page" class="inline-flex items-center gap-1 rounded-md px-2 py-1.5 text-xs font-semibold text-muted transition-colors hover:bg-slate-100 hover:text-navy disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                                Next
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 3 3-3 3" /></svg>
                            </button>
                        </div>
                    @endif
                @endif
            </section>
        </div>
    </div>
</div>
