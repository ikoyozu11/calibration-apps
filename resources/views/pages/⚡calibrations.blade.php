<?php

use App\Authorization\ApplicationCapability;
use App\CalibrationSessions\CalibrationSessionHttpRead;
use App\Dashboard\CalibrationAnnualOverview;
use App\Dashboard\CalibrationSessionHistory;
use App\Dashboard\CalibrationStanding;
use App\Models\Calibration;
use App\Models\Instrument;
use Illuminate\Database\Connection;
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
    public string $tab = CalibrationAnnualOverview::TAB_OVERVIEW;

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

    public function mount(): void
    {
        $this->tab = CalibrationAnnualOverview::sanitizeTab($this->tab);
        $this->sessionStatus = CalibrationSessionHistory::sanitizeStatus($this->sessionStatus);
        $this->instrument_id = CalibrationSessionHistory::sanitizeIdentityId($this->instrument_id);
        $this->instrument_type_id = CalibrationSessionHistory::sanitizeIdentityId($this->instrument_type_id);
        $this->year = CalibrationAnnualOverview::sanitizeYear(
            $this->year,
            $this->yearChoices(),
            (int) today()->year,
        );
    }

    public function updatedTab(mixed $value): void
    {
        $this->tab = CalibrationAnnualOverview::sanitizeTab($value);
        $this->year = CalibrationAnnualOverview::sanitizeYear(
            $this->year,
            $this->yearChoices(),
            (int) today()->year,
        );
        $this->resetPage();
    }

    public function updatedYear(mixed $value): void
    {
        $this->year = CalibrationAnnualOverview::sanitizeYear(
            $value,
            $this->yearChoices(),
            (int) today()->year,
        );
        $this->resetPage();
    }

    public function updatedSessionStatus(mixed $value): void
    {
        $this->sessionStatus = CalibrationSessionHistory::sanitizeStatus($value);
        $this->resetPage();
    }

    public function updatedInstrumentId(): void
    {
        $this->instrument_id = CalibrationSessionHistory::sanitizeIdentityId($this->instrument_id);
        $this->resetPage();
    }

    public function updatedInstrumentTypeId(): void
    {
        $this->instrument_type_id = CalibrationSessionHistory::sanitizeIdentityId($this->instrument_type_id);
        $this->resetPage();
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

    /**
     * @return LengthAwarePaginator<int, array{key: string, calibration_id: int, name: string, type: string, serial_number: string, calibration_number: string, session_date: string, status: string, method: string, engineer: string, detail_url: string}>
     */
    #[Computed]
    public function sessions(): LengthAwarePaginator
    {
        return CalibrationSessionHistory::paginate(
            $this->year,
            $this->sessionStatus,
            $this->search,
            $this->instrument_id,
            $this->instrument_type_id,
            $this->perPage,
            $this->readConnection(),
        );
    }

    /**
     * @return array{types: int, completed: int, inProgress: int, notCalibrated: int}
     */
    #[Computed]
    public function totals(): array
    {
        return CalibrationAnnualOverview::typeTotals($this->year, $this->readConnection());
    }

    /**
     * @return LengthAwarePaginator<int, array{key: string, instrument_id: int, instrument_type_id: int|null, name: string, brand: string, type: string, model: string, serial_number: string, asset_number: string, calibration_id: int|null, date: string, due: string, progress: string, due_status: string, active_year: int|null, active_id: int|null, can_start: bool, start_url: string|null, active_url: string|null}>
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
            ])
            ->leftJoin('instrument_type', 'instrument_type.instrument_id', '=', 'instrument.instrument_id')
            ->whereIdentityMatches($this->search)
            ->orderBy('instrument.instrument_id')
            ->orderBy('instrument_type.instrument_type_id')
            ->paginate($this->perPage);

        $typeIds = $instruments->getCollection()
            ->pluck('instrument_type_id')
            ->filter(fn (mixed $id): bool => $id !== null)
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $connection = $this->readConnection();
        $active = CalibrationAnnualOverview::activeByType($typeIds, $connection);
        $completed = CalibrationAnnualOverview::completedByType($typeIds, $this->year, $connection);
        $overall = CalibrationAnnualOverview::latestCompletedOverallByType($typeIds, $connection);
        $canStartSessions = Gate::allows(ApplicationCapability::SessionStart->value);

        return $instruments->through(function (Instrument $instrument) use ($active, $completed, $overall, $canStartSessions): array {
            $typeId = $instrument->getAttribute('instrument_type_id');
            $instrumentId = (int) $instrument->instrument_id;
            $resolvedTypeId = $typeId === null ? null : (int) $typeId;
            $activeSession = $resolvedTypeId === null ? null : ($active[$resolvedTypeId] ?? null);
            $yearCompleted = $resolvedTypeId === null ? null : ($completed[$resolvedTypeId] ?? null);
            $overallCompleted = $resolvedTypeId === null ? null : ($overall[$resolvedTypeId] ?? null);
            $yearActive = CalibrationAnnualOverview::yearActive($activeSession, $this->year);
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
                'active_year' => CalibrationAnnualOverview::crossYearActiveYear($activeSession, $this->year),
                'active_id' => $activeSession?->calibration_id,
                'can_start' => $canStart,
                'start_url' => $canStart ? route('instruments.types.calibrations.start', [
                    'instrument' => $instrumentId,
                    'instrumentType' => $resolvedTypeId,
                ]) : null,
                'active_url' => $activeSession === null
                    ? null
                    : route('calibrations.show', $activeSession->calibration_id),
            ];
        });
    }

    private function display(mixed $value): string
    {
        return filled($value) ? (string) $value : '—';
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

<div class="px-4 py-8 sm:px-6 lg:px-10">
    <div class="mx-auto flex max-w-6xl flex-col gap-8">
        <header class="flex flex-col gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-navy">Calibrations</h1>
                <p class="mt-1 text-sm text-muted">Annual calibration work progress for every registered instrument type.</p>
            </div>

            <div class="flex flex-wrap gap-2" role="tablist" aria-label="Calibrations views">
                <button
                    type="button"
                    role="tab"
                    wire:click="$set('tab', '{{ CalibrationAnnualOverview::TAB_OVERVIEW }}')"
                    aria-selected="{{ $tab === CalibrationAnnualOverview::TAB_OVERVIEW ? 'true' : 'false' }}"
                    class="{{ $tab === CalibrationAnnualOverview::TAB_OVERVIEW ? 'bg-navy text-white' : 'bg-brand-soft text-navy hover:bg-brand-soft/80' }} rounded-lg px-4 py-2 text-sm font-semibold"
                >
                    Annual Overview
                </button>
                <button
                    type="button"
                    role="tab"
                    wire:click="$set('tab', '{{ CalibrationAnnualOverview::TAB_HISTORY }}')"
                    aria-selected="{{ $tab === CalibrationAnnualOverview::TAB_HISTORY ? 'true' : 'false' }}"
                    class="{{ $tab === CalibrationAnnualOverview::TAB_HISTORY ? 'bg-navy text-white' : 'bg-brand-soft text-navy hover:bg-brand-soft/80' }} rounded-lg px-4 py-2 text-sm font-semibold"
                >
                    Session History
                </button>
            </div>
        </header>

        @if ($tab === CalibrationAnnualOverview::TAB_HISTORY)
            <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="session-history-heading">
                <div class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <h2 id="session-history-heading" class="text-base font-semibold tracking-tight text-navy">Session History</h2>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <label for="session-history-year" class="sr-only">Year</label>
                        <select id="session-history-year" wire:model.live="year" class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-navy">
                            @foreach ($this->years as $yearOption)
                                <option value="{{ $yearOption }}">{{ $yearOption }}</option>
                            @endforeach
                        </select>
                        <label for="session-history-status" class="sr-only">Session status</label>
                        <select id="session-history-status" wire:model.live="sessionStatus" class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-navy">
                            <option value="{{ CalibrationSessionHistory::STATUS_ALL }}">All</option>
                            <option value="{{ Calibration::STATUS_IN_PROGRESS }}">In Progress</option>
                            <option value="{{ Calibration::STATUS_COMPLETED }}">Completed</option>
                            <option value="{{ Calibration::STATUS_CANCELLED }}">Cancelled</option>
                        </select>
                        <label for="session-history-search" class="sr-only">Search by name, type, or calibration number</label>
                        <input
                            id="session-history-search"
                            type="search"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Name, type, or calibration number"
                            class="ui-input sm:w-64"
                        >
                        <label for="session-history-page-size" class="sr-only">Rows per page</label>
                        <select id="session-history-page-size" wire:model.live="perPage" class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-navy">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                    </div>
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
                        <table class="ui-table">
                            <thead class="bg-navy text-xs font-medium text-white">
                                <tr>
                                    <th scope="col" class="px-5 py-3 font-medium">Instrument Name</th>
                                    <th scope="col" class="px-5 py-3 font-medium">Instrument Type</th>
                                    <th scope="col" class="px-5 py-3 font-medium">Serial Number</th>
                                    <th scope="col" class="px-5 py-3 font-medium">Calibration Number</th>
                                    <th scope="col" class="px-5 py-3 font-medium">Session Date</th>
                                    <th scope="col" class="px-5 py-3 font-medium">Status</th>
                                    <th scope="col" class="px-5 py-3 font-medium">Method</th>
                                    <th scope="col" class="px-5 py-3 font-medium">Engineer</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/70">
                                @foreach ($this->sessions as $session)
                                    <tr wire:key="session-row-{{ $session['key'] }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec] hover:bg-[#edf4fa]">
                                        <td class="px-5 py-3.5 font-medium text-navy">
                                            <a href="{{ $session['detail_url'] }}" class="text-navy hover:text-brand">
                                                {{ $session['name'] }}
                                            </a>
                                        </td>
                                        <td class="px-5 py-3.5 text-navy">{{ $session['type'] }}</td>
                                        <td class="px-5 py-3.5 text-navy/70">{{ $session['serial_number'] }}</td>
                                        <td class="px-5 py-3.5 text-navy">{{ $session['calibration_number'] }}</td>
                                        <td class="px-5 py-3.5 tabular-nums text-navy">{{ $session['session_date'] }}</td>
                                        <td class="px-5 py-3.5">
                                            <x-status-badge :status="$session['status']" />
                                        </td>
                                        <td class="px-5 py-3.5 text-navy">{{ $session['method'] }}</td>
                                        <td class="px-5 py-3.5 text-navy">{{ $session['engineer'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($this->sessions->hasPages())
                        <div class="px-5 py-4">
                            {{ $this->sessions->links() }}
                        </div>
                    @endif
                @endif
            </section>
        @else
            <section aria-label="Annual progress totals" class="grid gap-4 sm:grid-cols-3">
                <article class="relative overflow-hidden rounded-2xl bg-on-track p-5 text-white shadow-[0_16px_28px_-16px_rgb(31_122_77/0.75)] transition duration-200 hover:-translate-y-0.5">
                    <span class="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/25 via-transparent to-black/15" aria-hidden="true"></span>
                    <div class="relative">
                        <p class="text-sm font-medium text-white/80">Completed</p>
                        <p class="mt-3 text-4xl font-semibold tracking-tight tabular-nums">{{ $this->totals['completed'] }}</p>
                    </div>
                </article>
                <article class="relative overflow-hidden rounded-2xl bg-brand p-5 text-white shadow-[0_16px_28px_-16px_rgb(30_86_160/0.75)] transition duration-200 hover:-translate-y-0.5">
                    <span class="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/25 via-transparent to-black/15" aria-hidden="true"></span>
                    <div class="relative">
                        <p class="text-sm font-medium text-white/80">In Progress</p>
                        <p class="mt-3 text-4xl font-semibold tracking-tight tabular-nums">{{ $this->totals['inProgress'] }}</p>
                    </div>
                </article>
                <article class="relative overflow-hidden rounded-2xl bg-navy p-5 text-white shadow-[0_16px_28px_-16px_rgb(22_49_114/0.75)] transition duration-200 hover:-translate-y-0.5">
                    <span class="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/25 via-transparent to-black/15" aria-hidden="true"></span>
                    <div class="relative">
                        <p class="text-sm font-medium text-white/80">Not Calibrated</p>
                        <p class="mt-3 text-4xl font-semibold tracking-tight tabular-nums">{{ $this->totals['notCalibrated'] }}</p>
                    </div>
                </article>
            </section>

            <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="calibrations-heading">
                <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <h2 id="calibrations-heading" class="text-base font-semibold tracking-tight text-navy">Annual Overview</h2>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <label for="calibration-year" class="sr-only">Year</label>
                        <select id="calibration-year" wire:model.live="year" class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-navy">
                            @foreach ($this->years as $yearOption)
                                <option value="{{ $yearOption }}">{{ $yearOption }}</option>
                            @endforeach
                        </select>
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
                                    <th scope="col" class="px-5 py-3 font-medium">Annual Progress</th>
                                    <th scope="col" class="px-5 py-3 font-medium">Current Due Status</th>
                                    <th scope="col" class="px-5 py-3 font-medium">Action</th>
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
                                            <div class="flex flex-col gap-1">
                                                <x-status-badge :status="$row['progress']" />
                                                @if ($row['active_year'] !== null)
                                                    <p class="text-xs text-navy/70">
                                                        @if ($row['active_id'] === null)
                                                            Active session ({{ $row['active_year'] }})
                                                        @else
                                                            <a href="{{ route('calibrations.show', $row['active_id']) }}" class="text-navy/70 hover:text-brand">
                                                                Active session ({{ $row['active_year'] }})
                                                            </a>
                                                        @endif
                                                    </p>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-5 py-3.5">
                                            <x-status-badge :status="$row['due_status']" />
                                        </td>
                                        <td class="px-5 py-3.5">
                                            @if ($row['can_start'] && $row['start_url'] !== null)
                                                <a href="{{ $row['start_url'] }}" class="font-medium text-navy hover:text-brand">Start Calibration</a>
                                            @elseif ($row['active_url'] !== null)
                                                <a href="{{ $row['active_url'] }}" class="font-medium text-navy hover:text-brand">Open Session</a>
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
                        <div class="px-5 py-4">
                            {{ $this->rows->links() }}
                        </div>
                    @endif
                @endif
            </section>
        @endif
    </div>
</div>
