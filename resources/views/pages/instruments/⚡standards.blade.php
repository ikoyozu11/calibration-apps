<?php

use App\CalibrationSessions\CalibrationSessionHttpRead;
use App\Livewire\Concerns\ManagesStandardAssignments;
use App\Models\Instrument;
use App\Models\InstrumentType;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use ManagesStandardAssignments;

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
        $this->reloadAssignments();
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
        $this->reloadAssignments();
    }

    /**
     * @param  mixed  $view
     * @param  mixed  $data
     */
    public function rendering(mixed $view = null, mixed $data = null): void
    {
        $this->reloadAssignments();
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
    data-component-id="{{ $this->getId() }}"
    x-data="{
        addingStandard: $wire.entangle('addingStandard'),
        requestCloseStandardModal() {
            window.instrumentsDeskNotifications.standardDiscardConfirmation({
                componentId: $root.dataset.componentId,
            });
        },
    }"
    x-on:keydown.escape.window="if (addingStandard) requestCloseStandardModal()"
    x-init="$watch('addingStandard', (open) => {
        $nextTick(() => {
            if (open) {
                $refs.standardName?.focus();
            } else {
                $refs.addStandardButton?.focus();
            }
        });
    })"
>
    <div class="mx-auto flex max-w-6xl flex-col gap-8">
        <header>
            <a href="{{ route('instruments.types.show', ['instrument' => $this->instrumentId, 'instrumentType' => $this->instrumentTypeId]) }}" class="ui-button-secondary w-fit gap-2 shadow-[0_10px_18px_-14px_rgb(22_49_114/0.9)]">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 6 9 12l6 6" />
                </svg>
                Back to instrument detail
            </a>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight text-navy">Manage Standards</h1>
            <p class="mt-1 text-sm text-muted">{{ $this->currentInstrument()->instrument_name }} · {{ $this->currentInstrumentType()->type_name }}</p>
        </header>

        @if ($status)
            <p class="ui-alert" role="status">{{ $status }}</p>
        @endif
        @error('standard')
            <p class="ui-error">{{ $message }}</p>
        @enderror

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="assigned-standards-heading">
            <div class="px-5 py-4">
                <h2 id="assigned-standards-heading" class="text-base font-semibold tracking-tight text-navy">Assigned Standards</h2>
            </div>

            @if ($this->assignedStandards()->isEmpty())
                <p class="px-5 py-8 text-sm text-navy/70">No standards assigned.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead class="bg-navy text-xs font-medium text-white">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-medium">No.</th>
                                <th scope="col" class="px-5 py-3 font-medium">Standard ID</th>
                                <th scope="col" class="px-5 py-3 font-medium">Instrument Name</th>
                                <th scope="col" class="px-5 py-3 font-medium">Type / Specification</th>
                                <th scope="col" class="px-5 py-3 font-medium">Serial Number</th>
                                <th scope="col" class="px-5 py-3 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/70">
                            @foreach ($this->assignedStandards() as $assignment)
                                <tr wire:key="assigned-standard-{{ $assignment->instrument_type_standard_assignment_id }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec]">
                                    <td class="px-5 py-3.5 tabular-nums text-navy">{{ $assignment->assignment_order }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ filled($assignment->standardCode()) ? $assignment->standardCode() : '—' }}</td>
                                    <td class="px-5 py-3.5 font-medium text-navy">{{ filled($assignment->instrumentName()) ? $assignment->instrumentName() : '—' }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ filled($assignment->typeSpecification()) ? $assignment->typeSpecification() : '—' }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ filled($assignment->serialNumber()) ? $assignment->serialNumber() : '—' }}</td>
                                    <td class="px-5 py-3.5">
                                        <button
                                            type="button"
                                            data-component-id="{{ $this->getId() }}"
                                            data-assignment-id="{{ $assignment->instrument_type_standard_assignment_id }}"
                                            data-standard-name="{{ $assignment->instrumentName() }}"
                                            x-on:click="window.instrumentsDeskNotifications.standardAssignmentRemovalConfirmation({
                                                componentId: $el.dataset.componentId,
                                                assignmentId: Number($el.dataset.assignmentId),
                                                standardName: $el.dataset.standardName,
                                            })"
                                            class="ui-button-danger"
                                        >Remove</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="assign-standard-heading">
            <div class="px-5 py-4">
                <h2 id="assign-standard-heading" class="text-base font-semibold tracking-tight text-navy">Assign Standard</h2>
                <p class="mt-1 text-sm text-muted">Search the Standard(s) Used master by name, type / specification, or serial number.</p>
            </div>

            <div class="flex flex-col gap-5 px-5 pb-5">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <label for="standard-search" class="ui-label">Search</label>
                        <input id="standard-search" type="search" wire:model.live.debounce.300ms="search" maxlength="100" class="ui-input" placeholder="Name, type / specification, or serial">
                    </div>
                    <button
                        type="button"
                        x-ref="addStandardButton"
                        x-on:click="addingStandard = true"
                        wire:click="startAddingStandard"
                        class="ui-button-outline"
                    >Add New Standard</button>
                </div>

                @if ($this->candidates()->isEmpty())
                    <p class="py-4 text-sm text-navy/70">No matching standards found. Add a new standard if it is not in the master list.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="ui-table">
                            <thead class="bg-navy text-xs font-medium text-white">
                                <tr>
                                    <th scope="col" class="px-5 py-3 font-medium">Standard ID</th>
                                    <th scope="col" class="px-5 py-3 font-medium">Instrument Name</th>
                                    <th scope="col" class="px-5 py-3 font-medium">Type / Specification</th>
                                    <th scope="col" class="px-5 py-3 font-medium">Serial Number</th>
                                    <th scope="col" class="px-5 py-3 font-medium">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/70">
                                @foreach ($this->candidates() as $candidate)
                                    <tr wire:key="standard-candidate-{{ $candidate->standard_id }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec]">
                                        <td class="px-5 py-3.5 text-navy">{{ $candidate->standard_code }}</td>
                                        <td class="px-5 py-3.5 font-medium text-navy">{{ $candidate->instrument_name }}</td>
                                        <td class="px-5 py-3.5 text-navy">{{ filled($candidate->type_specification) ? $candidate->type_specification : '—' }}</td>
                                        <td class="px-5 py-3.5 text-navy">{{ filled($candidate->serial_number) ? $candidate->serial_number : '—' }}</td>
                                        <td class="px-5 py-3.5">
                                            <button type="button" wire:click="selectCandidate({{ $candidate->standard_id }})" wire:loading.attr="disabled" wire:target="selectCandidate" class="ui-button-outline">Select</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div>
                        {{ $this->candidates()->links() }}
                    </div>
                @endif
            </div>
        </section>
    </div>

    <div
        x-show="addingStandard"
        x-cloak
        x-on:click.self="requestCloseStandardModal()"
        x-transition:enter="transition-opacity ease-out duration-[200ms] motion-reduce:duration-0"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition-opacity ease-in duration-[180ms] motion-reduce:duration-0"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 overflow-y-auto bg-navy/35 p-4 sm:p-8"
        role="dialog"
        aria-modal="true"
        aria-labelledby="add-standard-modal-title"
        aria-describedby="add-standard-modal-subtitle"
    >
        <div
            x-transition:enter="transition ease-out duration-[200ms] motion-reduce:duration-0"
            x-transition:enter-start="scale-[0.96] opacity-0"
            x-transition:enter-end="scale-100 opacity-100"
            x-transition:leave="transition ease-in duration-[180ms] motion-reduce:duration-0"
            x-transition:leave-start="scale-100 opacity-100"
            x-transition:leave-end="scale-[0.96] opacity-0"
            class="mx-auto flex max-h-[calc(100vh-4rem)] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-brand/20 bg-brand-soft shadow-2xl"
        >
            <div class="flex items-start justify-between bg-navy px-5 py-4 text-white sm:px-6">
                <div>
                    <h2 id="add-standard-modal-title" class="text-base font-semibold">Add New Standard</h2>
                    <p id="add-standard-modal-subtitle" class="mt-1 text-sm text-brand-soft">Create a new standard in the Standard Master.</p>
                </div>
                <button
                    type="button"
                    x-on:click="requestCloseStandardModal()"
                    class="rounded-lg px-3 py-2 text-xl leading-none text-white transition hover:bg-white/15"
                    aria-label="Close Add New Standard modal"
                >&times;</button>
            </div>

            <div class="flex-1 overflow-y-auto p-5 sm:p-6">
                <form wire:submit="prepareNewStandardCreation" class="flex flex-col gap-4">
                    <div>
                        <label for="assign-new-name" class="ui-label">Instrument Name <span class="text-red-700">*</span></label>
                        <input id="assign-new-name" x-ref="standardName" type="text" wire:model="newInstrumentName" maxlength="150" required class="ui-input">
                        @error('newInstrumentName')
                            <p class="ui-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="assign-new-type" class="ui-label">Type / Specification <span class="font-normal text-muted">(optional)</span></label>
                            <input id="assign-new-type" type="text" wire:model="newTypeSpecification" maxlength="150" class="ui-input">
                            @error('newTypeSpecification')
                                <p class="ui-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="assign-new-serial" class="ui-label">Serial Number <span class="font-normal text-muted">(optional)</span></label>
                            <input id="assign-new-serial" type="text" wire:model="newSerialNumber" maxlength="150" class="ui-input">
                            @error('newSerialNumber')
                                <p class="ui-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="mt-1 flex flex-wrap gap-2 border-t border-brand/20 pt-4">
                        <button type="submit" class="ui-button-primary" wire:loading.attr="disabled" wire:target="prepareNewStandardCreation,createStandard">
                            <span wire:loading.remove wire:target="prepareNewStandardCreation,createStandard">Save Standard</span>
                            <span wire:loading wire:target="prepareNewStandardCreation,createStandard">Saving...</span>
                        </button>
                        <button type="button" x-on:click="requestCloseStandardModal()" class="ui-button-secondary" wire:loading.attr="disabled">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
