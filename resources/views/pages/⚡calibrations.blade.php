<?php

use App\Authorization\ApplicationCapability;
use App\CalibrationSessions\CalibrationSessionHttpRead;
use App\Dashboard\CalibrationAnnualOverview;
use App\Dashboard\CalibrationSessionHistory;
use App\Dashboard\CalibrationStanding;
use App\Dashboard\InstrumentCalibrationOverview;
use App\Models\Calibration;
use App\Models\Instrument;
use App\Models\Location;
use App\Reports\CalibrationReport;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = CalibrationAnnualOverview::TAB_REPORTS;

    #[Url]
    public int $year = CalibrationAnnualOverview::MINIMUM_YEAR;

    #[Url(as: 'status')]
    public string $sessionStatus = CalibrationSessionHistory::STATUS_ALL;

    #[Url]
    public ?int $instrument_id = null;

    #[Url]
    public ?int $instrument_type_id = null;

    #[Url]
    public string $search = '';

    public int $perPage = 10;

    #[Url(as: 'report_year')]
    public int $reportYear = CalibrationAnnualOverview::MINIMUM_YEAR;

    #[Url(as: 'report_location')]
    public string $reportLocation = '';

    #[Url(as: 'report_search')]
    public string $reportSearch = '';

    public int $reportPerPage = 10;

    public bool $exportModalOpen = false;

    public int $exportYear = CalibrationAnnualOverview::MINIMUM_YEAR;

    public string $exportLocation = '';

    public int $worklistYear = CalibrationAnnualOverview::MINIMUM_YEAR;

    #[Url(as: 'worklist_location')]
    public string $worklistLocation = '';

    public string $worklistSearch = '';

    public int $worklistPerPage = 10;

    public function mount(): void
    {
        $this->tab = CalibrationAnnualOverview::sanitizeTab($this->tab);

        if ($this->tab === CalibrationAnnualOverview::TAB_HISTORY
            && ! Gate::allows(ApplicationCapability::SessionHistoryView->value)) {
            $this->tab = CalibrationAnnualOverview::TAB_REPORTS;
        }

        if ($this->tab === CalibrationAnnualOverview::TAB_START
            && ! Gate::allows(ApplicationCapability::SessionStart->value)) {
            $this->tab = CalibrationAnnualOverview::TAB_REPORTS;
        }

        $this->sessionStatus = CalibrationSessionHistory::sanitizeStatus($this->sessionStatus);
        $this->instrument_id = CalibrationSessionHistory::sanitizeIdentityId($this->instrument_id);
        $this->instrument_type_id = CalibrationSessionHistory::sanitizeIdentityId($this->instrument_type_id);
        $this->year = CalibrationAnnualOverview::sanitizeYear(
            $this->year,
            $this->yearChoices(),
            (int) today()->year,
        );
        $this->reportYear = CalibrationReport::sanitizeYear($this->reportYear, $this->readConnection());
        $this->reportLocation = CalibrationReport::sanitizeLocation($this->reportLocation);
        $this->worklistYear = CalibrationAnnualOverview::sanitizeYear(
            $this->worklistYear,
            CalibrationAnnualOverview::availableYears($this->readConnection()),
            (int) today()->year,
        );
        $this->worklistLocation = $this->sanitizeWorklistLocation($this->worklistLocation);
    }

    public function updatedTab(mixed $value): void
    {
        $this->tab = CalibrationAnnualOverview::sanitizeTab($value);

        if ($this->tab === CalibrationAnnualOverview::TAB_HISTORY
            && ! Gate::allows(ApplicationCapability::SessionHistoryView->value)) {
            $this->tab = CalibrationAnnualOverview::TAB_REPORTS;
        }

        if ($this->tab === CalibrationAnnualOverview::TAB_START
            && ! Gate::allows(ApplicationCapability::SessionStart->value)) {
            $this->tab = CalibrationAnnualOverview::TAB_REPORTS;
        }

        if ($this->tab !== CalibrationAnnualOverview::TAB_HISTORY) {
            $this->instrument_id = null;
            $this->instrument_type_id = null;
        }

        $this->year = CalibrationAnnualOverview::sanitizeYear(
            $this->year,
            $this->yearChoices(),
            (int) today()->year,
        );
        $this->resetPage('reportPage');
        $this->resetPage('worklistPage');
        $this->resetPage('historyPage');
    }

    public function updatedYear(mixed $value): void
    {
        $this->year = CalibrationAnnualOverview::sanitizeYear(
            $value,
            $this->yearChoices(),
            (int) today()->year,
        );
        $this->resetPage($this->tab === CalibrationAnnualOverview::TAB_HISTORY ? 'historyPage' : 'worklistPage');
    }

    public function updatedSessionStatus(mixed $value): void
    {
        $this->sessionStatus = CalibrationSessionHistory::sanitizeStatus($value);
        $this->resetPage('historyPage');
    }

    public function updatedInstrumentId(): void
    {
        $this->instrument_id = CalibrationSessionHistory::sanitizeIdentityId($this->instrument_id);
        $this->resetPage($this->tab === CalibrationAnnualOverview::TAB_HISTORY ? 'historyPage' : 'worklistPage');
    }

    public function updatedInstrumentTypeId(): void
    {
        $this->instrument_type_id = CalibrationSessionHistory::sanitizeIdentityId($this->instrument_type_id);
        $this->resetPage($this->tab === CalibrationAnnualOverview::TAB_HISTORY ? 'historyPage' : 'worklistPage');
    }

    public function updatedSearch(): void
    {
        $this->resetPage($this->tab === CalibrationAnnualOverview::TAB_HISTORY ? 'historyPage' : 'worklistPage');
    }

    public function updatedPerPage(mixed $value): void
    {
        $perPage = (int) $value;

        $this->perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;
        $this->resetPage($this->tab === CalibrationAnnualOverview::TAB_HISTORY ? 'historyPage' : 'worklistPage');
    }

    public function updatedWorklistYear(mixed $value): void
    {
        $this->worklistYear = CalibrationAnnualOverview::sanitizeYear(
            $value,
            CalibrationAnnualOverview::availableYears($this->readConnection()),
            (int) today()->year,
        );
        $this->resetPage('worklistPage');
    }

    public function updatedWorklistSearch(): void
    {
        $this->resetPage('worklistPage');
    }

    public function updatedWorklistLocation(mixed $value): void
    {
        $this->worklistLocation = $this->sanitizeWorklistLocation($value);
        $this->resetPage('worklistPage');
    }

    public function updatedWorklistPerPage(mixed $value): void
    {
        $perPage = (int) $value;

        $this->worklistPerPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;
        $this->resetPage('worklistPage');
    }

    public function clearWorklistFilters(): void
    {
        $this->worklistYear = CalibrationAnnualOverview::sanitizeYear(
            CalibrationAnnualOverview::MINIMUM_YEAR,
            CalibrationAnnualOverview::availableYears($this->readConnection()),
            (int) today()->year,
        );
        $this->worklistLocation = '';
        $this->worklistSearch = '';
        $this->worklistPerPage = 10;
        $this->resetPage('worklistPage');
    }

    public function updatedReportYear(mixed $value): void
    {
        $this->reportYear = CalibrationReport::sanitizeYear($value, $this->readConnection());
        $this->reportLocation = '';
        $this->resetPage('reportPage');
    }

    public function updatedReportLocation(mixed $value): void
    {
        $this->reportLocation = CalibrationReport::sanitizeLocation($value);
        $this->resetPage('reportPage');
    }

    public function updatedReportSearch(): void
    {
        $this->resetPage('reportPage');
    }

    public function updatedReportPerPage(mixed $value): void
    {
        $perPage = (int) $value;

        $this->reportPerPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;
        $this->resetPage('reportPage');
    }

    public function clearReportFilters(): void
    {
        $this->reportYear = CalibrationReport::sanitizeYear(2026, $this->readConnection());
        $this->reportLocation = '';
        $this->reportSearch = '';
        $this->resetPage('reportPage');
    }

    public function openExportModal(): void
    {
        $this->exportYear = $this->reportYear;
        $this->exportLocation = $this->reportLocation;
        $this->exportModalOpen = true;
    }

    public function closeExportModal(): void
    {
        $this->exportModalOpen = false;
    }

    public function updatedExportYear(mixed $value): void
    {
        $this->exportYear = CalibrationReport::sanitizeYear($value, $this->readConnection());
    }

    public function updatedExportLocation(mixed $value): void
    {
        $location = CalibrationReport::sanitizeLocation($value);
        $locations = CalibrationReport::locations($this->readConnection());

        $this->exportLocation = $location === '' || in_array($location, $locations, true)
            ? $location
            : '';
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function years(): array
    {
        return $this->yearChoices();
    }

    #[Computed]
    public function hasSessions(): bool
    {
        return Calibration::on($this->readConnectionName())->exists();
    }

    #[Computed]
    public function reportOverview(): InstrumentCalibrationOverview
    {
        return InstrumentCalibrationOverview::current($this->readConnection());
    }

    /** @return list<int> */
    #[Computed]
    public function reportYears(): array
    {
        return CalibrationReport::years($this->readConnection());
    }

    /** @return list<string> */
    #[Computed]
    public function reportLocations(): array
    {
        return CalibrationReport::locations($this->readConnection(), $this->reportYear);
    }

    /** @return list<string> */
    #[Computed]
    public function worklistLocations(): array
    {
        return Location::on($this->readConnectionName())
            ->whereNotNull('location_name')
            ->whereRaw("trim(location_name) <> ''")
            ->orderByRaw('lower(location_name)')
            ->orderBy('location_id')
            ->pluck('location_name')
            ->map(fn (mixed $location): string => (string) $location)
            ->unique()
            ->values()
            ->all();
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    #[Computed]
    public function reportRows(): LengthAwarePaginator
    {
        return CalibrationReport::paginate(
            $this->readConnection(),
            $this->reportYear,
            $this->reportLocation,
            $this->reportSearch,
            $this->reportPerPage,
            'reportPage',
        );
    }

    /**
     * @return LengthAwarePaginator<int, array{key: string, calibration_id: int, name: string, type: string, serial_number: string, calibration_number: string, session_date: string, status: string, method: string, engineer: string, detail_url: string}>
     */
    #[Computed]
    public function sessions(): LengthAwarePaginator
    {
        $sessions = CalibrationSessionHistory::paginate(
            $this->year,
            $this->sessionStatus,
            $this->search,
            $this->instrument_id,
            $this->instrument_type_id,
            $this->perPage,
            $this->readConnection(),
            'historyPage',
        );

        return $sessions->through(function (array $session): array {
            $query = [
                'return_tab' => 'history',
                'tab' => 'history',
                'year' => $this->year,
                'status' => $this->sessionStatus,
                'search' => $this->search,
            ];

            if ($this->instrument_id !== null) {
                $query['instrument_id'] = $this->instrument_id;
            }

            if ($this->instrument_type_id !== null) {
                $query['instrument_type_id'] = $this->instrument_type_id;
            }

            $session['detail_url'] = route('calibrations.show', [
                'calibration' => $session['calibration_id'],
                ...$query,
            ]);

            return $session;
        });
    }

    /**
     * @return array{types: int, completed: int, inProgress: int, notCalibrated: int}
     */
    #[Computed]
    public function totals(): array
    {
        return CalibrationAnnualOverview::typeTotals($this->worklistYear, $this->readConnection());
    }

    /**
     * @return LengthAwarePaginator<int, array{key: string, instrument_id: int, instrument_type_id: int|null, name: string, brand: string, type: string, model: string, serial_number: string, asset_number: string, calibration_id: int|null, date: string, due: string, progress: string, due_status: string, location: string, detail_location: string, active_year: int|null, active_id: int|null, can_start: bool, start_url: string|null, active_url: string|null}>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $instruments = Instrument::on($this->readConnectionName())
            ->select([
                'instrument.instrument_id',
                'instrument.instrument_name',
                'instrument_type.instrument_type_id',
                'instrument_type.type_name',
                'instrument_type.brand',
                'instrument_type.model',
                'instrument.serial_number',
                'instrument_type.asset_number',
                'location.location_name',
                'detail_location.detail_location_name',
            ])
            ->leftJoin('instrument_type', 'instrument_type.instrument_id', '=', 'instrument.instrument_id')
            ->leftJoin('detail_location', 'detail_location.detail_location_id', '=', 'instrument.detail_location_id')
            ->leftJoin('location', 'location.location_id', '=', 'detail_location.location_id')
            ->when($this->worklistLocation !== '', fn (EloquentBuilder $query): EloquentBuilder => $query->where('location.location_name', $this->worklistLocation))
            ->whereIdentityMatches($this->worklistSearch)
            ->orderBy('instrument.instrument_id')
            ->orderBy('instrument_type.instrument_type_id')
            ->paginate($this->worklistPerPage, ['*'], 'worklistPage');

        $typeIds = $instruments->getCollection()
            ->pluck('instrument_type_id')
            ->filter(fn (mixed $id): bool => $id !== null)
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $connection = $this->readConnection();
        $active = CalibrationAnnualOverview::activeByType($typeIds, $connection);
        $completed = CalibrationAnnualOverview::completedByType($typeIds, $this->worklistYear, $connection);
        $overall = CalibrationAnnualOverview::latestCompletedOverallByType($typeIds, $connection);
        $canStartSessions = Gate::allows(ApplicationCapability::SessionStart->value);

        return $instruments->through(function (Instrument $instrument) use ($active, $completed, $overall, $canStartSessions): array {
            $typeId = $instrument->getAttribute('instrument_type_id');
            $instrumentId = (int) $instrument->instrument_id;
            $resolvedTypeId = $typeId === null ? null : (int) $typeId;
            $activeSession = $resolvedTypeId === null ? null : ($active[$resolvedTypeId] ?? null);
            $yearCompleted = $resolvedTypeId === null ? null : ($completed[$resolvedTypeId] ?? null);
            $overallCompleted = $resolvedTypeId === null ? null : ($overall[$resolvedTypeId] ?? null);
            $yearActive = CalibrationAnnualOverview::yearActive($activeSession, $this->worklistYear);
            $progressSession = $yearActive ?? $yearCompleted;
            $canStart = $canStartSessions && $resolvedTypeId !== null && $activeSession === null;

            return [
                'key' => $instrumentId.'-'.($resolvedTypeId ?? 'none'),
                'instrument_id' => $instrumentId,
                'instrument_type_id' => $resolvedTypeId,
                'name' => $this->display($instrument->instrument_name),
                'brand' => $this->display($instrument->getAttribute('brand')),
                'type' => $this->display($instrument->getAttribute('type_name')),
                'model' => $this->display($instrument->getAttribute('model')),
                'serial_number' => $this->display($instrument->serial_number),
                'asset_number' => $this->display($instrument->getAttribute('asset_number')),
                'calibration_id' => $progressSession?->calibration_id,
                'date' => $yearCompleted?->calibration_date?->format('Y-m-d') ?? '—',
                'due' => $yearCompleted?->calibration_due?->format('Y-m-d') ?? '—',
                'progress' => CalibrationAnnualOverview::progress($yearActive, $yearCompleted)->value,
                'due_status' => CalibrationStanding::fromLatest($overallCompleted)->value,
                'location' => $this->display($instrument->getAttribute('location_name')),
                'detail_location' => $this->display($instrument->getAttribute('detail_location_name')),
                'active_year' => CalibrationAnnualOverview::crossYearActiveYear($activeSession, $this->worklistYear),
                'active_id' => $activeSession?->calibration_id,
                'can_start' => $canStart,
                'start_url' => $canStart ? route('instruments.types.calibrations.start', [
                    'instrument' => $instrumentId,
                    'instrumentType' => $resolvedTypeId,
                ]) : null,
                 'active_url' => $activeSession === null
                     ? null
                     : route('calibrations.show', [
                         'calibration' => $activeSession->calibration_id,
                         'return_tab' => 'start',
                     ]),
            ];
        });
    }

    private function display(mixed $value): string
    {
        return filled($value) ? (string) $value : '—';
    }

    private function sanitizeWorklistLocation(mixed $location): string
    {
        $location = is_string($location) ? trim($location) : '';

        return $location === '' || in_array($location, $this->worklistLocations, true)
            ? $location
            : '';
    }

    /**
     * @return list<int>
     */
    private function yearChoices(): array
    {
        if ($this->tab === CalibrationAnnualOverview::TAB_HISTORY) {
            return CalibrationSessionHistory::availableYears($this->readConnection());
        }

        return CalibrationAnnualOverview::availableYears($this->readConnection());
    }

    private function readConnection(): Connection
    {
        return app(CalibrationSessionHttpRead::class)->connection();
    }

    private function readConnectionName(): string
    {
        return app(CalibrationSessionHttpRead::class)->connectionName();
    }
};
?>

<div
    data-calibrations-page
    class="px-4 py-8 sm:px-6 lg:px-10"
    x-data="{
        exportModalOpen: $wire.entangle('exportModalOpen'),
        exportDownloading: false,
    }"
    x-on:keydown.escape.window="if (exportModalOpen) $wire.closeExportModal()"
    x-init="$watch('exportModalOpen', (open) => $nextTick(() => open ? $refs.exportYear?.focus() : $refs.exportButton?.focus()))"
