<?php

use App\Models\StandardMaster;
use App\Standards\StandardMasterException;
use App\Standards\StandardMasterWriter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $adding = false;

    public ?int $editingStandardId = null;

    public ?int $removingStandardId = null;

    public string $instrumentName = '';

    public string $typeSpecification = '';

    public string $serialNumber = '';

    public string $status = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function startAdding(): void
    {
        $this->adding = true;
        $this->editingStandardId = null;
        $this->removingStandardId = null;
        $this->instrumentName = '';
        $this->typeSpecification = '';
        $this->serialNumber = '';
        $this->resetValidation();
    }

    public function cancelForm(): void
    {
        $this->adding = false;
        $this->editingStandardId = null;
        $this->removingStandardId = null;
        $this->instrumentName = '';
        $this->typeSpecification = '';
        $this->serialNumber = '';
        $this->resetValidation();
    }

    public function saveStandard(): void
    {
        $this->validate([
            'instrumentName' => ['required', 'string', 'max:'.StandardMasterWriter::NAME_MAX],
            'typeSpecification' => ['nullable', 'string', 'max:'.StandardMasterWriter::TYPE_MAX],
            'serialNumber' => ['nullable', 'string', 'max:'.StandardMasterWriter::SERIAL_MAX],
        ], [
            'instrumentName.required' => 'Instrument Name is required.',
        ]);

        $writer = app(StandardMasterWriter::class);

        try {
            if ($this->editingStandardId !== null) {
                $writer->update($this->editingStandardId, $this->instrumentName, $this->typeSpecification, $this->serialNumber);
                $this->status = 'Standard updated.';
            } else {
                $writer->create($this->instrumentName, $this->typeSpecification, $this->serialNumber);
                $this->status = 'Standard saved.';
            }
        } catch (StandardMasterException $exception) {
            $this->addError($exception->field === 'standard' ? 'instrumentName' : $exception->field, $exception->getMessage());

            return;
        }

        $this->cancelForm();
        $this->resetPage();
    }

    public function startEditing(int $standardId): void
    {
        $standard = StandardMaster::query()->find($standardId);

        if ($standard === null) {
            $this->addError('standard', 'This standard could not be found.');

            return;
        }

        $this->editingStandardId = $standardId;
        $this->adding = false;
        $this->removingStandardId = null;
        $this->instrumentName = $standard->instrument_name;
        $this->typeSpecification = (string) ($standard->type_specification ?? '');
        $this->serialNumber = (string) ($standard->serial_number ?? '');
        $this->resetValidation();
    }

    public function askToRemove(int $standardId): void
    {
        $this->removingStandardId = $standardId;
        $this->adding = false;
        $this->editingStandardId = null;
        $this->resetValidation();
    }

    public function removeStandard(): void
    {
        if ($this->removingStandardId === null) {
            return;
        }

        try {
            app(StandardMasterWriter::class)->delete($this->removingStandardId);
        } catch (StandardMasterException $exception) {
            $this->addError('standard', $exception->getMessage());
            $this->removingStandardId = null;

            return;
        }

        $this->status = 'Standard deleted.';
        $this->removingStandardId = null;
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, StandardMaster>
     */
    public function standards(): LengthAwarePaginator
    {
        return StandardMaster::query()
            ->with([
                'assignments' => function (HasMany $query): void {
                    $query->select([
                        'instrument_type_standard_assignment_id',
                        'instrument_type_id',
                        'standard_id',
                        'assignment_order',
                    ]);
                },
                'assignments.instrumentType' => function (BelongsTo $query): void {
                    $query->select(['instrument_type_id', 'instrument_id', 'type_name']);
                },
                'assignments.instrumentType.instrument' => function (BelongsTo $query): void {
                    $query->select(['instrument_id', 'instrument_name']);
                },
            ])
            ->whereStandardMasterMatches($this->search)
            ->orderBy('standard_code')
            ->paginate(10);
    }
};
?>

