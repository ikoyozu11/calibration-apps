<div class="flex flex-col gap-6">
    <section aria-label="Current calibration standing metrics" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ([
            ['label' => 'Total Instruments', 'value' => $overview->total, 'surface' => 'bg-brand shadow-[0_16px_28px_-16px_rgb(30_86_160/0.75)]'],
            ['label' => 'On Track', 'value' => $overview->onTrack, 'surface' => 'bg-on-track shadow-[0_16px_28px_-16px_rgb(31_122_77/0.75)]'],
            ['label' => 'Due Soon', 'value' => $overview->dueSoon, 'surface' => 'bg-due-soon shadow-[0_16px_28px_-16px_rgb(138_90_0/0.75)]'],
            ['label' => 'Overdue', 'value' => $overview->overdue, 'surface' => 'bg-overdue shadow-[0_16px_28px_-16px_rgb(180_35_24/0.75)]'],
            ['label' => 'Not Calibrated', 'value' => $overview->notCalibrated, 'surface' => 'bg-navy shadow-[0_16px_28px_-16px_rgb(22_49_114/0.75)]'],
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

    <section class="ui-card p-6" aria-labelledby="calibration-status-heading">
        <h2 id="calibration-status-heading" class="text-base font-semibold tracking-tight text-navy">Calibration Status</h2>

        @if ($overview->total === 0)
            <p class="mt-4 text-sm text-muted">No instruments found.</p>
        @else
            <div class="mt-4 flex flex-col gap-6 md:flex-row md:items-center">
                <div class="chart-ring relative mx-auto size-52 shrink-0">
                    <svg viewBox="0 0 42 42" class="size-full drop-shadow-[0_14px_18px_rgb(22_49_114/0.16)]" role="img" aria-labelledby="calibration-status-heading">
                        @php
                            $offset = 25;
                            $drawn = 0;
                            $labels = [];
                            $gradients = [
                                'On Track' => ['#6EE7B7', '#12B76A'],
                                'Due Soon' => ['#FDE68A', '#F5A524'],
                                'Overdue' => ['#FDA29B', '#F04438'],
                                'Not Calibrated' => ['#D6E4F0', '#7C93B0'],
                            ];
                        @endphp
                        <defs>
                            @foreach ($gradients as $label => $stops)
                                <linearGradient id="report-chart-{{ \Illuminate\Support\Str::slug($label) }}" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0%" stop-color="{{ $stops[0] }}"></stop>
                                    <stop offset="100%" stop-color="{{ $stops[1] }}"></stop>
                                </linearGradient>
                            @endforeach
                        </defs>
                        <circle class="chart-track" cx="21" cy="21" r="15.915" fill="none" stroke="#D6E4F0" stroke-width="6"></circle>
                        @foreach ($overview->segments() as $segment)
                            @if ($segment['count'] > 0)
                                @php
                                    $raw = ($segment['count'] / $overview->total) * 100;
                                    $gap = min(1.8, $raw * 0.22);
                                    $length = max($raw - $gap, 0.6);
                                    $midpoint = (25 - $offset) + ($length / 2);
                                    $angle = ($midpoint / 100) * 2 * M_PI;
                                    $labelRadius = (15.915 / 42) * 100;
                                    $labels[] = [
                                        'percent' => $segment['percent'],
                                        'x' => 50 + sin($angle) * $labelRadius,
                                        'y' => 50 - cos($angle) * $labelRadius,
                                        'index' => $drawn,
                                    ];
                                @endphp
                                <circle
                                    class="chart-segment"
                                    style="--segment-index: {{ $drawn }}; stroke-dasharray: {{ $length }} {{ 100 - $length }}; stroke-dashoffset: {{ $offset }}"
                                    cx="21"
                                    cy="21"
                                    r="15.915"
                                    fill="none"
                                    stroke="url(#report-chart-{{ \Illuminate\Support\Str::slug($segment['label']) }})"
                                    stroke-width="6"
                                ></circle>
                                @php
                                    $offset -= $raw;
                                    $drawn++;
                                @endphp
                            @endif
                        @endforeach
                    </svg>
                    @foreach ($labels as $label)
                        <span class="chart-label pointer-events-none absolute -translate-x-1/2 -translate-y-1/2 rounded-full bg-white px-1.5 py-0.5 text-[11px] font-semibold leading-none text-navy shadow-sm" style="left: {{ $label['x'] }}%; top: {{ $label['y'] }}%; --segment-index: {{ $label['index'] }}">{{ $label['percent'] }}</span>
                    @endforeach
                </div>

                <ul class="flex flex-1 flex-col">
                    @foreach ($overview->segments() as $segment)
                        <li class="flex items-center justify-between gap-4 border-b border-line py-3 text-sm last:border-b-0">
                            <span class="inline-flex items-center gap-2 text-ink"><span class="size-2 rounded-full" style="background-color: {{ $segment['color'] }}" aria-hidden="true"></span>{{ $segment['label'] }}</span>
                            <span class="font-medium tabular-nums text-navy">{{ $segment['count'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>
</div>
