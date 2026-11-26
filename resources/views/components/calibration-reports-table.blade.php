<section data-calibrations-reports-panel data-calibrations-content class="ui-card overflow-hidden" aria-labelledby="calibration-reports-heading">
    <div data-calibrations-reports-header class="flex flex-col items-start justify-between gap-3 border-b border-line/80 bg-white px-5 py-4 md:flex-row md:items-center">
        <h2 id="calibration-reports-heading" class="min-w-0 flex-1 text-left text-base font-semibold tracking-tight text-navy">Calibration Reports</h2>
        <div data-calibrations-report-actions class="flex shrink-0 flex-col items-end gap-1.5 md:flex-row md:items-center md:gap-2">
            <button type="button" wire:click="clearReportFilters" class="ui-button-outline gap-1.5 whitespace-nowrap px-3 py-2 text-xs sm:px-4 sm:text-sm">
                <svg aria-hidden="true" class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 5.25h8.25M3.5 10h5.5M3.5 14.75h3.25M14.25 11.5l3 3m0-3-3 3M12.5 5.25h4" />
                </svg>
                Clear Filters
            </button>
            <button type="button" x-ref="exportButton" wire:click="openExportModal" class="ui-button-export gap-1.5 whitespace-nowrap px-3 py-2 text-xs sm:px-4 sm:text-sm" wire:loading.attr="disabled" wire:target="openExportModal">
                <svg aria-hidden="true" class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M10 2.5a1 1 0 0 1 1 1v7.09l2.3-2.3a1 1 0 1 1 1.4 1.42l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.42l2.3 2.3V3.5a1 1 0 0 1 1-1ZM4 15.5a1 1 0 0 1 1 1v.5h10v-.5a1 1 0 1 1 2 0v1.5a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-1.5a1 1 0 0 1 1-1Z" />
                </svg>
                Export XLSX
            </button>
        </div>
    </div>

    <div data-calibrations-toolbar class="grid grid-cols-2 gap-3 border-b border-line/80 bg-white px-5 py-4 lg:grid-cols-[10rem_1fr_2fr_6rem]">
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
        <label class="col-span-2 text-sm font-medium text-navy md:col-span-1">
            Search
            <input wire:model.live.debounce.300ms="reportSearch" type="search" placeholder="Name, brand, type, serial, asset..." class="ui-input mt-1 w-full">
        </label>
        <label class="col-span-2 text-sm font-medium text-navy md:col-span-1">
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
            <table class="management-table min-w-[1400px]">
                <thead>
                    <tr>
                        @foreach (\App\Reports\CalibrationReport::HEADERS as $header)
                            <th scope="col">{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reportRows as $row)
                        <tr wire:key="report-row-{{ $row['instrument_id'] }}-{{ $row['instrument_type_id'] }}">
                            <td class="min-w-[240px] whitespace-normal break-words font-medium text-navy">
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
                            <td class="text-navy/80">{{ $row['brand'] }}</td>
                            <td class="min-w-[220px] whitespace-normal break-words text-navy/80">{{ $row['instrument_type'] }}</td>
                            <td class="text-navy/80">{{ $row['model'] }}</td>
                            <td class="break-words text-navy/70">{{ $row['serial_number'] }}</td>
                            <td class="break-words text-navy/70">{{ $row['asset_number'] }}</td>
                            <td class="px-5 py-3.5 tabular-nums text-navy">{{ $row['calibration_date'] ?? '—' }}</td>
                            <td class="px-5 py-3.5 tabular-nums text-navy">{{ $row['calibration_due'] ?? '—' }}</td>
                            <td>
                                <x-status-badge :status="$row['due_status']" />
                            </td>
                            <td class="text-navy/80">{{ $row['location'] }}</td>
                            <td class="text-navy/80">{{ $row['detail_location'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($reportRows->hasPages())
            <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="management-pagination">
                <p>
                    Showing <span class="font-semibold text-navy">{{ $reportRows->firstItem() }}</span>
                    &ndash; <span class="font-semibold text-navy">{{ $reportRows->lastItem() }}</span>
                    of <span class="font-semibold text-navy">{{ $reportRows->total() }}</span> results
                </p>

                <div class="flex items-center gap-2">
                    @if ($reportRows->onFirstPage())
                        <span class="management-pagination-disabled" aria-disabled="true">Previous</span>
                    @else
                        <a href="{{ $reportRows->previousPageUrl() }}" wire:click.prevent="gotoPage({{ $reportRows->currentPage() - 1 }}, 'reportPage')" rel="prev" aria-label="{{ __('pagination.previous') }}">Previous</a>
                    @endif

                    <span class="hidden sm:inline">Page <span class="font-semibold text-navy">{{ $reportRows->currentPage() }}</span> of <span class="font-semibold text-navy">{{ $reportRows->lastPage() }}</span></span>

                    @if ($reportRows->hasMorePages())
                        <a href="{{ $reportRows->nextPageUrl() }}" wire:click.prevent="gotoPage({{ $reportRows->currentPage() + 1 }}, 'reportPage')" rel="next" aria-label="{{ __('pagination.next') }}">Next</a>
                    @else
                        <span class="management-pagination-disabled" aria-disabled="true">Next</span>
                    @endif
                </div>
            </nav>
        @endif
    @endif
</section>
