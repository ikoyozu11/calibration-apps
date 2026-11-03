<?php

use App\CalibrationSessions\CalibrationSessionHttpRead;
use App\CalibrationSessions\CalibrationHttpConnection;
use App\Authorization\AuthorizesMasterDataWrites;
use App\Livewire\Concerns\DisplaysTestComponents;
use App\Models\DetailLocation;
use App\Models\Calibration;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\InstrumentTypeStandardAssignment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use AuthorizesMasterDataWrites;
    use DisplaysTestComponents;
    use WithPagination;

    #[Locked]
    public int $instrumentId = 0;

    #[Locked]
    public ?int $instrumentTypeId = null;

    public bool $editingIdentity = false;

    public string $editInstrumentName = '';

    public string $editSerialNumber = '';

    public ?int $editDetailLocationId = null;

    public string $editAssetNumber = '';

    public string $editBrand = '';

    public string $editModel = '';

    public string $editMaxCapacity = '';

    public string $editMaxCapacityUnit = '';

    public string $editResolution = '';

    public string $editResolutionUnit = '';

    private ?Instrument $loadedInstrument = null;

    private ?InstrumentType $loadedInstrumentType = null;

    private bool $skipTestProfileReload = false;

    public function mount(int $instrument, ?int $instrumentType = null): void
    {
        $reads = app(CalibrationSessionHttpRead::class);
        $this->instrumentId = $instrument;

        if ($instrumentType === null) {
            $firstType = $reads->firstInstrumentType($this->instrumentId);

            if ($firstType !== null) {
                $this->redirect(route('instruments.types.show', [
                    'instrument' => $this->instrumentId,
                    'instrumentType' => $firstType->instrument_type_id,
                ]));

                return;
            }

            $this->instrumentTypeId = null;
        } else {
            $reads->instrumentType($instrumentType, $this->instrumentId);
            $this->instrumentTypeId = $instrumentType;
        }

        $this->currentInstrument();
        $this->loadTestProfiles();
        $this->loadStandardAssignments();
    }

    #[Computed]
    public function instrument(): Instrument
    {
        return $this->currentInstrument();
    }

    #[Computed]
    public function instrumentType(): ?InstrumentType
    {
        return $this->currentInstrumentType();
    }

    public function hydrate(): void
    {
        $this->loadTestProfiles();
        $this->loadStandardAssignments();
    }

    /**
     * @param  mixed  $view
     * @param  mixed  $data
     */
    public function rendering(mixed $view = null, mixed $data = null): void
    {
        if ($this->skipTestProfileReload) {
            $this->skipTestProfileReload = false;
            $this->loadStandardAssignments();

            return;
        }

        $this->loadTestProfiles();
        $this->loadStandardAssignments();
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function identity(): array
    {
        $type = $this->currentInstrumentType();
        $loaded = $this->currentInstrument();

        return [
            ['label' => 'Instrument Name', 'value' => $this->display($loaded->instrument_name)],
            ['label' => 'Location', 'value' => $this->display($loaded->detailLocation?->location?->location_name)],
            ['label' => 'Detail Location', 'value' => $this->display($loaded->detailLocation?->detail_location_name)],
            ['label' => 'Type', 'value' => $this->display($type?->type_name)],
            ['label' => 'Serial Number', 'value' => $this->display($loaded->serial_number)],
            ['label' => 'Asset Number', 'value' => $this->display($type?->asset_number)],
            ['label' => 'Brand', 'value' => $this->display($type?->brand)],
            ['label' => 'Model', 'value' => $this->display($type?->model)],
            ['label' => 'Max Capacity', 'value' => $this->measurement($type?->max_capacity, $type?->max_capacity_unit)],
            ['label' => 'Resolution', 'value' => $this->measurement($type?->resolution, $type?->resolution_unit)],
        ];
    }

    public function startEditingIdentity(): void
    {
        $this->authorizeMasterDataManage();

        $instrument = $this->currentInstrument();
        $type = $this->currentInstrumentType();

        $this->editInstrumentName = (string) $instrument->instrument_name;
        $this->editSerialNumber = (string) ($instrument->serial_number ?? '');
        $this->editDetailLocationId = $instrument->detail_location_id !== null ? (int) $instrument->detail_location_id : null;
        $this->editAssetNumber = (string) ($type?->asset_number ?? '');
        $this->editBrand = (string) ($type?->brand ?? '');
        $this->editModel = (string) ($type?->model ?? '');
        $this->editMaxCapacity = (string) ($type?->max_capacity ?? '');
        $this->editMaxCapacityUnit = (string) ($type?->max_capacity_unit ?? '');
        $this->editResolution = (string) ($type?->resolution ?? '');
        $this->editResolutionUnit = (string) ($type?->resolution_unit ?? '');
        $this->editingIdentity = true;
        $this->resetValidation();
    }

    public function cancelEditingIdentity(): void
    {
        $this->editingIdentity = false;
        $this->resetIdentityEditor();
        $this->resetValidation();
    }

    public function prepareIdentityUpdate(): void
    {
        $this->authorizeMasterDataManage();

        $this->validateIdentity();

        $this->dispatch(
            'instruments-desk-confirm-identity-update',
            componentId: $this->getId(),
            instrumentName: $this->editInstrumentName,
        );
    }

    /**
     * @return array{saved: bool, validation?: bool, message?: string}
     */
    public function saveIdentity(): array
    {
        $this->authorizeMasterDataManage();

        try {
            $this->validateIdentity();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return ['saved' => false, 'validation' => true];
        }

        try {
            $connection = app(CalibrationHttpConnection::class)->resolve();
            $connection->transaction(function () use ($connection): void {
                $instrument = $connection->table('instrument')
                    ->where('instrument_id', $this->instrumentId)
                    ->lockForUpdate()
                    ->first();
                $type = $this->instrumentTypeId === null
                    ? null
                    : $connection->table('instrument_type')
                        ->where('instrument_type_id', $this->instrumentTypeId)
                        ->where('instrument_id', $this->instrumentId)
                        ->lockForUpdate()
                        ->first();

                if ($instrument === null || ($this->instrumentTypeId !== null && $type === null)) {
                    throw new \RuntimeException('The instrument identity could not be found.');
                }

                if ($this->editDetailLocationId !== null
                    && ! $connection->table('detail_location')
                        ->where('detail_location_id', $this->editDetailLocationId)
                        ->exists()) {
                    throw ValidationException::withMessages([
                        'editDetailLocationId' => 'The selected detail location could not be found.',
                    ]);
                }

                $connection->table('instrument')
                    ->where('instrument_id', $this->instrumentId)
                    ->update([
                        'instrument_name' => trim($this->editInstrumentName),
                        'serial_number' => $this->nullableText($this->editSerialNumber),
                        'detail_location_id' => $this->editDetailLocationId,
                    ]);

                if ($type !== null) {
                    $connection->table('instrument_type')
                        ->where('instrument_type_id', $this->instrumentTypeId)
                        ->where('instrument_id', $this->instrumentId)
                        ->update([
                            'asset_number' => $this->nullableText($this->editAssetNumber),
                            'brand' => $this->nullableText($this->editBrand),
                            'model' => $this->nullableText($this->editModel),
                            'max_capacity' => $this->nullableText($this->editMaxCapacity),
                            'max_capacity_unit' => $this->nullableText($this->editMaxCapacityUnit),
                            'resolution' => $this->nullableText($this->editResolution),
                            'resolution_unit' => $this->nullableText($this->editResolutionUnit),
                        ]);
                }
            });
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return ['saved' => false, 'validation' => true];
        } catch (QueryException|\RuntimeException) {
            return [
                'saved' => false,
                'message' => 'The instrument identity could not be saved. Please try again.',
            ];
        }

        $this->loadedInstrument?->setAttribute('instrument_name', trim($this->editInstrumentName));
        $this->loadedInstrument?->setAttribute('serial_number', $this->nullableText($this->editSerialNumber));
        $this->loadedInstrument?->setAttribute('detail_location_id', $this->editDetailLocationId);
        $this->loadedInstrumentType?->setAttribute('asset_number', $this->nullableText($this->editAssetNumber));
        $this->loadedInstrumentType?->setAttribute('brand', $this->nullableText($this->editBrand));
        $this->loadedInstrumentType?->setAttribute('model', $this->nullableText($this->editModel));
        $this->loadedInstrumentType?->setAttribute('max_capacity', $this->nullableText($this->editMaxCapacity));
        $this->loadedInstrumentType?->setAttribute('max_capacity_unit', $this->nullableText($this->editMaxCapacityUnit));
        $this->loadedInstrumentType?->setAttribute('resolution', $this->nullableText($this->editResolution));
        $this->loadedInstrumentType?->setAttribute('resolution_unit', $this->nullableText($this->editResolutionUnit));

        $this->editingIdentity = false;
        $this->resetIdentityEditor();
        $this->resetValidation();
        $this->loadedInstrument = null;
        $this->currentInstrument();
        $this->skipTestProfileReload = true;

        return ['saved' => true];
    }

    /**
     * @return Collection<int, DetailLocation>
     */
    public function detailLocations(): Collection
    {
        return DetailLocation::on($this->readConnectionName())
            ->with('location')
            ->orderBy('location_id')
            ->orderBy('detail_location_name')
            ->get([
                'detail_location_id',
                'location_id',
                'detail_location_name',
            ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function measurementUnitGroups(): array
    {
        return [
            'Length / Dimension' => ['mm', 'cm', 'm', 'µm'],
            'Mass / Weight' => ['mg', 'g', 'kg'],
            'Temperature / Humidity' => ['°C', '°F', '%RH'],
            'Pressure' => ['Pa', 'kPa', 'bar', 'mbar', 'mmWG'],
            'Other' => ['%', 's', 'min', 'rpm', 'V', 'mA'],
        ];
    }

    public function isKnownMeasurementUnit(?string $unit): bool
    {
        if (! filled($unit)) {
            return true;
        }

        foreach ($this->measurementUnitGroups() as $units) {
            if (in_array($unit, $units, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return LengthAwarePaginator<int, array{id: int, date: string, due: string, status: string|null, remarks: string}>
     */
    #[Computed]
    public function history(): LengthAwarePaginator
    {
        return $this->currentInstrument()->calibrations()
            ->completed()
            ->select([
                'calibration_id',
                'instrument_id',
                'calibration_date',
                'calibration_due',
                'remarks',
            ])
            ->orderByRaw('case when calibration_date is null then 1 else 0 end')
            ->orderByDesc('calibration_date')
            ->orderByDesc('calibration_id')
            ->paginate(5)
            ->through(fn (Calibration $calibration): array => [
                'id' => $calibration->calibration_id,
                'date' => $calibration->calibration_date?->format('Y-m-d') ?? '—',
                'due' => $calibration->calibration_due?->format('Y-m-d') ?? '—',
                'status' => $this->status($calibration),
                'remarks' => $this->display($calibration->remarks),
            ]);
    }

    private function status(Calibration $calibration): ?string
    {
        if ($calibration->calibration_due === null) {
            return null;
        }

        if (today()->greaterThan($calibration->calibration_due->copy()->startOfDay())) {
            return 'Overdue';
        }

        return 'On Track';
    }

    /**
     * @return Collection<int, InstrumentTypeStandardAssignment>
     */
    public function assignedStandards(): Collection
    {
        $type = $this->currentInstrumentType();

        if ($type === null) {
            return new Collection;
        }

        return $type->relationLoaded('standardAssignments')
            ? $type->standardAssignments
            : new Collection;
    }

    /**
     * @return array{type: string, asset: string}
     */
    public function standardMeta(Instrument $instrument): array
    {
        $type = $instrument->instrumentTypes->sortBy('instrument_type_id')->first();

        return [
            'type' => filled($type?->type_name) ? (string) $type->type_name : '—',
            'asset' => filled($type?->asset_number) ? (string) $type->asset_number : '—',
        ];
    }

    public function defaultDisplay(?string $range, ?string $unit): string
    {
        $parts = array_values(array_filter([
            filled($range) ? $range : null,
            filled($unit) ? $unit : null,
        ], fn (?string $part): bool => $part !== null));

        return $parts === [] ? '—' : implode(' ', $parts);
    }

    private function loadStandardAssignments(): void
    {
        $type = $this->currentInstrumentType();

        if ($type === null || ! Schema::connection($this->readConnectionName())->hasTable('instrument_type_standard_assignment')) {
            return;
        }

        $type->load([
            'standardAssignments' => function (HasMany $query): void {
                $query
                    ->select([
                        'instrument_type_standard_assignment_id',
                        'instrument_type_id',
                        'standard_id',
                        'standard_instrument_id',
                        'assignment_order',
                        'default_range',
                        'default_unit',
                    ])
                    ->orderBy('assignment_order')
                    ->orderBy('instrument_type_standard_assignment_id');
            },
            'standardAssignments.standard' => function (BelongsTo $query): void {
                $query->select([
                    'standard_id',
                    'standard_code',
                    'instrument_name',
                    'type_specification',
                    'serial_number',
                ]);
            },
        ]);
    }

    private function display(mixed $value): string
    {
        return filled($value) ? (string) $value : '—';
    }

    private function measurement(mixed $value, mixed $unit): string
    {
        if (! filled($value)) {
            return '—';
        }

        $amount = (string) $value;

        if (is_numeric($amount) && str_contains($amount, '.')) {
            $amount = rtrim(rtrim($amount, '0'), '.');
            $amount = $amount === '' || $amount === '-' ? '0' : $amount;
        }

        return filled($unit) ? $amount.' '.$unit : $amount;
    }

    private function currentInstrument(): Instrument
    {
        if ($this->loadedInstrument instanceof Instrument
            && (int) $this->loadedInstrument->getKey() === $this->instrumentId) {
            return $this->loadedInstrument;
        }

        $instrument = app(CalibrationSessionHttpRead::class)->instrument($this->instrumentId);
        $instrument->load([
            'detailLocation:detail_location_id,location_id,detail_location_name',
            'detailLocation.location:location_id,location_name',
        ]);
        $this->loadedInstrument = $instrument;

        return $instrument;
    }

    private function currentInstrumentType(): ?InstrumentType
    {
        if ($this->instrumentTypeId === null) {
            return null;
        }

        if ($this->loadedInstrumentType instanceof InstrumentType
            && (int) $this->loadedInstrumentType->getKey() === $this->instrumentTypeId) {
            return $this->loadedInstrumentType;
        }

        $this->loadedInstrumentType = app(CalibrationSessionHttpRead::class)
            ->instrumentType($this->instrumentTypeId, $this->instrumentId);

        return $this->loadedInstrumentType;
    }

    private function readConnectionName(): string
    {
        return app(CalibrationSessionHttpRead::class)->connectionName();
    }

    private function validateIdentity(): void
    {
        $this->validate([
            'editInstrumentName' => ['required', 'string', 'max:150'],
            'editSerialNumber' => ['nullable', 'string', 'max:255'],
            'editDetailLocationId' => ['nullable', 'integer'],
            'editAssetNumber' => ['nullable', 'string', 'max:255'],
            'editBrand' => ['nullable', 'string', 'max:255'],
            'editModel' => ['nullable', 'string', 'max:255'],
            'editMaxCapacity' => ['nullable', 'numeric', 'min:0'],
            'editMaxCapacityUnit' => ['nullable', 'string', 'max:50'],
            'editResolution' => ['nullable', 'numeric', 'min:0'],
            'editResolutionUnit' => ['nullable', 'string', 'max:50'],
        ], [
            'editInstrumentName.required' => 'Instrument Name is required.',
            'editMaxCapacity.numeric' => 'Max Capacity must be a number.',
            'editResolution.numeric' => 'Resolution must be a number.',
        ]);
    }

    private function nullableText(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function resetIdentityEditor(): void
    {
        $this->reset(
            'editInstrumentName',
            'editSerialNumber',
            'editDetailLocationId',
            'editAssetNumber',
            'editBrand',
            'editModel',
            'editMaxCapacity',
            'editMaxCapacityUnit',
            'editResolution',
            'editResolutionUnit',
        );
    }
};
?>

<div
    class="px-4 py-8 sm:px-6 lg:px-10"
    data-component-id="{{ $this->getId() }}"
    x-data="{
        editingIdentity: $wire.entangle('editingIdentity'),
        requestCloseIdentityModal() {
            window.instrumentsDeskNotifications.identityDiscardConfirmation({ componentId: $root.dataset.componentId });
        },
    }"
    x-on:keydown.escape.window="if (editingIdentity) requestCloseIdentityModal()"
    x-init="$watch('editingIdentity', (open) => $nextTick(() => open ? $refs.editIdentityName?.focus() : $refs.editIdentityButton?.focus()))"
>
    <div class="mx-auto flex max-w-6xl flex-col gap-8">
        <header>
            <a href="{{ route('instruments.index') }}" class="ui-button-secondary w-fit gap-2 shadow-[0_10px_18px_-14px_rgb(22_49_114/0.9)]">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 6 9 12l6 6" />
                </svg>
                Back to instruments
            </a>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight text-navy">{{ $this->currentInstrument()->instrument_name }}</h1>
            <p class="mt-1 text-sm text-muted">{{ $this->currentInstrumentType()?->type_name ?? 'Instrument identity' }}</p>
        </header>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="instrument-identity-heading">
            <div class="flex flex-col gap-3 bg-navy px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 id="instrument-identity-heading" class="text-base font-semibold tracking-tight text-white">Instrument Identity</h2>
                @can('masterdata.manage')
                    <button
                        x-ref="editIdentityButton"
                        type="button"
                        wire:click="startEditingIdentity"
                        class="ui-button-primary"
                    >
                        Edit Identity
                    </button>
                @endcan
            </div>

            <dl class="grid gap-3 px-5 py-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->identity() as $field)
                    <div wire:key="identity-{{ $field['label'] }}" class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
                        <dt class="text-xs font-medium text-muted">{{ $field['label'] }}</dt>
                        <dd class="mt-1 text-sm font-semibold text-navy">{{ $field['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="test-components-heading">
            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 id="test-components-heading" class="text-base font-semibold tracking-tight text-navy">Test Components</h2>
                @if ($this->currentInstrumentType() !== null)
                    <a href="{{ route('instruments.types.test-components', ['instrument' => $this->instrumentId, 'instrumentType' => $this->instrumentTypeId]) }}" class="ui-button-outline">
                        Manage Test Components
                    </a>
                @endif
            </div>

            @if ($this->currentInstrumentType() === null || $this->currentInstrumentType()->testProfiles->isEmpty())
                <p class="px-5 py-8 text-sm text-navy/70">No test components found.</p>
            @else
                <div class="flex flex-col gap-6 px-5 pb-5">
                    @foreach ($this->currentInstrumentType()->testProfiles as $profile)
                        @php($heading = $this->profileHeading($profile))
                        <div wire:key="test-profile-{{ $profile->test_profile_id }}" class="flex flex-col gap-3">
                            @if ($heading !== null)
                                <h3 @class([
                                    'text-sm font-semibold text-navy' => ! $heading['missing'],
                                    'text-sm font-medium text-muted' => $heading['missing'],
                                ])>{{ $heading['text'] }}</h3>
                            @endif
                            @if ($this->testTableIsUnpublished($profile))
                                <p class="text-sm text-muted">This test table has not been published yet.</p>
                            @endif

                            @if ($profile->testColumns->isNotEmpty())
                                <div class="overflow-x-auto">
                                    <table class="ui-table">
                                        <thead class="bg-navy text-xs font-medium text-white">
                                            <tr>
                                                @foreach ($profile->testColumns as $column)
                                                    <th scope="col" wire:key="test-column-{{ $column->test_column_id }}" class="px-5 py-3 font-medium">{{ $column->column_label }}</th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-white/70">
                                            @forelse ($profile->testPoints as $point)
                                                <tr wire:key="test-point-{{ $point->test_point_id }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec]">
                                                    @foreach ($profile->testColumns as $column)
                                                        <td wire:key="test-cell-{{ $point->test_point_id }}-{{ $column->test_column_id }}" @class([
                                                            'px-5 py-3.5 text-navy',
                                                            'tabular-nums' => $column->data_type === 'numeric',
                                                        ])>{{ $this->templateCell($point, $column) }}</td>
                                                    @endforeach
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="{{ $profile->testColumns->count() }}" class="px-5 py-4 text-sm text-navy/70">No test points yet.</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            @elseif ($profile->testPoints->isEmpty())
                                <p class="text-sm font-semibold text-navy">{{ $profile->profile_name }}</p>
                                <p class="py-4 text-sm text-navy/70">No test points yet.</p>
                            @else
                                <div class="overflow-x-auto">
                                    <table class="ui-table">
                                        <thead class="bg-navy text-xs font-medium text-white">
                                            <tr>
                                                <th scope="col" class="px-5 py-3 font-medium">Point</th>
                                                <th scope="col" class="px-5 py-3 font-medium">Nominal</th>
                                                <th scope="col" class="px-5 py-3 font-medium">Unit</th>
                                                <th scope="col" class="px-5 py-3 font-medium">+Tol</th>
                                                <th scope="col" class="px-5 py-3 font-medium">-Tol</th>
                                                <th scope="col" class="px-5 py-3 font-medium">Description</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-white/70">
                                            @foreach ($profile->testPoints as $point)
                                                <tr wire:key="test-point-{{ $point->test_point_id }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec]">
                                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ $point->point_order }}</td>
                                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ filled($point->nominal_value) ? $point->nominal_value : '—' }}</td>
                                                    <td class="px-5 py-3.5 text-navy">{{ filled($point->unit) ? $point->unit : '—' }}</td>
                                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ filled($point->tolerance_plus) ? $point->tolerance_plus : '—' }}</td>
                                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ filled($point->tolerance_minus) ? $point->tolerance_minus : '—' }}</td>
                                                    <td class="px-5 py-3.5 text-navy">{{ filled($point->description) ? $point->description : '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="standards-used-heading">
            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 id="standards-used-heading" class="text-base font-semibold tracking-tight text-navy">Standard(s) Used</h2>
                @if ($this->currentInstrumentType() !== null)
                    <a href="{{ route('instruments.types.standards', ['instrument' => $this->instrumentId, 'instrumentType' => $this->instrumentTypeId]) }}" class="ui-button-outline">
                        Manage Standards
                    </a>
                @endif
            </div>

            @if ($this->assignedStandards()->isEmpty())
                <p class="px-5 py-8 text-sm text-navy/70">No standards assigned.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead class="bg-navy text-xs font-medium text-white">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-medium">No.</th>
                                <th scope="col" class="px-5 py-3 font-medium">Instrument Name</th>
                                <th scope="col" class="px-5 py-3 font-medium">Type / Specification</th>
                                <th scope="col" class="px-5 py-3 font-medium">Serial Number</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/70">
                            @foreach ($this->assignedStandards() as $assignment)
                                <tr wire:key="detail-standard-{{ $assignment->instrument_type_standard_assignment_id }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec]">
                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ $assignment->assignment_order }}</td>
                                    <td class="px-5 py-3.5 font-medium text-navy">{{ filled($assignment->instrumentName()) ? $assignment->instrumentName() : '—' }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ filled($assignment->typeSpecification()) ? $assignment->typeSpecification() : '—' }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ filled($assignment->serialNumber()) ? $assignment->serialNumber() : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="calibration-history-heading">
            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 id="calibration-history-heading" class="text-base font-semibold tracking-tight text-navy">Calibration History</h2>
                @if ($this->currentInstrumentType() !== null)
                    <a
                        href="{{ route('calibrations.index', [
                            'tab' => 'history',
                            'instrument_id' => $this->instrumentId,
                            'instrument_type_id' => $this->instrumentTypeId,
                        ]) }}"
                        class="ui-button-primary shrink-0"
                    >
                        View All
                    </a>
                @endif
            </div>

            @if ($this->history->isEmpty())
                <p class="px-5 py-8 text-sm text-navy/70">No calibration history found.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead class="bg-navy text-xs font-medium text-white">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-medium">Calibration Date</th>
                                <th scope="col" class="px-5 py-3 font-medium">Calibration Due</th>
                                <th scope="col" class="px-5 py-3 font-medium">Status</th>
                                <th scope="col" class="px-5 py-3 font-medium">Remarks</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/70">
                            @foreach ($this->history as $calibration)
                                <tr wire:key="calibration-{{ $calibration['id'] }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec] hover:bg-[#edf4fa]">
                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ $calibration['date'] }}</td>
                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ $calibration['due'] }}</td>
                                    <td class="px-5 py-3.5">
                                        @if ($calibration['status'] === null)
                                            <span class="text-navy/70">—</span>
                                        @else
                                            <x-status-badge :status="$calibration['status']" />
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 text-navy">{{ $calibration['remarks'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($this->history->hasPages())
                    <div class="px-5 py-4">
                        {{ $this->history->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>

    <div
        x-cloak
        x-show="editingIdentity"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-180"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-navy/40 px-4 py-8 sm:items-center"
        role="presentation"
        x-on:click.self="requestCloseIdentityModal()"
    >
        <div
            x-show="editingIdentity"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="scale-95 opacity-0"
            x-transition:enter-end="scale-100 opacity-100"
            x-transition:leave="transition ease-in duration-180"
            x-transition:leave-start="scale-100 opacity-100"
            x-transition:leave-end="scale-95 opacity-0"
            class="w-full max-w-4xl overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-2xl motion-reduce:transition-none"
            role="dialog"
            aria-modal="true"
            aria-labelledby="edit-identity-modal-heading"
            data-identity-modal
            x-on:click.stop
        >
            <div class="flex items-start justify-between bg-navy px-5 py-4">
                <div>
                    <h2 id="edit-identity-modal-heading" class="text-base font-semibold tracking-tight text-white">Edit Instrument Identity</h2>
                    <p class="mt-1 text-sm text-white/80">Update instrument and type details. Type cannot be changed here.</p>
                </div>
                <button type="button" class="text-xl leading-none text-white/80 hover:text-white" aria-label="Close" x-on:click="requestCloseIdentityModal()">&times;</button>
            </div>

            <form wire:submit="prepareIdentityUpdate" class="max-h-[calc(100vh-9rem)] overflow-y-auto px-5 py-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="edit-identity-name" class="ui-label">Instrument Name <span class="text-red-700">*</span></label>
                        <input x-ref="editIdentityName" id="edit-identity-name" data-identity-field data-initial-value="{{ $this->editInstrumentName }}" wire:model="editInstrumentName" type="text" class="ui-input mt-1" autocomplete="off">
                        @error('editInstrumentName') <p class="ui-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="edit-identity-serial" class="ui-label">Serial Number</label>
                        <input id="edit-identity-serial" data-identity-field data-initial-value="{{ $this->editSerialNumber }}" wire:model="editSerialNumber" type="text" class="ui-input mt-1" autocomplete="off">
                        @error('editSerialNumber') <p class="ui-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="edit-identity-location" class="ui-label">Location / Detail Location</label>
                        <select id="edit-identity-location" data-identity-field data-initial-value="{{ $this->editDetailLocationId ?? '' }}" wire:model="editDetailLocationId" class="ui-input mt-1">
                            <option value="">No location</option>
                            @foreach ($this->detailLocations() as $detailLocation)
                                <option value="{{ $detailLocation->detail_location_id }}">
                                    {{ $detailLocation->location?->location_name }} / {{ $detailLocation->detail_location_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('editDetailLocationId') <p class="ui-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="ui-label">Type</label>
                        <input type="text" class="ui-input mt-1 bg-white/60" value="{{ $this->currentInstrumentType()?->type_name ?? '—' }}" disabled>
                    </div>

                    <div>
                        <label for="edit-identity-asset" class="ui-label">Asset Number</label>
                        <input id="edit-identity-asset" data-identity-field data-initial-value="{{ $this->editAssetNumber }}" wire:model="editAssetNumber" type="text" class="ui-input mt-1" autocomplete="off">
                        @error('editAssetNumber') <p class="ui-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="edit-identity-brand" class="ui-label">Brand</label>
                        <input id="edit-identity-brand" data-identity-field data-initial-value="{{ $this->editBrand }}" wire:model="editBrand" type="text" class="ui-input mt-1" autocomplete="off">
                        @error('editBrand') <p class="ui-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="edit-identity-model" class="ui-label">Model</label>
                        <input id="edit-identity-model" data-identity-field data-initial-value="{{ $this->editModel }}" wire:model="editModel" type="text" class="ui-input mt-1" autocomplete="off">
                        @error('editModel') <p class="ui-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="edit-identity-capacity" class="ui-label">Max Capacity</label>
                        <input id="edit-identity-capacity" data-identity-field data-initial-value="{{ $this->editMaxCapacity }}" wire:model="editMaxCapacity" type="number" min="0" step="any" class="ui-input mt-1">
                        @error('editMaxCapacity') <p class="ui-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="edit-identity-capacity-unit" class="ui-label">Max Capacity Unit</label>
                        <select id="edit-identity-capacity-unit" data-identity-field data-initial-value="{{ $this->editMaxCapacityUnit }}" wire:model="editMaxCapacityUnit" class="ui-input mt-1">
                            <option value="">No Unit</option>
                            @foreach ($this->measurementUnitGroups() as $group => $units)
                                <optgroup label="{{ $group }}">
                                    @foreach ($units as $unit)
                                        <option value="{{ $unit }}">{{ $unit }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                            @if (! $this->isKnownMeasurementUnit($this->editMaxCapacityUnit))
                                <option value="{{ $this->editMaxCapacityUnit }}">Saved unit: {{ $this->editMaxCapacityUnit }}</option>
                            @endif
                        </select>
                        @error('editMaxCapacityUnit') <p class="ui-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="edit-identity-resolution" class="ui-label">Resolution</label>
                        <input id="edit-identity-resolution" data-identity-field data-initial-value="{{ $this->editResolution }}" wire:model="editResolution" type="number" min="0" step="any" class="ui-input mt-1">
                        @error('editResolution') <p class="ui-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="edit-identity-resolution-unit" class="ui-label">Resolution Unit</label>
                        <select id="edit-identity-resolution-unit" data-identity-field data-initial-value="{{ $this->editResolutionUnit }}" wire:model="editResolutionUnit" class="ui-input mt-1">
                            <option value="">No Unit</option>
                            @foreach ($this->measurementUnitGroups() as $group => $units)
                                <optgroup label="{{ $group }}">
                                    @foreach ($units as $unit)
                                        <option value="{{ $unit }}">{{ $unit }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                            @if (! $this->isKnownMeasurementUnit($this->editResolutionUnit))
                                <option value="{{ $this->editResolutionUnit }}">Saved unit: {{ $this->editResolutionUnit }}</option>
                            @endif
                        </select>
                        @error('editResolutionUnit') <p class="ui-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-brand/20 pt-4">
                    <button type="submit" class="ui-button-primary" wire:loading.attr="disabled" wire:target="prepareIdentityUpdate">Save Identity</button>
                    <button type="button" class="ui-button-secondary" x-on:click="requestCloseIdentityModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
