<?php

use App\Authorization\AuthorizesMasterDataWrites;
use App\CalibrationSessions\CalibrationSessionHttpRead;
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
    use AuthorizesMasterDataWrites;
    use WithPagination;

    public string $search = '';

    public bool $adding = false;

    public ?int $editingStandardId = null;

    public ?int $removingStandardId = null;

    public string $instrumentName = '';

    public string $typeSpecification = '';

    public string $serialNumber = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function startAdding(): void
    {
        $this->authorizeMasterDataManage();

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

    public function prepareStandardSave(): void
    {
        $this->authorizeMasterDataManage();

        $this->validateStandardForm();

        $standardCode = $this->editingStandardId === null
            ? null
            : StandardMaster::on($this->readConnectionName())
                ->where('standard_id', $this->editingStandardId)
                ->value('standard_code');

        $this->dispatch(
            'instruments-desk-confirm-standard-master-save',
            componentId: $this->getId(),
            mode: $this->editingStandardId === null ? 'create' : 'update',
            standardId: $standardCode ?? $this->editingStandardId,
            instrumentName: $this->instrumentName,
        );
    }

    /**
     * @return array{created?: bool, updated?: bool, validation?: bool, message?: string}
     */
    public function saveStandard(): array
    {
        $this->authorizeMasterDataManage();

        try {
            $this->validateStandardForm();
        } catch (\Illuminate\Validation\ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return ['validation' => true];
        }

        $writer = app(StandardMasterWriter::class);

        try {
            if ($this->editingStandardId !== null) {
                $writer->update($this->editingStandardId, $this->instrumentName, $this->typeSpecification, $this->serialNumber);
                $result = ['updated' => true];
            } else {
                $writer->create($this->instrumentName, $this->typeSpecification, $this->serialNumber);
                $result = ['created' => true];
            }
        } catch (StandardMasterException $exception) {
            $this->addError($exception->field === 'standard' ? 'instrumentName' : $exception->field, $exception->getMessage());

            return ['validation' => true, 'message' => $exception->getMessage()];
        }

        $this->cancelForm();
        $this->resetPage();

        return $result;
    }

    public function startEditing(int $standardId): void
    {
        $this->authorizeMasterDataManage();

        $standard = StandardMaster::on($this->readConnectionName())->find($standardId);

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
        $this->authorizeMasterDataManage();

        $this->removingStandardId = $standardId;
        $this->adding = false;
        $this->editingStandardId = null;
        $this->resetValidation();
    }

    public function prepareStandardDelete(int $standardId): void
    {
        $this->authorizeMasterDataManage();

        $standard = StandardMaster::on($this->readConnectionName())->find($standardId);

        if ($standard === null) {
            $this->dispatch('instruments-desk-notification', type: 'error', message: 'This standard could not be found.');

            return;
        }

        $this->removingStandardId = $standardId;
        $this->adding = false;
        $this->editingStandardId = null;
        $this->dispatch(
            'instruments-desk-confirm-standard-master-delete',
            componentId: $this->getId(),
            standardId: $standardId,
            standardCode: $standard->standard_code,
            instrumentName: $standard->instrument_name,
            serialNumber: $standard->serial_number,
        );
    }

    /**
     * @return array{deleted?: bool, message?: string}
     */
    public function removeStandard(): array
    {
        $this->authorizeMasterDataManage();

        if ($this->removingStandardId === null) {
            return ['deleted' => false, 'message' => 'This standard could not be found.'];
        }

        try {
            app(StandardMasterWriter::class)->delete($this->removingStandardId);
        } catch (StandardMasterException $exception) {
            $message = $exception->getMessage();
            $this->removingStandardId = null;

            return ['deleted' => false, 'message' => $message];
        }

        $this->removingStandardId = null;
        $this->resetPage();

        return ['deleted' => true];
    }

    /**
     * @return LengthAwarePaginator<int, StandardMaster>
     */
    public function standards(): LengthAwarePaginator
    {
        return StandardMaster::on($this->readConnectionName())
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

    private function readConnectionName(): string
    {
        return app(CalibrationSessionHttpRead::class)->connectionName();
    }

    private function validateStandardForm(): void
    {
        $this->validate([
            'instrumentName' => ['required', 'string', 'max:'.StandardMasterWriter::NAME_MAX],
            'typeSpecification' => ['nullable', 'string', 'max:'.StandardMasterWriter::TYPE_MAX],
            'serialNumber' => ['nullable', 'string', 'max:'.StandardMasterWriter::SERIAL_MAX],
        ], [
            'instrumentName.required' => 'Instrument Name is required.',
        ]);
    }
};
?>

<div
    class="px-4 py-8 sm:px-6 lg:px-10"
    data-component-id="{{ $this->getId() }}"
    x-data="{
        adding: $wire.entangle('adding'),
        editingStandardId: $wire.entangle('editingStandardId'),
        get standardModalOpen() { return this.adding || this.editingStandardId !== null; },
        requestCloseStandardModal() {
            window.instrumentsDeskNotifications.standardMasterDiscardConfirmation({ componentId: $root.dataset.componentId });
        },
    }"
    x-on:keydown.escape.window="if (standardModalOpen) requestCloseStandardModal()"
    x-init="$watch('standardModalOpen', (open) => $nextTick(() => open ? $refs.standardInstrumentName?.focus() : $refs.addStandardButton?.focus()))"
