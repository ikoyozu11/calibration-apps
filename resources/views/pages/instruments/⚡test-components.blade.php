<?php

use App\CalibrationSessions\CalibrationSessionHttpRead;
use App\Livewire\Concerns\ManagesTestComponents;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\TestProfile;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use ManagesTestComponents;

    #[Locked]
    public int $instrumentId = 0;

    #[Locked]
    public int $instrumentTypeId = 0;

    private ?Instrument $loadedInstrument = null;

    private ?InstrumentType $loadedInstrumentType = null;

    public function mount(int $instrument, int $instrumentType): void
    {
        $this->instrumentId = $instrument;
        $this->instrumentTypeId = $instrumentType;
        $this->currentInstrument();
        $this->currentInstrumentType();
        $this->reloadManagedProfiles();
    }

    #[Computed]
    public function instrument(): Instrument
    {
        return $this->currentInstrument();
    }

    #[Computed]
    public function instrumentType(): InstrumentType
    {
        return $this->currentInstrumentType();
    }

    public function hydrate(): void
    {
        $this->reloadManagedProfiles();
    }

    /**
     * @param  mixed  $view
     * @param  mixed  $data
     */
    public function rendering(mixed $view = null, mixed $data = null): void
    {
        $this->reloadManagedProfiles();
    }

    public function selectedProfile(): ?TestProfile
    {
        if ($this->selectedProfileId === null) {
            return null;
        }

        return $this->currentInstrumentType()->testProfiles->first(
            fn (TestProfile $profile): bool => (int) $profile->test_profile_id === (int) $this->selectedProfileId,
        );
    }

    private function currentInstrument(): Instrument
    {
        if ($this->loadedInstrument instanceof Instrument
            && (int) $this->loadedInstrument->getKey() === $this->instrumentId) {
            return $this->loadedInstrument;
        }

        $this->loadedInstrument = app(CalibrationSessionHttpRead::class)->instrument($this->instrumentId);

        return $this->loadedInstrument;
    }

    private function currentInstrumentType(): InstrumentType
    {
        if ($this->loadedInstrumentType instanceof InstrumentType
            && (int) $this->loadedInstrumentType->getKey() === $this->instrumentTypeId) {
            return $this->loadedInstrumentType;
        }

        $this->loadedInstrumentType = app(CalibrationSessionHttpRead::class)
            ->instrumentType($this->instrumentTypeId, $this->instrumentId);

        return $this->loadedInstrumentType;
    }
};
?>

<div
    class="px-4 py-8 sm:px-6 lg:px-10"
    x-data="{
        addingTestTable: $wire.entangle('addingTestTable'),
        archivedTablesOpen: false,
        editingProfileId: $wire.entangle('editingProfileId'),
        managingColumnsProfileId: $wire.entangle('managingColumnsProfileId'),
        editingRowProfileId: $wire.entangle('editingRowProfileId'),
        editingPointId: $wire.entangle('editingPointId'),
    }"
