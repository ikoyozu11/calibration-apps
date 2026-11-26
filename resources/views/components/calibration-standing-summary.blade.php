<section data-calibrations-status-panel data-calibrations-content class="ui-card min-w-0 p-5 sm:p-6" aria-labelledby="calibration-status-heading">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h2 id="calibration-status-heading" class="text-base font-semibold tracking-tight text-navy">Calibration Status</h2>
            <p class="mt-1 text-sm text-muted">Current operational standing across all instruments.</p>
        </div>
        <span class="hidden rounded-full bg-brand/10 px-3 py-1 text-xs font-semibold text-brand sm:inline-flex">{{ $overview->total }} total</span>
    </div>

    @php
        $statusSegments = $overview->segments();
        $statusTotal = (int) $overview->total;
        $chartRadius = 15.915;
        $chartOffset = 25;
    @endphp

    @if ($statusTotal === 0)
        <p class="mt-4 text-sm text-muted">No instruments found.</p>
    @else
        <div class="mt-5 flex flex-col items-center gap-5 lg:flex-row lg:items-center lg:justify-center lg:gap-5 xl:justify-start">
            <div class="chart-ring relative size-44 shrink-0 xl:size-48" data-calibration-status-donut>
                <svg viewBox="0 0 42 42" class="size-full drop-shadow-[0_14px_18px_rgb(22_49_114/0.12)]" role="img" aria-label="Calibration status distribution">
                    <circle class="chart-track" cx="21" cy="21" r="{{ $chartRadius }}" fill="none" stroke="#E6EDF4" stroke-width="6"></circle>
                    @foreach ($statusSegments as $index => $segment)
                        @php
                            $percentage = (float) rtrim($segment['percent'], '%');
                            $segmentLength = $statusTotal > 0 ? max($percentage - min(1.1, $percentage * 0.12), 0.35) : 0;
                        @endphp
                        <circle
                            class="chart-segment"
                            data-calibration-chart-segment
                            data-calibration-segment-index="{{ $index }}"
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
                    <span class="text-3xl font-semibold tracking-tight tabular-nums text-navy" data-calibration-counter="{{ $statusTotal }}">{{ $statusTotal }}</span>
                    <span class="text-xs font-medium text-muted">Instruments</span>
                </div>
            </div>

            <ul class="w-full min-w-0 max-w-lg divide-y divide-line xl:max-w-sm">
                @foreach ($statusSegments as $index => $segment)
                    <li data-calibrations-status-row data-calibrations-status-index="{{ $index }}" class="flex items-center justify-between gap-4 py-3 text-sm" wire:key="calibration-status-{{ $segment['label'] }}">
                        <span class="inline-flex min-w-0 items-center gap-2">
                            <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $segment['color'] }}" aria-hidden="true"></span>
                            <span class="truncate text-sm font-medium text-navy">{{ $segment['label'] }}</span>
                        </span>
                        <span class="shrink-0 text-right text-sm font-semibold tabular-nums text-navy"><span data-calibration-counter="{{ $segment['count'] }}">{{ $segment['count'] }}</span> <span class="font-normal text-muted">(<span data-calibration-percentage="{{ (float) rtrim($segment['percent'], '%') }}" data-calibration-percentage-label="{{ $segment['percent'] }}">{{ $segment['percent'] }}</span>)</span></span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