>
    <div class="mx-auto flex max-w-6xl flex-col gap-8">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight text-navy">Standard(s) Used</h1>
            <p class="mt-1 text-sm text-muted">Register master standards independently of the Instruments list.</p>
        </header>

        <section class="overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-[0_16px_32px_-22px_rgb(22_49_114/0.45)]" aria-labelledby="standard-master-heading">
            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 id="standard-master-heading" class="text-base font-semibold tracking-tight text-navy">Standard Master</h2>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <label for="standard-master-search" class="sr-only">Search by name, type, or serial number</label>
                    <input id="standard-master-search" type="search" wire:model.live.debounce.300ms="search" maxlength="100" class="ui-input" placeholder="Name, type / specification, or serial">
                    @can('masterdata.manage')
                        <button x-ref="addStandardButton" type="button" wire:click="startAdding" class="ui-button-outline">Add New Standard</button>
                    @endcan
                </div>
            </div>

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
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" wire:click="startEditing({{ $standard->standard_id }})" class="ui-button-outline">Edit</button>
                                            <button type="button" wire:click="prepareStandardDelete({{ $standard->standard_id }})" class="ui-button-danger" wire:loading.attr="disabled" wire:target="prepareStandardDelete">Delete</button>
                                        </div>
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

    <div
        x-cloak
        x-show="standardModalOpen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-180"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-navy/40 px-4 py-8 sm:items-center"
        role="presentation"
        x-on:click.self="requestCloseStandardModal()"
    >
        <div
            x-show="standardModalOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="scale-95 opacity-0"
            x-transition:enter-end="scale-100 opacity-100"
            x-transition:leave="transition ease-in duration-180"
            x-transition:leave-start="scale-100 opacity-100"
            x-transition:leave-end="scale-95 opacity-0"
            class="w-full max-w-2xl overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-2xl motion-reduce:transition-none"
            role="dialog"
            aria-modal="true"
            aria-labelledby="standard-master-modal-heading"
            data-standard-master-modal
            x-on:click.stop
        >
            <div class="flex items-start justify-between bg-navy px-5 py-4">
                <div>
                    <h2 id="standard-master-modal-heading" class="text-base font-semibold tracking-tight text-white" x-text="editingStandardId !== null ? 'Edit Standard' : 'Add New Standard'"></h2>
                    <p class="mt-1 text-sm text-white/80" x-text="editingStandardId !== null ? 'Update the selected standard information.' : 'Create a new standard in the Standard Master.'"></p>
                </div>
                <button type="button" class="text-xl leading-none text-white/80 hover:text-white" aria-label="Close" x-on:click="requestCloseStandardModal()">&times;</button>
            </div>

            <form wire:submit="prepareStandardSave" class="max-h-[calc(100vh-9rem)] overflow-y-auto px-5 py-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="master-instrument-name" class="ui-label">Instrument Name <span class="text-red-700">*</span></label>
                        <input x-ref="standardInstrumentName" id="master-instrument-name" data-standard-form-field data-initial-value="{{ $instrumentName }}" type="text" wire:model="instrumentName" maxlength="150" required class="ui-input">
                        @error('instrumentName') <p class="ui-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="master-type-specification" class="ui-label">Type / Specification <span class="font-normal text-muted">(optional)</span></label>
                        <input id="master-type-specification" data-standard-form-field data-initial-value="{{ $typeSpecification }}" type="text" wire:model="typeSpecification" maxlength="150" class="ui-input">
                        @error('typeSpecification') <p class="ui-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="master-serial-number" class="ui-label">Serial Number <span class="font-normal text-muted">(optional)</span></label>
                        <input id="master-serial-number" data-standard-form-field data-initial-value="{{ $serialNumber }}" type="text" wire:model="serialNumber" maxlength="150" class="ui-input">
                        @error('serialNumber') <p class="ui-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-brand/20 pt-4">
                    <button type="submit" class="ui-button-primary" wire:loading.attr="disabled" wire:target="prepareStandardSave" x-text="editingStandardId !== null ? 'Save Changes' : 'Save Standard'"></button>
                    <button type="button" class="ui-button-secondary" x-on:click="requestCloseStandardModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