>
    <div class="mx-auto flex max-w-[1760px] flex-col gap-6 xl:gap-8">
        <section data-calibrations-hero class="overflow-hidden rounded-2xl bg-gradient-to-br from-[#14263F] via-[#1D4E73] to-[#365F83] p-4 text-white shadow-[0_22px_44px_-28px_rgb(20_38_63/0.8)] sm:p-6">
            <header data-calibrations-header>
                <h1 class="text-2xl font-semibold tracking-tight text-white">Calibrations</h1>
                <p class="mt-1 text-sm text-blue-100/75">
                    @if (Gate::allows(ApplicationCapability::SessionHistoryView->value))
                        Reports, calibration worklist, and session history.
                    @else
                        Calibration reports and instrument status overview.
                    @endif
                </p>
            </header>

            <div class="mt-5 h-px bg-white/20" aria-hidden="true"></div>

            @php
                $calibrationMetrics = [
                    ['label' => 'Total Instruments', 'value' => $this->reportOverview->total, 'iconSurface' => 'bg-white/15'],
                    ['label' => 'On Track', 'value' => $this->reportOverview->onTrack, 'iconSurface' => 'bg-emerald-400/20'],
                    ['label' => 'Due Soon', 'value' => $this->reportOverview->dueSoon, 'iconSurface' => 'bg-amber-400/20'],
                    ['label' => 'Overdue', 'value' => $this->reportOverview->overdue, 'iconSurface' => 'bg-rose-400/20'],
                    ['label' => 'Not Calibrated', 'value' => $this->reportOverview->notCalibrated, 'iconSurface' => 'bg-blue-200/25'],
                ];
            @endphp

            <section data-calibrations-stats aria-label="Calibration statistics" class="mt-4 grid grid-cols-2 gap-y-4 sm:gap-y-5 xl:grid-cols-5 xl:gap-y-0">
                @foreach ($calibrationMetrics as $metric)
                    <article data-calibrations-stat data-calibrations-stat-index="{{ $loop->index }}" class="relative flex min-w-0 flex-col items-center gap-1 px-2 py-1 text-center sm:gap-2 sm:px-4 xl:px-6 xl:first:pl-0 xl:last:pr-0">
                        @if ($loop->index > 0)
                            <span class="pointer-events-none absolute left-0 top-1/2 hidden h-10 w-px -translate-y-1/2 bg-white/15 xl:block" aria-hidden="true"></span>
                        @endif
                        @if ($loop->index % 2 === 1)
                            <span class="pointer-events-none absolute left-0 top-0 h-full w-px bg-white/15 xl:hidden" aria-hidden="true"></span>
                        @endif
                        <div class="flex items-center gap-2">
                            <span class="size-2 shrink-0 rounded-full {{ $metric['iconSurface'] }}" aria-hidden="true"></span>
                            <p class="text-[10px] font-medium leading-tight text-blue-50/80 sm:text-sm">{{ $metric['label'] }}</p>
                        </div>
                        <p class="text-2xl font-semibold tracking-tight tabular-nums text-white sm:text-4xl" data-calibration-counter="{{ $metric['value'] }}">{{ $metric['value'] }}</p>
                    </article>
                @endforeach
            </section>
        </section>

        @if (Gate::allows(ApplicationCapability::SessionHistoryView->value)
            || Gate::allows(ApplicationCapability::SessionStart->value))
            <nav data-calibrations-tabs class="desk-tabs" aria-label="Calibrations views">
                <div role="tablist" class="flex min-w-max gap-1">
                <button
                    type="button"
                    role="tab"
                    wire:click="$set('tab', '{{ CalibrationAnnualOverview::TAB_REPORTS }}')"
                    aria-selected="{{ $tab === CalibrationAnnualOverview::TAB_REPORTS ? 'true' : 'false' }}"
                    class="desk-tab {{ $tab === CalibrationAnnualOverview::TAB_REPORTS ? 'desk-tab-active' : 'desk-tab-inactive' }}"
                >
                    Reports
                </button>

                    @if (Gate::allows(ApplicationCapability::SessionStart->value))
                    <button
                        type="button"
                        role="tab"
                        wire:click="$set('tab', '{{ CalibrationAnnualOverview::TAB_START }}')"
                        aria-selected="{{ $tab === CalibrationAnnualOverview::TAB_START ? 'true' : 'false' }}"
                        class="desk-tab {{ $tab === CalibrationAnnualOverview::TAB_START ? 'desk-tab-active' : 'desk-tab-inactive' }}"
                    >
                        Start Calibration
                    </button>
                    @endif

                    @if (Gate::allows(ApplicationCapability::SessionHistoryView->value))
                        <button
                            type="button"
                            role="tab"
                            wire:click="$set('tab', '{{ CalibrationAnnualOverview::TAB_HISTORY }}')"
                            aria-selected="{{ $tab === CalibrationAnnualOverview::TAB_HISTORY ? 'true' : 'false' }}"
                            class="desk-tab {{ $tab === CalibrationAnnualOverview::TAB_HISTORY ? 'desk-tab-active' : 'desk-tab-inactive' }}"
                        >
                            Session History
                        </button>
                    @endif
                </div>
            </nav>
        @endif

        @if ($tab === CalibrationAnnualOverview::TAB_REPORTS)
            @include('components.calibration-reports-table', [
                'reportRows' => $this->reportRows,
                'reportYears' => $this->reportYears,
                'reportLocations' => $this->reportLocations,
                'reportYear' => $reportYear,
                'reportLocation' => $reportLocation,
                'reportSearch' => $reportSearch,
                'reportPerPage' => $reportPerPage,
            ])
        @elseif ($tab === CalibrationAnnualOverview::TAB_HISTORY)
            <section data-calibrations-content class="overflow-hidden rounded-2xl border border-line/80 bg-white shadow-[0_1px_2px_rgb(22_49_114/0.06),0_18px_36px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="session-history-heading">
                <div class="border-b border-line/80 bg-white px-5 py-4">
                    <h2 id="session-history-heading" class="text-base font-semibold tracking-tight text-navy">Session History</h2>
                </div>

                <div class="grid gap-3 border-b border-line/80 bg-white px-5 py-4 sm:grid-cols-2 lg:grid-cols-[10rem_10rem_1fr_6rem]">
                    <label class="text-sm font-medium text-navy">
                        Calibration Year
                        <select id="session-history-year" wire:model.live="year" class="ui-input mt-1 w-full">
                            @foreach ($this->years as $yearOption)
                                <option value="{{ $yearOption }}">{{ $yearOption }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-medium text-navy">
                        Status
                        <select id="session-history-status" wire:model.live="sessionStatus" class="ui-input mt-1 w-full">
                            <option value="{{ CalibrationSessionHistory::STATUS_ALL }}">All</option>
                            <option value="{{ Calibration::STATUS_IN_PROGRESS }}">In Progress</option>
                            <option value="{{ Calibration::STATUS_COMPLETED }}">Completed</option>
                            <option value="{{ Calibration::STATUS_CANCELLED }}">Cancelled</option>
                        </select>
                    </label>
                    <label class="text-sm font-medium text-navy">
                        Search
                        <input
                            id="session-history-search"
                            type="search"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Name, type, or calibration number"
                            class="ui-input mt-1 w-full"
                        >
                    </label>
                    <label class="text-sm font-medium text-navy">
                        Per page
                        <select id="session-history-page-size" wire:model.live="perPage" class="ui-input mt-1 w-full">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                    </label>
                </div>

                @if ($this->sessions->isEmpty())
                    @if ($this->hasSessions)
                        <p class="px-5 py-8 text-sm text-navy/70">No Matching Calibration Sessions</p>
                        <p class="px-5 pb-8 text-sm text-navy/70">No sessions match the current year, status, or identity filters.</p>
                    @else
                        <p class="px-5 py-8 text-sm text-navy/70">No Calibration Sessions Yet</p>
                        <p class="px-5 pb-8 text-sm text-navy/70">Sessions appear after a calibration has been started.</p>
                    @endif
                @else
                    <div class="overflow-x-auto">
                        <table class="management-table w-full min-w-[920px]">
                            <thead>
                                <tr>
                                    <th scope="col">Instrument Name</th>
                                    <th scope="col">Instrument Type</th>
                                    <th scope="col">Serial Number</th>
                                    <th scope="col">Calibration Number</th>
                                    <th scope="col">Session Date</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Method</th>
                                    <th scope="col">Engineer</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->sessions as $session)
                                    <tr wire:key="session-row-{{ $session['key'] }}">
                                        <td class="font-medium text-navy">
                                            <a href="{{ $session['detail_url'] }}" class="text-navy underline-offset-2 hover:text-brand hover:underline focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2">
                                                {{ $session['name'] }}
                                            </a>
                                        </td>
                                        <td class="text-navy/80">{{ $session['type'] }}</td>
                                        <td class="break-words text-navy/70">{{ $session['serial_number'] }}</td>
                                        <td class="text-navy/80">{{ $session['calibration_number'] }}</td>
                                        <td class="tabular-nums text-navy">{{ $session['session_date'] }}</td>
                                        <td>
                                            <x-status-badge :status="$session['status']" />
                                        </td>
                                        <td class="text-navy/80">{{ $session['method'] }}</td>
                                        <td class="text-navy/80">{{ $session['engineer'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($this->sessions->hasPages())
                        <div>
                            {{ $this->sessions->links('vendor.pagination.management-tailwind') }}
                        </div>
                    @endif
                @endif
            </section>
        @else
            <section data-calibrations-content class="overflow-hidden rounded-2xl border border-line/80 bg-white shadow-[0_1px_2px_rgb(22_49_114/0.06),0_18px_36px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="calibrations-heading">
                <div class="flex flex-col gap-3 border-b border-line/80 bg-white px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 id="calibrations-heading" class="text-base font-semibold tracking-tight text-navy">Calibration Worklist</h2>
                        <dl data-calibrations-worklist-summary class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-navy/70">
                            <div class="flex items-center gap-1.5">
                                <dt>Completed</dt>
                                <dd class="font-semibold tabular-nums text-navy">{{ $this->totals['completed'] }}</dd>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <dt>In Progress</dt>
                                <dd class="font-semibold tabular-nums text-navy">{{ $this->totals['inProgress'] }}</dd>
                            </div>
                        </dl>
                    </div>
                    <button type="button" wire:click="clearWorklistFilters" class="ui-button-secondary">Clear Filters</button>
                </div>

                <div class="grid gap-3 border-b border-line/80 bg-white px-5 py-4 sm:grid-cols-2 lg:grid-cols-[10rem_1fr_2fr_6rem]">
                    <label class="text-sm font-medium text-navy">
                        Calibration Year
                        <select id="calibration-year" wire:model.live="worklistYear" class="ui-input mt-1 w-full">
                            @foreach ($this->years as $yearOption)
                                <option value="{{ $yearOption }}">{{ $yearOption }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-medium text-navy">
                        Location
                        <select id="calibration-location" wire:model.live="worklistLocation" class="ui-input mt-1 w-full">
                            <option value="">All Locations</option>
                            @foreach ($this->worklistLocations as $locationOption)
                                <option value="{{ $locationOption }}">{{ $locationOption }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-medium text-navy">
                        Search
                        <input
                            id="calibration-search"
                            type="search"
                            wire:model.live.debounce.300ms="worklistSearch"
                            placeholder="Name, brand, type, or model"
                            class="ui-input mt-1 w-full"
                        >
                    </label>
                    <label class="text-sm font-medium text-navy">
                        Per page
                        <select id="calibration-page-size" wire:model.live="worklistPerPage" class="ui-input mt-1 w-full">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                    </label>
                </div>

                @if ($this->rows->isEmpty())
                    <p class="px-5 py-8 text-sm text-navy/70">No instruments found.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="management-table min-w-[1180px]">
                            <thead>
                                <tr>
                                    <th scope="col">Instrument Name</th>
                                    <th scope="col">Brand</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Model</th>
                                    <th scope="col">Serial Number</th>
                                    <th scope="col">Asset Number</th>
                                    <th scope="col">Calibration Date</th>
                                    <th scope="col">Calibration Due</th>
                                    <th scope="col">Annual Progress</th>
                                    <th scope="col">Current Due Status</th>
                                    <th scope="col">Location</th>
                                    <th scope="col">Detail Location</th>
                                    <th scope="col">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->rows as $row)
                                    <tr wire:key="calibration-row-{{ $row['key'] }}">
                                        <td class="font-medium text-navy">
                                            @if ($row['calibration_id'] === null)
                                                {{ $row['name'] }}
                                            @else
                                                <a href="{{ route('calibrations.show', $row['calibration_id']) }}" class="text-navy underline-offset-2 hover:text-brand hover:underline focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2">
                                                    {{ $row['name'] }}
                                                </a>
                                            @endif
                                        </td>
                                        <td class="text-navy/80">{{ $row['brand'] }}</td>
                                        <td class="text-navy/80">{{ $row['type'] }}</td>
                                        <td class="text-navy/80">{{ $row['model'] }}</td>
                                        <td class="break-words text-navy/70">{{ $row['serial_number'] }}</td>
                                        <td class="break-words text-navy/70">{{ $row['asset_number'] }}</td>
                                        <td class="tabular-nums text-navy">{{ $row['date'] }}</td>
                                        <td class="tabular-nums text-navy">{{ $row['due'] }}</td>
                                        <td>
                                            <div class="flex flex-col gap-1">
                                                <x-status-badge :status="$row['progress']" />
                                                @if ($row['active_year'] !== null)
                                                    <p class="text-xs text-navy/70">
                                                        @if ($row['active_id'] === null)
                                                            Active session ({{ $row['active_year'] }})
                                                        @else
                                                            <a href="{{ route('calibrations.show', $row['active_id']) }}" class="text-navy/70 underline-offset-2 hover:text-brand hover:underline focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2">
                                                                Active session ({{ $row['active_year'] }})
                                                            </a>
                                                        @endif
                                                    </p>
                                                @endif
                                            </div>
                                        </td>
                                        <td>
                                            <x-status-badge :status="$row['due_status']" />
                                        </td>
                                        <td class="text-navy/80">{{ $row['location'] }}</td>
                                        <td class="text-navy/80">{{ $row['detail_location'] }}</td>
                                        <td>
                                            @if ($row['can_start'] && $row['start_url'] !== null)
                                                <a href="{{ $row['start_url'] }}" class="ui-button-export inline-flex w-fit items-center gap-1.5 whitespace-nowrap px-3 py-2 text-xs">
                                                    <svg aria-hidden="true" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                                        <path d="M6.5 4.75a1 1 0 0 1 1.54-.84l7.5 5.25a1 1 0 0 1 0 1.68l-7.5 5.25A1 1 0 0 1 6.5 15.25v-10.5Z" />
                                                    </svg>
                                                    Start Calibration
                                                </a>
                                            @elseif ($row['active_url'] !== null)
                                                <a href="{{ $row['active_url'] }}" class="font-medium text-navy underline-offset-2 hover:text-brand hover:underline focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2">Open Session</a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($this->rows->hasPages())
                        <div>
                            {{ $this->rows->links('vendor.pagination.management-tailwind') }}
                        </div>
                    @endif
                @endif
            </section>
        @endif
    </div>

    <div
        x-cloak
        x-show="exportModalOpen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-180"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-navy/40 px-4 py-8 sm:items-center"
        role="presentation"
        x-on:click.self="$wire.closeExportModal()"
    >
        <div
            x-show="exportModalOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="scale-95 opacity-0"
            x-transition:enter-end="scale-100 opacity-100"
            x-transition:leave="transition ease-in duration-180"
            x-transition:leave-start="scale-100 opacity-100"
            x-transition:leave-end="scale-95 opacity-0"
            class="w-full max-w-xl overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-2xl motion-reduce:transition-none"
            role="dialog"
            aria-modal="true"
            aria-labelledby="calibration-report-export-heading"
            x-on:click.stop
        >
            <div class="flex items-start justify-between bg-navy px-5 py-4">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg bg-white/15 text-white" aria-hidden="true">
                        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 3.75h8.25L19 8.5v11.75H6V3.75Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 3.75V9h5M9 13h7M9 16h5" />
                        </svg>
                    </span>
                    <div>
                        <h2 id="calibration-report-export-heading" class="text-base font-semibold tracking-tight text-white">Export Calibration Report</h2>
                        <p class="mt-1 text-sm text-white/80">Select the year and location for your Excel report.</p>
                    </div>
                </div>
                <button type="button" class="text-xl leading-none text-white/80 hover:text-white" aria-label="Close" x-on:click="$wire.closeExportModal()">&times;</button>
            </div>

            <div class="space-y-5 px-5 py-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label for="calibration-report-export-year" class="ui-label">
                        Calibration Year
                        <select x-ref="exportYear" id="calibration-report-export-year" wire:model.live="exportYear" class="ui-input mt-1 w-full">
                            @foreach ($this->reportYears as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label for="calibration-report-export-location" class="ui-label">
                        Location
                        <select id="calibration-report-export-location" wire:model.live="exportLocation" class="ui-input mt-1 w-full">
                            <option value="">All Locations</option>
                            @foreach ($this->reportLocations as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="rounded-xl border border-brand/20 bg-white px-4 py-3 text-sm text-navy">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium">Selected report</span>
                        <span class="text-xs font-semibold uppercase tracking-wide text-muted">Excel (.xlsx)</span>
                    </div>
                    <p class="mt-2 text-navy/75">{{ $exportYear }} · {{ $exportLocation !== '' ? $exportLocation : 'All Locations' }}</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-3 border-t border-brand/20 px-5 py-4">
                <button type="button" class="ui-button-secondary" x-on:click="$wire.closeExportModal()">Cancel</button>
                <a
                    href="{{ route('calibration-reports.export', array_filter(['year' => $exportYear, 'location' => $exportLocation], fn (mixed $value): bool => filled($value))) }}"
                    class="ui-button-primary"
                    x-bind:aria-disabled="exportDownloading ? 'true' : 'false'"
                    x-bind:class="exportDownloading ? 'pointer-events-none opacity-60' : ''"
                    x-on:click="exportDownloading = true; exportModalOpen = false"
                >
                    <span x-show="!exportDownloading" class="inline-flex items-center gap-1.5">
                        <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M10 2.5a1 1 0 0 1 1 1v7.09l2.3-2.3a1 1 0 1 1 1.4 1.42l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.42l2.3 2.3V3.5a1 1 0 0 1 1-1ZM4 15.5a1 1 0 0 1 1 1v.5h10v-.5a1 1 0 1 1 2 0v1.5a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-1.5a1 1 0 0 1 1-1Z" />
                        </svg>
                        Download XLSX
                    </span>
                    <span x-show="exportDownloading" x-cloak>Preparing XLSX...</span>
                </a>
            </div>
        </div>
    </div>
</div>
