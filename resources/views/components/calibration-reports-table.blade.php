<section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="calibration-reports-heading">
    <div class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h2 id="calibration-reports-heading" class="text-base font-semibold tracking-tight text-navy">Calibration Reports</h2>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="clearReportFilters" class="ui-button-secondary">Clear Filters</button>
            <button type="button" x-ref="exportButton" wire:click="openExportModal" class="ui-button-primary" wire:loading.attr="disabled" wire:target="openExportModal">
                <svg aria-hidden="true" class="mr-1.5 h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M10 2.5a1 1 0 0 1 1 1v7.09l2.3-2.3a1 1 0 1 1 1.4 1.42l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.42l2.3 2.3V3.5a1 1 0 0 1 1-1ZM4 15.5a1 1 0 0 1 1 1v.5h10v-.5a1 1 0 1 1 2 0v1.5a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-1.5a1 1 0 0 1 1-1Z" />
                </svg>
                Export XLSX
            </button>
        </div>
    </div>

    <div class="grid gap-3 border-y border-brand/15 px-5 py-4 sm:grid-cols-2 lg:grid-cols-[10rem_1fr_2fr_6rem]">
        <label class="text-sm font-medium text-navy">
            Calibration Year
            <select wire:model.live="reportYear" class="ui-input mt-1 w-full">
                @foreach ($reportYears as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm font-medium text-navy">
            Location
            <select wire:model.live="reportLocation" class="ui-input mt-1 w-full">
                <option value="">All Locations</option>
                @foreach ($reportLocations as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm font-medium text-navy">
            Search
            <input wire:model.live.debounce.300ms="reportSearch" type="search" placeholder="Name, brand, type, serial, asset..." class="ui-input mt-1 w-full">
        </label>
        <label class="text-sm font-medium text-navy">
            Per page
            <select wire:model.live="reportPerPage" class="ui-input mt-1 w-full">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
            </select>
        </label>
    </div>

    @if ($reportRows->isEmpty())
        <p class="px-5 py-8 text-sm text-navy/70">No instrument types match the selected filters.</p>
    @else
        <div class="overflow-x-auto">
                <table class="ui-table min-w-[1400px]">
                <thead class="bg-navy text-xs font-medium text-white">
                    <tr>
                        @foreach (\App\Reports\CalibrationReport::HEADERS as $header)
                            <th scope="col" class="px-5 py-3 font-medium">{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/70">
                    @foreach ($reportRows as $row)
                        <tr wire:key="report-row-{{ $row['instrument_id'] }}-{{ $row['instrument_type_id'] }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec] hover:bg-[#edf4fa]">
                            <td class="min-w-[240px] whitespace-normal break-words px-5 py-3.5 font-medium text-navy">
                                @if ($row['calibration_id'] !== null)
                                    <a
                                        href="{{ route('calibrations.show', [
                                            'calibration' => $row['calibration_id'],
                                            'report_year' => $reportYear,
                                            'report_location' => $reportLocation,
                                            'report_search' => $reportSearch,
                                            'return_tab' => 'reports',
                                        ]) }}"
                                        class="text-navy underline-offset-2 hover:text-brand hover:underline focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2"
                                    >
                                        {{ $row['instrument_name'] }}
                                    </a>
                                @else
                                    <a
                                        href="{{ route('calibrations.report-detail', [
                                            'instrument' => $row['instrument_id'],
                                            'instrumentType' => $row['instrument_type_id'],
                                            'year' => $reportYear,
                                            'report_location' => $reportLocation,
                                            'report_search' => $reportSearch,
                                            'return_tab' => 'reports',
                                        ]) }}"
                                        class="text-navy underline-offset-2 hover:text-brand hover:underline focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2"
                                    >
                                        {{ $row['instrument_name'] }}
                                    </a>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-navy">{{ $row['brand'] }}</td>
                            <td class="min-w-[220px] whitespace-normal break-words px-5 py-3.5 text-navy">{{ $row['instrument_type'] }}</td>
                            <td class="px-5 py-3.5 text-navy">{{ $row['model'] }}</td>
                            <td class="px-5 py-3.5 text-navy/70">{{ $row['serial_number'] }}</td>
                            <td class="px-5 py-3.5 text-navy/70">{{ $row['asset_number'] }}</td>
                            <td class="px-5 py-3.5 tabular-nums text-navy">{{ $row['calibration_date'] ?? '—' }}</td>
                            <td class="px-5 py-3.5 tabular-nums text-navy">{{ $row['calibration_due'] ?? '—' }}</td>
                            <td class="px-5 py-3.5">
                                <x-status-badge :status="$row['due_status']" />
                            </td>
                            <td class="px-5 py-3.5 text-navy">{{ $row['location'] }}</td>
                            <td class="px-5 py-3.5 text-navy">{{ $row['detail_location'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($reportRows->hasPages())
            <div class="px-5 py-4">{{ $reportRows->links() }}</div>
        @endif
    @endif
</section>
