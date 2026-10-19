<?php

use App\Livewire\Concerns\ManagesTestComponents;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\TestProfile;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use ManagesTestComponents;

    #[Locked]
    public Instrument $instrument;

    #[Locked]
    public InstrumentType $instrumentType;

    public function mount(Instrument $instrument, InstrumentType $instrumentType): void
    {
        $this->instrument = $instrument;
        $this->instrumentType = $instrumentType;
        $this->reloadManagedProfiles();
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

        return $this->instrumentType->testProfiles->first(
            fn (TestProfile $profile): bool => (int) $profile->test_profile_id === (int) $this->selectedProfileId,
        );
    }
};
?>

<div class="px-4 py-8 sm:px-6 lg:px-10">
    @php($profile = $this->selectedProfile())
    <div class="mx-auto flex max-w-6xl flex-col gap-8">
        <header>
            <a href="{{ route('instruments.types.show', [$instrument, $instrumentType]) }}" class="ui-button-secondary w-fit gap-2 shadow-[0_10px_18px_-14px_rgb(22_49_114/0.9)]">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 6 9 12l6 6" />
                </svg>
                Back to instrument detail
            </a>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight text-navy">Manage Test Components</h1>
            <p class="mt-1 text-sm text-muted">{{ $instrument->instrument_name }} · {{ $instrumentType->type_name }}</p>
        </header>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="test-tables-heading">
            <div class="grid lg:min-h-72 lg:grid-cols-4">
            <aside class="border-b border-white/70 lg:col-span-1 lg:border-b-0 lg:border-r" aria-label="Test tables">
                <div class="flex flex-col gap-3 px-5 py-4">
                    <h2 id="test-tables-heading" class="text-base font-semibold tracking-tight text-navy">Test Tables</h2>
                    <button type="button" wire:click="startAddingTestTable" class="ui-button-outline w-full">Add Test Table</button>
                </div>

            @if ($addingTestTable)
                    <form wire:submit="saveTestTable" class="flex flex-col gap-3 border-t border-white/70 px-5 py-4">
                    <div>
                        <label for="profile-name" class="ui-label">Profile name</label>
                        <input id="profile-name" type="text" wire:model="profileName" maxlength="150" required class="ui-input">
                        @error('profileName')
                            <p class="ui-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="display-title" class="ui-label">
                            Table title
                            @unless ($this->tableTitleRequired())
                                <span class="font-normal text-muted">(optional)</span>
                            @endunless
                        </label>
                        <input id="display-title" type="text" wire:model="displayTitle" maxlength="255" @if ($this->tableTitleRequired()) required @endif class="ui-input">
                        @error('displayTitle')
                            <p class="ui-error">{{ $message }}</p>
                        @enderror
                    </div>
                    @if ($this->needsExistingTableTitle())
                        <div>
                            <label for="existing-display-title" class="ui-label">Title for the existing test table</label>
                            <input id="existing-display-title" type="text" wire:model="existingDisplayTitle" maxlength="255" required class="ui-input">
                            @error('existingDisplayTitle')
                                <p class="ui-error">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif
                    <div class="flex gap-2">
                            <button type="submit" class="ui-button-primary" wire:loading.attr="disabled" wire:target="saveTestTable">Save</button>
                        <button type="button" wire:click="cancelAddingTestTable" class="ui-button-secondary">Cancel</button>
                    </div>
                </form>
            @endif

                @if ($testTableStatus)
                    <p class="ui-alert mx-5 mb-4" role="status">{{ $testTableStatus }}</p>
                @endif

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
                            <h3 class="text-sm font-semibold tracking-tight text-navy">Archived Test Tables</h3>
                            <ul class="mt-3 flex flex-col gap-1">
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
                                    @if ((int) $editingProfileId === (int) $profile->test_profile_id)
                                        <form wire:submit="saveEditedTestTable({{ $profile->test_profile_id }})" class="flex flex-col gap-3">
                                            <div>
                                                <label for="edit-profile-name" class="ui-label">Profile name</label>
                                                <input id="edit-profile-name" type="text" wire:model="profileName" maxlength="150" required class="ui-input">
                                                @error('profileName')
                                                    <p class="ui-error">{{ $message }}</p>
                                                @enderror
                                            </div>
                                            <div>
                                                <label for="edit-display-title" class="ui-label">
                                                    Table title
                                                    @if ($this->activeTestProfiles()->count() < 2)
                                                        <span class="font-normal text-muted">(optional)</span>
                                                    @endif
                                                </label>
                                                <input id="edit-display-title" type="text" wire:model="displayTitle" maxlength="255" @if ($this->activeTestProfiles()->count() > 1) required @endif class="ui-input">
                                                @error('displayTitle')
                                                    <p class="ui-error">{{ $message }}</p>
                                                @enderror
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="submit" class="ui-button-primary" wire:loading.attr="disabled" wire:target="saveEditedTestTable">Save</button>
                                                <button type="button" wire:click="cancelEditingTestTable" class="ui-button-secondary">Cancel</button>
                                            </div>
                                        </form>
                                    @elseif ((int) $deletingProfileId === (int) $profile->test_profile_id)
                                        <div class="flex flex-col gap-2">
                                            <p class="text-sm text-navy">Delete {{ $this->testTableProfileName($profile) }}? This cannot be undone.</p>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" wire:click="deleteTestTable({{ $profile->test_profile_id }})" class="ui-button-danger" wire:loading.attr="disabled" wire:target="deleteTestTable">Delete Table</button>
                                                <button type="button" wire:click="cancelDeletingTestTable" class="ui-button-secondary">Cancel</button>
                                            </div>
                                        </div>
                                    @elseif ((int) $archivingProfileId === (int) $profile->test_profile_id)
                                        <div class="flex flex-col gap-2">
                                            <p class="text-sm text-navy">Archive {{ $this->testTableProfileName($profile) }}? Historical calibrations stay unchanged.</p>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" wire:click="archiveTestTable({{ $profile->test_profile_id }})" class="ui-button-primary" wire:loading.attr="disabled" wire:target="archiveTestTable">Archive Table</button>
                                                <button type="button" wire:click="cancelArchivingTestTable" class="ui-button-secondary">Cancel</button>
                                            </div>
                                        </div>
                                    @elseif ((int) $restoringProfileId === (int) $profile->test_profile_id)
                                        <div class="flex flex-col gap-2">
                                            <p class="text-sm font-semibold text-navy">Restore Test Table?</p>
                                            <p class="text-sm text-navy">Restore "{{ $this->testTableProfileName($profile) }}" to Test Tables? Its existing versions, columns, rows, and formulas will be preserved.</p>
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" wire:click="restoreTestTable({{ $profile->test_profile_id }})" class="ui-button-primary" wire:loading.attr="disabled" wire:target="restoreTestTable">Restore Table</button>
                                                <button type="button" wire:click="cancelRestoringTestTable" class="ui-button-secondary">Cancel</button>
                                            </div>
                                        </div>
                                    @else
                                    @if ($this->canPublishTestTable($profile))
                                        @if ((int) $publishingProfileId === (int) $profile->test_profile_id)
                                            <div class="flex flex-col gap-2">
                                                <p class="text-sm text-navy">Publish this draft as the active version?</p>
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" wire:click="publishVersion({{ $profile->test_profile_id }})" class="ui-button-primary" wire:loading.attr="disabled" wire:target="publishVersion">Publish Version</button>
                                                    <button type="button" wire:click="cancelPublishingVersion" class="ui-button-secondary">Cancel</button>
                                                </div>
                                            </div>
                                        @else
                                            <button type="button" wire:click="askToPublishVersion({{ $profile->test_profile_id }})" class="ui-button-outline">
                                                Publish Version
                                            </button>
                                        @endif
                                    @endif
                                    @if ($this->canForkTestTable($profile))
                                        <button type="button" wire:click="forkDraft({{ $profile->test_profile_id }})" class="ui-button-outline" wire:loading.attr="disabled" wire:target="forkDraft">
                                            Fork Draft
                                        </button>
                                    @endif
                                    @if ($this->canRenameTestTable($profile))
                                        <button type="button" wire:click="startEditingTestTable({{ $profile->test_profile_id }})" class="ui-button-outline">
                                            Edit Table
                                        </button>
                                    @endif
                                    @if ($this->canEditTestTable($profile))
                                    <button type="button" wire:click="startManagingColumns({{ $profile->test_profile_id }})" class="ui-button-outline">
                                        Manage Columns
                                    </button>
                                    @if ($profile->testColumns->isNotEmpty())
                                        <button type="button" wire:click="startAddingRow({{ $profile->test_profile_id }})" class="ui-button-outline">
                                            Add Row
                                        </button>
                                    @endif
                                    @endif
                                    @if ($this->canPermanentlyDeleteTestTable($profile))
                                        <button type="button" wire:click="askToDeleteTestTable({{ $profile->test_profile_id }})" class="ui-button-danger">
                                            Delete Table
                                        </button>
                                    @elseif ($this->canArchiveTestTable($profile))
                                        <button type="button" wire:click="askToArchiveTestTable({{ $profile->test_profile_id }})" class="ui-button-outline">
                                            Archive Table
                                        </button>
                                    @endif
                                    @if ($this->canRestoreTestTable($profile))
                                        <button type="button" wire:click="askToRestoreTestTable({{ $profile->test_profile_id }})" class="ui-button-outline">
                                            Restore Table
                                        </button>
                                    @endif
                                    @endif
                                </div>
                        @endif
                            </div>

                    <div class="flex flex-col gap-5 px-5 pb-5">
                            @if ($this->canEditTestTable($profile) && (int) $managingColumnsProfileId === (int) $profile->test_profile_id)
                                <div class="flex flex-col gap-4 rounded-xl border border-white/70 bg-[#edf4fa] p-4">
                                    @if ($columnStatus)
                                        <p class="ui-alert" role="status">{{ $columnStatus }}</p>
                                    @endif
                                    @error('columns')
                                        <p class="ui-error">{{ $message }}</p>
                                    @enderror

                                    @if ($profile->testColumns->isEmpty())
                                        <p class="text-sm text-navy/70">No columns yet. This table keeps its current layout until the first column is saved.</p>
                                    @else
                                        <ul class="flex flex-col gap-2">
                                            @foreach ($profile->testColumns as $column)
                                                <li wire:key="manage-column-{{ $column->test_column_id }}" class="flex flex-col gap-2 rounded-lg bg-white px-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                                                    <div>
                                                        <p class="text-sm font-semibold text-navy">{{ $column->column_label }}</p>
                                                        <p class="text-xs text-muted">{{ $column->column_key }} · {{ $this->columnSummary($column) }}</p>
                                                    </div>
                                                    @if ((int) $deletingColumnId === (int) $column->test_column_id)
                                                        <div class="flex flex-col gap-2">
                                                            <p class="text-sm text-navy">Delete this column?</p>
                                                            <div class="flex gap-2">
                                                                <button type="button" wire:click="deleteTestColumn" class="ui-button-danger" wire:loading.attr="disabled" wire:target="deleteTestColumn">Delete column</button>
                                                                <button type="button" wire:click="cancelDeletingTestColumn" class="ui-button-secondary">Cancel</button>
                                                            </div>
                                                        </div>
                                                    @else
                                                        <div class="flex flex-wrap gap-2">
                                                            <button type="button" wire:click="moveTestColumn({{ $profile->test_profile_id }}, {{ $column->test_column_id }}, 'up')" class="ui-button-secondary disabled:cursor-not-allowed disabled:opacity-40" @disabled($loop->first)>Move up</button>
                                                            <button type="button" wire:click="moveTestColumn({{ $profile->test_profile_id }}, {{ $column->test_column_id }}, 'down')" class="ui-button-secondary disabled:cursor-not-allowed disabled:opacity-40" @disabled($loop->last)>Move down</button>
                                                            <button type="button" wire:click="editTestColumn({{ $profile->test_profile_id }}, {{ $column->test_column_id }})" class="ui-button-outline">Edit</button>
                                                            <button type="button" wire:click="askToDeleteTestColumn({{ $profile->test_profile_id }}, {{ $column->test_column_id }})" class="ui-button-danger">Delete</button>
                                                        </div>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    <form wire:submit="saveTestColumn" class="flex flex-col gap-4">
                                    <h3 class="text-sm font-semibold text-navy">{{ $editingColumnId === null ? 'Add column' : 'Edit column' }}</h3>
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
                                                <input id="column-unit" type="text" wire:model="unit" maxlength="50" class="ui-input">
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
                                        <div class="flex gap-2">
                                            <button type="submit" class="ui-button-primary" wire:loading.attr="disabled" wire:target="saveTestColumn">
                                                <span wire:loading.remove wire:target="saveTestColumn">{{ $editingColumnId === null ? 'Save column' : 'Update column' }}</span>
                                                <span wire:loading wire:target="saveTestColumn">Saving...</span>
                                            </button>
                                            <button type="button" wire:click="cancelManagingColumns" class="ui-button-secondary">Close</button>
                                        </div>
                                    </form>
                                </div>
                            @endif

                            @if ($profile->testColumns->isNotEmpty())
                                @if ((int) $rowStatusProfileId === (int) $profile->test_profile_id && ($rowStatus || $errors->has('rows')))
                                    <div class="flex flex-col gap-2">
                                        @if ($rowStatus)
                                            <p class="ui-alert" role="status">{{ $rowStatus }}</p>
                                        @endif
                                        @error('rows')
                                            <p class="ui-error">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @endif

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
                                                        @if ((int) $deletingPointId === (int) $point->test_point_id && (int) $editingRowProfileId === (int) $profile->test_profile_id)
                                                            <div class="flex flex-col gap-2">
                                                                <p class="text-sm text-navy">Delete this row?</p>
                                                                <div class="flex flex-wrap gap-2">
                                                                    <button type="button" wire:click="deleteTestRow" class="ui-button-danger" wire:loading.attr="disabled" wire:target="deleteTestRow">Delete row</button>
                                                                    <button type="button" wire:click="cancelDeletingTestRow" class="ui-button-secondary">Cancel</button>
                                                                </div>
                                                            </div>
                                                        @else
                                                            <div class="flex flex-wrap gap-2">
                                                                <button type="button" wire:click="moveTestRow({{ $profile->test_profile_id }}, {{ $point->test_point_id }}, 'up')" class="ui-button-secondary disabled:cursor-not-allowed disabled:opacity-40" @disabled($loop->first)>Move up</button>
                                                                <button type="button" wire:click="moveTestRow({{ $profile->test_profile_id }}, {{ $point->test_point_id }}, 'down')" class="ui-button-secondary disabled:cursor-not-allowed disabled:opacity-40" @disabled($loop->last)>Move down</button>
                                                                <button type="button" wire:click="editTestRow({{ $profile->test_profile_id }}, {{ $point->test_point_id }})" class="ui-button-outline">Edit</button>
                                                                <button type="button" wire:click="askToDeleteTestRow({{ $profile->test_profile_id }}, {{ $point->test_point_id }})" class="ui-button-danger">Delete</button>
                                                            </div>
                                                        @endif
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

                                @if ($this->canEditTestTable($profile) && (int) $editingRowProfileId === (int) $profile->test_profile_id && $editingPointId !== null && (int) $deletingPointId !== (int) $editingPointId)
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
                </div>
            @endif
        </section>
            </div>
        </section>
    </div>
</div>