<div class="px-4 py-8 sm:px-6 lg:px-10">
    <div class="mx-auto flex max-w-6xl flex-col gap-8">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight text-navy">Standard(s) Used</h1>
            <p class="mt-1 text-sm text-muted">Register master standards independently of the Instruments list.</p>
        </header>

        @if ($status)
            <p class="ui-alert" role="status">{{ $status }}</p>
        @endif
        @error('standard')
            <p class="ui-error">{{ $message }}</p>
        @enderror

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="standard-master-heading">
            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 id="standard-master-heading" class="text-base font-semibold tracking-tight text-navy">Standard Master</h2>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <label for="standard-master-search" class="sr-only">Search by name, type, or serial number</label>
                    <input id="standard-master-search" type="search" wire:model.live.debounce.300ms="search" maxlength="100" class="ui-input" placeholder="Name, type / specification, or serial">
                    <button type="button" wire:click="startAdding" class="ui-button-outline">Add New Standard</button>
                </div>
            </div>

            @if ($adding || $editingStandardId !== null)
                <form wire:submit="saveStandard" class="flex flex-col gap-3 border-t border-white/70 px-5 py-4">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label for="master-instrument-name" class="ui-label">Instrument Name</label>
                            <input id="master-instrument-name" type="text" wire:model="instrumentName" maxlength="150" required class="ui-input">
                            @error('instrumentName')
                                <p class="ui-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="master-type-specification" class="ui-label">Type / Specification <span class="font-normal text-muted">(optional)</span></label>
                            <input id="master-type-specification" type="text" wire:model="typeSpecification" maxlength="150" class="ui-input">
                            @error('typeSpecification')
                                <p class="ui-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="master-serial-number" class="ui-label">Serial Number <span class="font-normal text-muted">(optional)</span></label>
                            <input id="master-serial-number" type="text" wire:model="serialNumber" maxlength="150" class="ui-input">
                            @error('serialNumber')
                                <p class="ui-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="submit" class="ui-button-primary" wire:loading.attr="disabled" wire:target="saveStandard">{{ $editingStandardId !== null ? 'Save changes' : 'Save Standard' }}</button>
                        <button type="button" wire:click="cancelForm" class="ui-button-secondary">Cancel</button>
                    </div>
                </form>
            @endif

            @if ($this->standards()->isEmpty())
                <p class="px-5 py-8 text-sm text-navy/70">No standards found.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead class="bg-navy text-xs font-medium text-white">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-medium">Standard ID</th>
                                <th scope="col" class="px-5 py-3 font-medium">Instrument Name</th>
                                <th scope="col" class="px-5 py-3 font-medium">Type / Specification</th>
                                <th scope="col" class="px-5 py-3 font-medium">Serial Number</th>
                                <th scope="col" class="px-5 py-3 font-medium">Assigned To</th>
                                <th scope="col" class="px-5 py-3 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/70">
                            @foreach ($this->standards() as $standard)
                                <tr wire:key="standard-master-{{ $standard->standard_id }}" class="odd:bg-[#d6e4f0] even:bg-[#c5d8ec]">
                                    <td class="px-5 py-3.5 font-medium text-navy">{{ $standard->standard_code }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ $standard->instrument_name }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ filled($standard->type_specification) ? $standard->type_specification : '—' }}</td>
                                    <td class="px-5 py-3.5 text-navy">{{ filled($standard->serial_number) ? $standard->serial_number : '—' }}</td>
                                    <td class="px-5 py-3.5 text-navy">
                                        @if ($standard->assignments->isEmpty())
                                            —
                                        @else
                                            <ul class="flex flex-col gap-1 text-sm">
                                                @foreach ($standard->assignments as $assignment)
                                                    <li>{{ $assignment->instrumentType?->instrument?->instrument_name ?? '—' }} · {{ $assignment->instrumentType?->type_name ?? '—' }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5">
                                        @if ((int) $removingStandardId === (int) $standard->standard_id)
                                            <div class="flex flex-col gap-2">
                                                <p class="text-sm text-navy">Delete this standard from the master list?</p>
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" wire:click="removeStandard" class="ui-button-danger" wire:loading.attr="disabled" wire:target="removeStandard">Delete</button>
                                                    <button type="button" wire:click="cancelForm" class="ui-button-secondary">Cancel</button>
                                                </div>
                                            </div>
                                        @else
                                            <div class="flex flex-wrap gap-2">
                                                <button type="button" wire:click="startEditing({{ $standard->standard_id }})" class="ui-button-outline">Edit</button>
                                                <button type="button" wire:click="askToRemove({{ $standard->standard_id }})" class="ui-button-danger">Delete</button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-4">
                    {{ $this->standards()->links() }}
                </div>
            @endif
        </section>
    </div>
</div>