>
    @php($profile = $this->selectedProfile())
    <div class="mx-auto flex max-w-6xl flex-col gap-8">
        <header>
            <a href="{{ route('instruments.types.show', ['instrument' => $this->instrumentId, 'instrumentType' => $this->instrumentTypeId]) }}" class="ui-button-secondary w-fit gap-2 shadow-[0_10px_18px_-14px_rgb(22_49_114/0.9)]">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 6 9 12l6 6" />
                </svg>
                Back to instrument detail
            </a>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight text-navy">Manage Test Components</h1>
            <p class="mt-1 text-sm text-muted">{{ $this->currentInstrument()->instrument_name }} · {{ $this->currentInstrumentType()->type_name }}</p>
        </header>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="test-tables-heading">
            <div class="grid lg:min-h-72 lg:grid-cols-4">
            <aside class="border-b border-white/70 lg:col-span-1 lg:border-b-0 lg:border-r" aria-label="Test tables">
                <div class="flex flex-col gap-3 px-5 py-4">
                    <h2 id="test-tables-heading" class="text-base font-semibold tracking-tight text-navy">Test Tables</h2>
                    <button type="button" x-on:click="addingTestTable = true; editingProfileId = null" wire:click="startAddingTestTable" class="ui-button-outline w-full">Add Test Table</button>
            </div>

                @if ($this->activeTestProfiles()->isEmpty() && $this->archivedTestProfiles()->isEmpty())
                    <p class="px-5 py-8 text-sm text-navy/70">No test tables yet.</p>
            @else
                    @if ($this->activeTestProfiles()->isNotEmpty())
                    <ul class="flex flex-col gap-1 px-3 pb-5">
                        @foreach ($this->activeTestProfiles() as $sidebarProfile)
                            <li wire:key="sidebar-profile-{{ $sidebarProfile->test_profile_id }}">
                                <button
                                    type="button"
                                    wire:click="selectProfile({{ $sidebarProfile->test_profile_id }})"
                                    @class([
                                        'flex w-full flex-col rounded-xl px-3 py-3 text-left transition',
                                        'bg-white text-navy shadow-sm' => (int) $selectedProfileId === (int) $sidebarProfile->test_profile_id,
                                        'text-navy/80 hover:bg-white/70' => (int) $selectedProfileId !== (int) $sidebarProfile->test_profile_id,
                                    ])
                                >
                                    <span class="text-sm font-semibold">{{ $this->testTableProfileName($sidebarProfile) }}</span>
                                    <span class="mt-1 text-xs text-muted">
                                        {{ $sidebarProfile->testColumns->count() }} columns · {{ $sidebarProfile->testPoints->count() }} rows
                                    </span>
                                    @if ($this->testTableVersionCaption($sidebarProfile) !== null)
                                        <span class="mt-1 text-xs text-muted">{{ $this->testTableVersionCaption($sidebarProfile) }}</span>
                                @endif
                                    @if (filled($this->testTableDisplayTitle($sidebarProfile)))
                                        <span class="mt-1 text-xs text-muted">{{ $this->testTableDisplayTitle($sidebarProfile) }}</span>
                                @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                    @endif
                    @if ($this->archivedTestProfiles()->isNotEmpty())
                        <div class="border-t border-white/70 px-5 pt-4 pb-5">
                            <button
                                type="button"
                                x-on:click="archivedTablesOpen = !archivedTablesOpen"
                                class="flex w-full items-center justify-between gap-3 rounded-lg px-2 py-2 text-left text-sm font-semibold tracking-tight text-navy transition hover:bg-white/50 focus-visible:outline-brand"
                                :aria-expanded="archivedTablesOpen.toString()"
                                aria-controls="archived-test-tables-list"
                            >
                                <span class="flex min-w-0 items-center gap-2">
                                    <svg class="size-4 shrink-0 text-navy/70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 7.5h5l1.5 1.75h10v9.5a1.5 1.5 0 0 1-1.5 1.5h-15a1.5 1.5 0 0 1-1.5-1.5v-10a1.25 1.25 0 0 1 1.5-1.25Z" />
                                    </svg>
                                    <span class="truncate">Archived Test Tables</span>
                                    <span class="rounded-full bg-navy/10 px-2 py-0.5 text-xs font-medium text-navy/75">{{ $this->archivedTestProfiles()->count() }}</span>
                                </span>
                                <svg class="size-4 shrink-0 text-navy/70 transition-transform duration-200 motion-reduce:transition-none" :class="archivedTablesOpen ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                                </svg>
                            </button>
                            <ul
                                id="archived-test-tables-list"
                                x-show="archivedTablesOpen"
                                x-cloak
                                x-transition:enter="transition ease-out duration-200 motion-reduce:duration-0"
                                x-transition:enter-start="-translate-y-1 opacity-0"
                                x-transition:enter-end="translate-y-0 opacity-100"
                                x-transition:leave="transition ease-in duration-150 motion-reduce:duration-0"
                                x-transition:leave-start="translate-y-0 opacity-100"
                                x-transition:leave-end="-translate-y-1 opacity-0"
                                class="mt-2 flex flex-col gap-1 overflow-hidden motion-reduce:transition-none"
                            >
                                @foreach ($this->archivedTestProfiles() as $sidebarProfile)
                                    <li wire:key="sidebar-archived-profile-{{ $sidebarProfile->test_profile_id }}">
                                        <button
                                            type="button"
                                            wire:click="selectProfile({{ $sidebarProfile->test_profile_id }})"
                                            @class([
                                                'flex w-full flex-col rounded-xl px-3 py-3 text-left transition',
                                                'bg-white text-navy shadow-sm' => (int) $selectedProfileId === (int) $sidebarProfile->test_profile_id,
                                                'text-navy/80 hover:bg-white/70' => (int) $selectedProfileId !== (int) $sidebarProfile->test_profile_id,
                                            ])
                                        >
                                            <span class="text-sm font-semibold">{{ $this->testTableProfileName($sidebarProfile) }}</span>
                                            <span class="mt-1 text-xs text-muted">Archived</span>
                                            @if ($this->testTableVersionCaption($sidebarProfile) !== null)
                                                <span class="mt-1 text-xs text-muted">{{ $this->testTableVersionCaption($sidebarProfile) }}</span>
                                            @endif
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endif
            </aside>

            <section class="min-w-0 lg:col-span-3" aria-label="Test table editor">
                @if ($profile === null)
                    <div class="px-5 py-8 text-sm text-navy/70">
                        Select a test table from the list, or add a new one.
                    </div>
                @else
                    <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-base font-semibold tracking-tight text-navy">{{ $this->testTableProfileName($profile) }}</h3>
                            @if (filled($this->testTableDisplayTitle($profile)))
                                <p class="mt-1 text-sm text-muted">{{ $this->testTableDisplayTitle($profile) }}</p>
                            @endif
                            @if ($this->testTableVersionCaption($profile) !== null)
                                <p class="mt-1 text-sm text-muted">{{ $this->testTableVersionCaption($profile) }}</p>
                            @endif
                            @if ($profile->isArchived())
                                <p class="mt-1 text-sm text-muted">This test table is archived and read-only.</p>
                            @elseif (! $this->canEditTestTable($profile))
                                <p class="mt-1 text-sm text-muted">This published test table is read-only.</p>
                            @endif
                            @error('testTable')
                                <p class="ui-error mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        @if ($this->canPublishTestTable($profile) || $this->canForkTestTable($profile) || $this->canEditTestTable($profile) || $this->canPermanentlyDeleteTestTable($profile) || $this->canArchiveTestTable($profile) || $this->canRestoreTestTable($profile))
                        <div class="flex flex-wrap gap-2">
                                    @if ($this->canPublishTestTable($profile))
                                        <button
                                            type="button"
                                            data-component-id="{{ $this->getId() }}"
                                            data-profile-id="{{ $profile->test_profile_id }}"
                                            data-profile-name="{{ $this->testTableProfileName($profile) }}"
                                            data-version="{{ $this->testTableVersionCaption($profile) ?? 'Draft' }}"
                                            x-on:click="window.instrumentsDeskNotifications.testTablePublishConfirmation({ componentId: $el.dataset.componentId, profileId: Number($el.dataset.profileId), profileName: $el.dataset.profileName, version: $el.dataset.version })"
                                            class="ui-button-outline"
                                        >
                                            Publish Version
                                        </button>
                                    @endif
                                    @if ($this->canForkTestTable($profile))
                                        <button
                                            type="button"
                                            data-component-id="{{ $this->getId() }}"
                                            data-profile-id="{{ $profile->test_profile_id }}"
                                            data-profile-name="{{ $this->testTableProfileName($profile) }}"
                                            data-version="{{ $this->testTableVersionCaption($profile) ?? 'Active' }}"
                                            x-on:click="window.instrumentsDeskNotifications.testTableForkConfirmation({ componentId: $el.dataset.componentId, profileId: Number($el.dataset.profileId), profileName: $el.dataset.profileName, version: $el.dataset.version })"
                                            class="ui-button-outline"
                                        >
                                            Fork Draft
                                        </button>
                                    @endif
                                    @if ($this->canRenameTestTable($profile))
                                    <button type="button" x-on:click="editingProfileId = {{ $profile->test_profile_id }}; addingTestTable = false" wire:click="startEditingTestTable({{ $profile->test_profile_id }})" class="ui-button-outline">
                                            Edit Table
                                        </button>
                                    @endif
                                    @if ($this->canEditTestTable($profile))
                                    <button type="button" x-on:click="managingColumnsProfileId = {{ $profile->test_profile_id }}" wire:click="startManagingColumns({{ $profile->test_profile_id }})" class="ui-button-outline">
                                        Manage Columns
                                    </button>
                                    @if ($profile->testColumns->isNotEmpty())
                                        <button type="button" x-on:click="editingRowProfileId = {{ $profile->test_profile_id }}; editingPointId = null" wire:click="startAddingRow({{ $profile->test_profile_id }})" class="ui-button-outline">
                                            Add Row
                                        </button>
                                    @endif
                                    @endif
                                    @if ($this->canPermanentlyDeleteTestTable($profile))
                                        <button
                                            type="button"
                                            data-component-id="{{ $this->getId() }}"
                                            data-profile-id="{{ $profile->test_profile_id }}"
                                            data-profile-name="{{ $this->testTableProfileName($profile) }}"
                                            x-on:click="window.instrumentsDeskNotifications.testTableDeleteConfirmation({ componentId: $el.dataset.componentId, profileId: Number($el.dataset.profileId), profileName: $el.dataset.profileName })"
                                            class="ui-button-danger"
                                        >
                                            Delete Table
                                        </button>
                                    @elseif ($this->canArchiveTestTable($profile))
                                        <button
                                            type="button"
                                            data-component-id="{{ $this->getId() }}"
                                            data-profile-id="{{ $profile->test_profile_id }}"
                                            data-profile-name="{{ $this->testTableProfileName($profile) }}"
                                            x-on:click="window.instrumentsDeskNotifications.testTableArchiveConfirmation({ componentId: $el.dataset.componentId, profileId: Number($el.dataset.profileId), profileName: $el.dataset.profileName })"
                                            class="ui-button-outline"
                                        >
                                            Archive Table
                                        </button>
                                    @endif
                                    @if ($this->canRestoreTestTable($profile))
                                        <button
                                            type="button"
                                            data-component-id="{{ $this->getId() }}"
                                            data-profile-id="{{ $profile->test_profile_id }}"
                                            data-profile-name="{{ $this->testTableProfileName($profile) }}"
                                            x-on:click="window.instrumentsDeskNotifications.testTableRestoreConfirmation({ componentId: $el.dataset.componentId, profileId: Number($el.dataset.profileId), profileName: $el.dataset.profileName })"
                                            class="ui-button-outline"
                                        >
                                            Restore Table
                                        </button>
                                    @endif
                                </div>
                        @endif
                            </div>

                    <div class="flex flex-col gap-5 px-5 pb-5">
                            @if ($this->canEditTestTable($profile))
                                <div
                                    x-show="managingColumnsProfileId == {{ $profile->test_profile_id }}"
                                    x-cloak
                                    x-transition:enter="transition-opacity ease-out duration-[200ms] motion-reduce:duration-0"
                                    x-transition:enter-start="opacity-0"
                                    x-transition:enter-end="opacity-100"
                                    x-transition:leave="transition-opacity ease-in duration-[180ms] motion-reduce:duration-0"
                                    x-transition:leave-start="opacity-100"
                                    x-transition:leave-end="opacity-0"
                                    class="fixed inset-0 z-50 overflow-y-auto bg-navy/35 p-4 sm:p-8"
                                    role="dialog"
                                    aria-modal="true"
                                    aria-labelledby="manage-columns-title"
                                >
                                    <div
                                        x-transition:enter="transition ease-out duration-[200ms] motion-reduce:duration-0"
                                        x-transition:enter-start="scale-[0.96] opacity-0"
                                        x-transition:enter-end="scale-100 opacity-100"
                                        x-transition:leave="transition ease-in duration-[180ms] motion-reduce:duration-0"
                                        x-transition:leave-start="scale-100 opacity-100"
                                        x-transition:leave-end="scale-[0.96] opacity-0"
                                        class="mx-auto max-w-5xl overflow-hidden rounded-2xl border border-brand/20 bg-brand-soft shadow-2xl"
                                    >
                                        <div class="flex items-center justify-between bg-navy px-5 py-4 text-white">
                                            <div>
                                                <h2 id="manage-columns-title" class="text-base font-semibold">Manage Columns</h2>
                                                <p class="mt-1 text-sm text-brand-soft">{{ $this->testTableProfileName($profile) }}</p>
                                            </div>
                                            <button type="button" x-on:click="managingColumnsProfileId = null" wire:click="cancelManagingColumns" class="rounded-lg px-3 py-2 text-xl leading-none text-white transition hover:bg-white/15" aria-label="Close Manage Columns">&times;</button>
                                        </div>
                                        <div class="flex max-h-[calc(100vh-9rem)] flex-col gap-4 overflow-y-auto p-4 sm:p-6">
                                    @error('columns')
                                        <p class="ui-error">{{ $message }}</p>
                                    @enderror

                                    @if ($editingColumnId === null)
                                    @if ($profile->testColumns->isEmpty())
                                        <p class="text-sm text-navy/70">No columns yet. This table keeps its current layout until the first column is saved.</p>
                                    @else
                                        <div class="flex flex-col gap-1">
                                            <h3 class="text-sm font-semibold text-navy">Existing Columns</h3>
                                            <p class="text-sm text-muted">Drag columns to reorder them, or edit their settings.</p>
                                        </div>
                                        <ul class="flex flex-col gap-2" data-column-sort-list data-component-id="{{ $this->getId() }}" data-profile-id="{{ $profile->test_profile_id }}" aria-label="Test table columns">
                                            @foreach ($profile->testColumns as $column)
                                                <li wire:key="manage-column-{{ $column->test_column_id }}" draggable="true" data-column-sort-item data-column-id="{{ $column->test_column_id }}" aria-grabbed="false" title="Drag to reorder" class="flex cursor-grab flex-col gap-2 rounded-lg bg-white px-3 py-3 transition-shadow active:cursor-grabbing sm:flex-row sm:items-center sm:justify-between">
                                                    <div>
                                                        <p class="flex items-center gap-2 text-sm font-semibold text-navy"><span class="select-none text-muted" aria-hidden="true">⋮⋮</span>{{ $column->column_label }}</p>
                                                        <p class="text-xs text-muted">{{ $column->column_key }} · {{ $this->columnSummary($column) }}</p>
                                                    </div>
                                                    <div class="flex flex-wrap gap-2">
                                                        <button type="button" wire:click="editTestColumn({{ $profile->test_profile_id }}, {{ $column->test_column_id }})" class="ui-button-outline">Edit</button>
                                                        <button
                                                            type="button"
                                                            data-component-id="{{ $this->getId() }}"
                                                            data-profile-id="{{ $profile->test_profile_id }}"
                                                            data-column-id="{{ $column->test_column_id }}"
                                                            data-column-label="{{ $column->column_label }}"
                                                            x-on:click="window.instrumentsDeskNotifications.testColumnRemovalConfirmation({ componentId: $el.dataset.componentId, profileId: Number($el.dataset.profileId), columnId: Number($el.dataset.columnId), columnLabel: $el.dataset.columnLabel })"
                                                            class="ui-button-danger"
                                                        >Delete</button>
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ul>
                                        <div class="border-t border-brand/25"></div>
                                    @endif
                                    @endif

                                    <form wire:submit="saveTestColumn" class="flex flex-col gap-4">
                                    <div class="flex flex-col gap-1">
                                        <h3 class="text-sm font-semibold text-navy">{{ $editingColumnId === null ? 'Add New Column' : 'Edit Column' }}</h3>
                                        @if ($editingColumnId === null)
                                            <p class="text-sm text-muted">Create a new column for this test table.</p>
                                        @endif
                                    </div>
                                        @if ($editingColumnId !== null)
                                            <p class="text-sm text-muted">The column key stays {{ $profile->testColumns->first(fn ($column) => (int) $column->test_column_id === (int) $editingColumnId)?->column_key }}.</p>
                                        @else
                                            <p class="text-sm text-muted">The column key is created from the label and cannot be changed later.</p>
                                        @endif
                                        <div>
                                            <label for="column-label" class="ui-label">Column label</label>
                                            <input id="column-label" type="text" wire:model="columnLabel" maxlength="255" required class="ui-input">
                                            @error('columnLabel')
                                                <p class="ui-error">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <div>
                                                <label for="column-data-type" class="ui-label">Data type</label>
                                                <select id="column-data-type" wire:model.live="dataType" class="ui-input">
                                                    <option value="numeric">Numeric</option>
                                                    <option value="text">Text</option>
                                                    <option value="duration">Duration</option>
                                                </select>
                                                @error('dataType')
                                                    <p class="ui-error">{{ $message }}</p>
                                                @enderror
                                            </div>
                                            <div>
                                                <label for="column-value-source" class="ui-label">Value source</label>
                                                <select id="column-value-source" wire:model.live="valueSource" class="ui-input">
                                                    <option value="template">Template</option>
                                                    <option value="engineer">Engineer</option>
                                                    <option value="calculated" @disabled(! in_array($dataType, ['numeric', 'duration'], true))>Calculated</option>
                                                </select>
                                                @error('valueSource')
                                                    <p class="ui-error">{{ $message }}</p>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <div>
                                                <label for="column-unit" class="ui-label">Unit <span class="font-normal text-muted">(optional)</span></label>
                                                <select id="column-unit" wire:model="unit" class="ui-input">
                                                    <option value="">No Unit</option>
                                                    @if ($editingColumnId !== null && filled($unit) && ! collect($this->measurementUnitGroups())->flatten()->contains($unit))
                                                        <option value="{{ $unit }}">{{ $unit }} (saved unit)</option>
                                                    @endif
                                                    @foreach ($this->measurementUnitGroups() as $group => $units)
                                                        <optgroup label="{{ $group }}">
                                                            @foreach ($units as $measurementUnit)
                                                                <option value="{{ $measurementUnit }}">{{ $measurementUnit }}</option>
                                                            @endforeach
                                                        </optgroup>
                                                    @endforeach
                                                </select>
                                                @error('unit')
                                                    <p class="ui-error">{{ $message }}</p>
                                                @enderror
                                            </div>
                                            @if ($dataType === 'numeric')
                                                <div>
                                                    <label for="column-display-scale" class="ui-label">Decimal precision <span class="font-normal text-muted">(optional, 0–6)</span></label>
                                                    <input id="column-display-scale" type="text" inputmode="numeric" wire:model="displayScale" class="ui-input">
                                                    @error('displayScale')
                                                        <p class="ui-error">{{ $message }}</p>
                                                    @enderror
                                                </div>
                                            @elseif ($dataType === 'duration')
                                                <div>
                                                    <p class="ui-label">Display format</p>
                                                    <p class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-navy/70">hh:mm:ss</p>
                                                </div>
                                            @endif
                                        </div>
                                        @if ($valueSource === 'calculated')
                                            <div class="flex flex-col gap-3">
                                                <div>
                                                    <label for="column-formula" class="ui-label">Formula</label>
                                                    <select id="column-formula" wire:model.live="formulaCode" class="ui-input">
                                                        @foreach ($this->formulaCodes() as $code)
                                                            <option wire:key="formula-{{ $code }}" value="{{ $code }}">
                                                                {{ str_replace('_', ' ', $code) }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                    @error('formulaCode')
                                                        <p class="ui-error">{{ $message }}</p>
                                                    @enderror
                                                </div>
                                                @if ($this->formulaSources()->isEmpty())
                                                    <p class="text-sm text-navy/70">Add a {{ $dataType === 'duration' ? 'duration' : 'numeric' }} template or engineer column before using a formula.</p>
                                                @elseif ($this->usesPairedFormula())
                                                    <p class="text-sm text-muted">{{ $this->formulaPairHint() }}</p>
                                                    <div class="grid gap-4 sm:grid-cols-2">
                                                        <div>
                                                            <label for="deviation-first" class="ui-label">{{ $this->formulaFirstLabel() }}</label>
                                                            <select id="deviation-first" wire:model="deviationMinuend" class="ui-input">
                                                                <option value="">Choose a column</option>
                                                                @foreach ($this->formulaSources() as $source)
                                                                    <option wire:key="deviation-first-{{ $source->test_column_id }}" value="{{ $source->column_key }}">{{ $source->column_label }}</option>
                                                                @endforeach
                                                            </select>
                                                            @error('deviationMinuend')
                                                                <p class="ui-error">{{ $message }}</p>
                                                            @enderror
                                                        </div>
                                                        <div>
                                                            <label for="deviation-second" class="ui-label">{{ $this->formulaSecondLabel() }}</label>
                                                            <select id="deviation-second" wire:model="deviationSubtrahend" class="ui-input">
                                                                <option value="">Choose a column</option>
                                                                @foreach ($this->formulaSources() as $source)
                                                                    <option wire:key="deviation-second-{{ $source->test_column_id }}" value="{{ $source->column_key }}">{{ $source->column_label }}</option>
                                                                @endforeach
                                                            </select>
                                                            @error('deviationSubtrahend')
                                                                <p class="ui-error">{{ $message }}</p>
                                                            @enderror
                                                        </div>
                                                    </div>
                                                @else
                                                    <fieldset class="flex flex-col gap-2">
                                                        <legend class="ui-label">Average columns</legend>
                                                        @foreach ($this->formulaSources() as $source)
                                                            <label wire:key="average-source-{{ $source->test_column_id }}" class="flex items-center gap-2 text-sm text-navy">
                                                                <input type="checkbox" wire:model="formulaInputs" value="{{ $source->column_key }}" class="size-4 rounded border-line text-brand">
                                                                {{ $source->column_label }}
                                                            </label>
                                                        @endforeach
                                                        @error('formulaInputs')
                                                            <p class="ui-error">{{ $message }}</p>
                                                        @enderror
                                                        @error('formulaInputs.*')
                                                            <p class="ui-error">{{ $message }}</p>
                                                        @enderror
                                                    </fieldset>
                                                @endif
                                            </div>
                                        @endif
                                        <div class="sticky bottom-0 z-10 -mx-1 flex gap-2 border-t border-brand/25 bg-brand-soft/95 px-1 py-3 shadow-[0_-8px_16px_rgba(214,228,240,0.85)] backdrop-blur-sm">
                                            <button type="submit" class="ui-button-primary" wire:loading.attr="disabled" wire:target="saveTestColumn">
                                                <span wire:loading.remove wire:target="saveTestColumn">{{ $editingColumnId === null ? 'Save column' : 'Update column' }}</span>
                                                <span wire:loading wire:target="saveTestColumn">Saving...</span>
                                            </button>
                                            <button type="button" wire:click="{{ $editingColumnId === null ? 'cancelManagingColumns' : 'cancelEditingColumn' }}" class="ui-button-secondary">{{ $editingColumnId === null ? 'Close' : 'Cancel' }}</button>
                                        </div>
                                    </form>
                                </div>
                                        </div>
                                    </div>
                            @endif

                            @if ($profile->testColumns->isNotEmpty())
                                <div class="overflow-x-auto">
                                    <table class="ui-table">
                                        <thead class="bg-navy text-xs font-medium text-white">
                                            <tr>
                                                @foreach ($profile->testColumns as $column)
                                                    <th scope="col" wire:key="test-column-{{ $column->test_column_id }}" class="px-5 py-3 font-medium">{{ $column->column_label }}</th>
                                                @endforeach
                                                @if ($this->canEditTestTable($profile))
                                                <th scope="col" class="px-5 py-3 font-medium">Actions</th>
                                                @endif
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
                                                    @if ($this->canEditTestTable($profile))
                                                    <td class="px-5 py-3.5">
                                                        <div class="flex flex-wrap gap-2">
                                                            <button type="button" wire:click="editTestRow({{ $profile->test_profile_id }}, {{ $point->test_point_id }})" class="ui-button-outline">Edit</button>
                                                            <button
                                                                type="button"
                                                                data-component-id="{{ $this->getId() }}"
                                                                data-profile-id="{{ $profile->test_profile_id }}"
                                                                data-point-id="{{ $point->test_point_id }}"
                                                                x-on:click="window.instrumentsDeskNotifications.testRowRemovalConfirmation({ componentId: $el.dataset.componentId, profileId: Number($el.dataset.profileId), pointId: Number($el.dataset.pointId) })"
                                                                class="ui-button-danger"
                                                            >Delete</button>
                                                        </div>
                                                    </td>
                                                    @endif
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="{{ $profile->testColumns->count() + ($this->canEditTestTable($profile) ? 1 : 0) }}" class="px-5 py-4 text-sm text-navy/70">No test points yet.</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>

                                @if (false)
                                    <form wire:submit="saveTestRow" class="flex flex-col gap-4 rounded-xl border border-white/70 bg-[#edf4fa] p-4">
                                    <h3 class="text-sm font-semibold text-navy">Edit row</h3>
                                        <p class="text-sm text-muted">Template columns can be filled or left blank. Engineer and calculated columns stay empty here.</p>
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            @foreach ($profile->testColumns as $column)
                                                @if ($column->value_source === 'template')
                                                    <div wire:key="template-input-{{ $column->test_column_id }}">
                                                        <label for="template-{{ $profile->test_profile_id }}-{{ $column->test_column_id }}" class="ui-label">{{ $column->column_label }}</label>
                                                        <input
                                                            id="template-{{ $profile->test_profile_id }}-{{ $column->test_column_id }}"
                                                            type="text"
                                                            wire:model="templateValues.{{ $column->test_column_id }}"
                                                            @if ($column->data_type === 'numeric') inputmode="decimal" @endif
                                                            @if ($column->data_type === 'duration') placeholder="hh:mm:ss" @endif
                                                            class="ui-input"
                                                        >
                                                        @error('templateValues.'.$column->test_column_id)
                                                            <p class="ui-error">{{ $message }}</p>
                                                        @enderror
                                                    </div>
                                                @else
                                                    <div wire:key="readonly-cell-{{ $column->test_column_id }}">
                                                        <p class="ui-label">{{ $column->column_label }}</p>
                                                        <p class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-navy/70">—</p>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                        <div class="flex gap-2">
                                            <button type="submit" class="ui-button-primary" wire:loading.attr="disabled" wire:target="saveTestRow">
                                                <span wire:loading.remove wire:target="saveTestRow">Save row</span>
                                                <span wire:loading wire:target="saveTestRow">Saving...</span>
                                            </button>
                                            <button type="button" wire:click="cancelEditingRow" class="ui-button-secondary">Cancel</button>
                                        </div>
                                    </form>
                                @endif
                            @elseif ($profile->testPoints->isEmpty())
                            <p class="text-sm text-navy/70">No columns yet. Use Manage Columns to create the first column. The legacy layout stays on the instrument detail page until then.</p>
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
                                            <tr wire:key="legacy-point-{{ $point->test_point_id }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec]">
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
                            <p class="text-sm text-navy/70">This table still uses the legacy layout. Add columns to switch it to the dynamic editor.</p>
                            @endif
                            @if (filled($this->testTableRemarks($profile)))
                                <section class="mt-5 rounded-xl border border-brand/20 bg-[#edf4fa] p-4 sm:p-5" aria-labelledby="test-table-remarks-heading">
                                    <h4 id="test-table-remarks-heading" class="text-sm font-semibold text-navy">Remarks</h4>
                                    <p class="mt-2 whitespace-pre-line break-words text-sm leading-6 text-navy/75">{{ $this->testTableRemarks($profile) }}</p>
                                </section>
                            @endif
                </div>
            @endif
        </section>
            </div>
        </section>

    @if ($profile !== null && $this->canEditTestTable($profile))
        <div
            x-show="editingRowProfileId == {{ $profile->test_profile_id }} && editingPointId !== null"
            x-cloak
            x-transition:enter="transition-opacity ease-out duration-[200ms] motion-reduce:duration-0"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition-opacity ease-in duration-[180ms] motion-reduce:duration-0"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-50 overflow-y-auto bg-navy/35 p-4 sm:p-8"
            role="dialog"
            aria-modal="true"
            aria-labelledby="test-row-modal-title"
        >
            <div
                x-transition:enter="transition ease-out duration-[200ms] motion-reduce:duration-0"
                x-transition:enter-start="scale-[0.96] opacity-0"
                x-transition:enter-end="scale-100 opacity-100"
                x-transition:leave="transition ease-in duration-[180ms] motion-reduce:duration-0"
                x-transition:leave-start="scale-100 opacity-100"
                x-transition:leave-end="scale-[0.96] opacity-0"
                class="mx-auto flex max-h-[calc(100vh-4rem)] max-w-2xl flex-col overflow-hidden rounded-2xl border border-brand/20 bg-brand-soft shadow-2xl"
            >
                <div class="flex items-center justify-between bg-navy px-5 py-4 text-white">
                    <div>
                        <h2 id="test-row-modal-title" class="text-base font-semibold">{{ $addingTestRow ? 'Add Test Row' : 'Edit Test Row' }}</h2>
                        <p class="mt-1 text-sm text-brand-soft">{{ $this->testTableProfileName($profile) }}</p>
                    </div>
                    <button type="button" x-on:click="editingRowProfileId = null; editingPointId = null" wire:click="cancelEditingRow" class="rounded-lg px-3 py-2 text-xl leading-none text-white transition hover:bg-white/15" aria-label="Close Test Row modal">&times;</button>
                </div>

                <div class="flex-1 overflow-y-auto p-5 sm:p-6">
                    <form wire:submit="saveTestRow" class="flex flex-col gap-4">
                        <p class="text-sm text-navy/70">Template values can be filled or left blank. Engineer and calculated values are entered elsewhere.</p>

                        @foreach ($profile->testColumns as $column)
                            @if ($column->value_source === 'template')
                                <div wire:key="modal-template-input-{{ $column->test_column_id }}">
                                    <label for="modal-template-{{ $profile->test_profile_id }}-{{ $column->test_column_id }}" class="ui-label">
                                        {{ $column->column_label }}
                                        @if (filled($column->unit))
                                            <span class="font-normal text-muted">({{ $column->unit }})</span>
                                        @endif
                                    </label>
                                    <input
                                        id="modal-template-{{ $profile->test_profile_id }}-{{ $column->test_column_id }}"
                                        type="text"
                                        wire:model="templateValues.{{ $column->test_column_id }}"
                                        @if ($column->data_type === 'numeric') inputmode="decimal" @endif
                                        @if ($column->data_type === 'duration') placeholder="hh:mm:ss" @endif
                                        class="ui-input"
                                    >
                                    @error('templateValues.'.$column->test_column_id)
                                        <p class="ui-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            @else
                                <div wire:key="modal-readonly-cell-{{ $column->test_column_id }}">
                                    <p class="ui-label">
                                        {{ $column->column_label }}
                                        @if (filled($column->unit))
                                            <span class="font-normal text-muted">({{ $column->unit }})</span>
                                        @endif
                                    </p>
                                    <p class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-navy/70">Filled during calibration</p>
                                </div>
                            @endif
                        @endforeach

                        <div class="sticky bottom-0 z-10 -mx-1 flex gap-2 border-t border-brand/25 bg-brand-soft/95 px-1 py-3 shadow-[0_-8px_16px_rgba(214,228,240,0.85)] backdrop-blur-sm">
                            <button type="submit" class="ui-button-primary" wire:loading.attr="disabled" wire:target="saveTestRow">
                                <span wire:loading.remove wire:target="saveTestRow">Save Row</span>
                                <span wire:loading wire:target="saveTestRow">Saving...</span>
                            </button>
                            <button type="button" x-on:click="editingRowProfileId = null; editingPointId = null" wire:click="cancelEditingRow" class="ui-button-secondary" wire:loading.attr="disabled">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <div
        x-show="addingTestTable || editingProfileId !== null"
        x-cloak
        x-transition:enter="transition-opacity ease-out duration-[200ms] motion-reduce:duration-0"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-in duration-[180ms] motion-reduce:duration-0"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 overflow-y-auto bg-navy/35 p-4 sm:p-8"
        role="dialog"
        aria-modal="true"
        aria-labelledby="test-table-modal-title"
    >
            <div
                x-transition:enter="transition ease-out duration-[200ms] motion-reduce:duration-0"
                x-transition:enter-start="scale-[0.96] opacity-0"
                x-transition:enter-end="scale-100 opacity-100"
                x-transition:leave="transition ease-in duration-[180ms] motion-reduce:duration-0"
                x-transition:leave-start="scale-100 opacity-100"
                x-transition:leave-end="scale-[0.96] opacity-0"
                class="mx-auto flex max-h-[calc(100vh-4rem)] max-w-xl flex-col overflow-hidden rounded-2xl border border-brand/20 bg-brand-soft shadow-2xl"
            >
                <div class="flex items-center justify-between bg-navy px-5 py-4 text-white">
                    <div>
                        <h2 id="test-table-modal-title" class="text-base font-semibold">
                            {{ $addingTestTable ? 'Add Test Table' : 'Edit Test Table' }}
                        </h2>
                        <p class="mt-1 text-sm text-brand-soft">Test table configuration</p>
                    </div>
                    <button
                        type="button"
                        x-on:click="addingTestTable = false; editingProfileId = null" wire:click="{{ $addingTestTable ? 'cancelAddingTestTable' : 'cancelEditingTestTable' }}"
                        class="rounded-lg px-3 py-2 text-xl leading-none text-white transition hover:bg-white/15"
                        aria-label="Close Test Table modal"
                    >&times;</button>
                </div>

                <div class="flex-1 overflow-y-auto p-5 sm:p-6">
                    @if ($addingTestTable)
                        <form wire:submit="saveTestTable" class="flex flex-col gap-4">
                    @elseif ($editingProfileId !== null)
                        <form wire:submit="saveEditedTestTable({{ $editingProfileId }})" class="flex flex-col gap-4">
                    @endif
                    @if ($addingTestTable || $editingProfileId !== null)
                            @error('testTable')
                                <p class="ui-error">{{ $message }}</p>
                            @enderror

                            <div>
                                <label for="test-table-profile-name" class="ui-label">Test Table Name <span aria-hidden="true">*</span></label>
                                <input id="test-table-profile-name" type="text" wire:model="profileName" maxlength="150" required autofocus class="ui-input">
                                @error('profileName')
                                    <p class="ui-error">{{ $message }}</p>
                                @enderror
                            </div>

                            @if (! $addingTestTable)
                                <div>
                                    <label for="test-table-remarks" class="ui-label">Remarks <span class="font-normal text-muted">(optional)</span></label>
                                    <textarea id="test-table-remarks" wire:model="testTableRemarks" maxlength="5000" rows="4" class="ui-input"></textarea>
                                    @error('testTableRemarks')
                                        <p class="ui-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endif

                            <div>
                                <label for="test-table-display-title" class="ui-label">Display Title
                                    @if (! $addingTestTable && $this->activeTestProfiles()->count() > 1)
                                        <span aria-hidden="true">*</span>
                                    @else
                                        <span class="font-normal text-muted">(optional)</span>
                                    @endif
                                </label>
                                <input id="test-table-display-title" type="text" wire:model="displayTitle" maxlength="255" @required(! $addingTestTable && $this->activeTestProfiles()->count() > 1) class="ui-input">
                                <p class="mt-1 text-xs text-muted">Optional label shown with this test table.</p>
                                @error('displayTitle')
                                    <p class="ui-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="sticky bottom-0 z-10 -mx-1 flex gap-2 border-t border-brand/25 bg-brand-soft/95 px-1 py-3 shadow-[0_-8px_16px_rgba(214,228,240,0.85)] backdrop-blur-sm">
                                <button type="submit" class="ui-button-primary" wire:loading.attr="disabled">
                                    <span wire:loading.remove>{{ $addingTestTable ? 'Create Table' : 'Save' }}</span>
                                    <span wire:loading>Saving...</span>
                                </button>
                                <button type="button" x-on:click="addingTestTable = false; editingProfileId = null" wire:click="{{ $addingTestTable ? 'cancelAddingTestTable' : 'cancelEditingTestTable' }}" class="ui-button-secondary" wire:loading.attr="disabled">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
