<?php

use App\Livewire\Concerns\ManagesStandardAssignments;
use App\Models\Instrument;
use App\Models\InstrumentType;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use ManagesStandardAssignments;

    #[Locked]
    public Instrument $instrument;

    #[Locked]
    public InstrumentType $instrumentType;

    public function mount(Instrument $instrument, InstrumentType $instrumentType): void
    {
        $this->instrument = $instrument;
        $this->instrumentType = $instrumentType;
        $this->reloadAssignments();
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
};
?>

<div class="px-4 py-8 sm:px-6 lg:px-10">
    <div class="mx-auto flex max-w-6xl flex-col gap-8">
        <header>
            <a href="{{ route('instruments.types.show', [$instrument, $instrumentType]) }}" class="ui-button-secondary w-fit gap-2 shadow-[0_10px_18px_-14px_rgb(22_49_114/0.9)]">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 6 9 12l6 6" />
                </svg>
                Back to instrument detail
            </a>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight text-navy">Manage Standards</h1>
            <p class="mt-1 text-sm text-muted">{{ $instrument->instrument_name }} · {{ $instrumentType->type_name }}</p>
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
                                        @if ((int) $removingAssignmentId === (int) $assignment->instrument_type_standard_assignment_id)
                                            <div class="flex flex-col gap-2">
                                                <p class="text-sm text-navy">Remove this standard assignment? The master standard stays registered.</p>
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" wire:click="removeAssignment" class="ui-button-danger" wire:loading.attr="disabled" wire:target="removeAssignment">Remove assignment</button>
                                                    <button type="button" wire:click="cancelRemoving" class="ui-button-secondary">Cancel</button>
                                                </div>
                                            </div>
                                        @else
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" wire:click="moveAssignment({{ $assignment->instrument_type_standard_assignment_id }}, -1)" class="ui-button-outline" @disabled($loop->first)>Move up</button>
                                                <button type="button" wire:click="moveAssignment({{ $assignment->instrument_type_standard_assignment_id }}, 1)" class="ui-button-outline" @disabled($loop->last)>Move down</button>
                                                <button type="button" wire:click="askToRemove({{ $assignment->instrument_type_standard_assignment_id }})" class="ui-button-danger">Remove</button>
                                            </div>
                                        @endif
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
                    <button type="button" wire:click="startAddingStandard" class="ui-button-outline">Add New Standard</button>
                </div>

                @if ($addingStandard)
                    <form wire:submit="createStandard" class="flex flex-col gap-3 rounded-xl border border-white/70 bg-[#edf4fa] p-4">
                        <p class="text-sm font-semibold text-navy">Add New Standard</p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label for="assign-new-name" class="ui-label">Instrument Name</label>
                                <input id="assign-new-name" type="text" wire:model="newInstrumentName" maxlength="150" required class="ui-input">
                                @error('newInstrumentName')
                                    <p class="ui-error">{{ $message }}</p>
                                @enderror
                            </div>
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
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="ui-button-primary" wire:loading.attr="disabled" wire:target="createStandard">Save Standard</button>
                            <button type="button" wire:click="cancelAddingStandard" class="ui-button-secondary">Cancel</button>
                        </div>
                    </form>
                @endif

                @php($selected = $this->selectedCandidate())
                @if ($selected !== null)
                    <form wire:submit="assignStandard" class="flex flex-col gap-3 rounded-xl border border-white/70 bg-[#edf4fa] p-4">
                        <div>
                            <p class="ui-label">Selected Standard</p>
                            <p class="text-sm font-semibold text-navy">{{ $selected->standard_code }} · {{ $selected->instrument_name }}</p>
                            <p class="text-sm text-muted">{{ filled($selected->type_specification) ? $selected->type_specification : '—' }} · {{ filled($selected->serial_number) ? $selected->serial_number : '—' }}</p>
                        </div>
                        @error('selectedStandardId')
                            <p class="ui-error">{{ $message }}</p>
                        @enderror
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="ui-button-primary" wire:loading.attr="disabled" wire:target="assignStandard">Assign Standard</button>
                            <button type="button" wire:click="clearSelection" class="ui-button-secondary">Clear</button>
                        </div>
                    </form>
                @endif

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
                                            <button type="button" wire:click="selectCandidate({{ $candidate->standard_id }})" class="ui-button-outline">Select</button>
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
</div>
