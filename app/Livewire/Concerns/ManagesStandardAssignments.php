<?php

namespace App\Livewire\Concerns;

use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\InstrumentTypeStandardAssignment;
use App\Models\StandardMaster;
use App\Standards\InstrumentTypeStandardAssignmentException;
use App\Standards\InstrumentTypeStandardAssignmentWriter;
use App\Standards\StandardMasterException;
use App\Standards\StandardMasterWriter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Livewire\WithPagination;

/**
 * @property Instrument $instrument
 * @property InstrumentType $instrumentType
 */
trait ManagesStandardAssignments
{
    use WithPagination;

    public string $search = '';

    public string $newInstrumentName = '';

    public string $newTypeSpecification = '';

    public string $newSerialNumber = '';

    public bool $addingStandard = false;

    public ?int $selectedStandardId = null;

    public ?int $removingAssignmentId = null;

    public string $status = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function selectCandidate(int $standardId): void
    {
        $this->selectedStandardId = $standardId;
        $this->removingAssignmentId = null;
        $this->addingStandard = false;
        $this->resetValidation();
    }

    public function clearSelection(): void
    {
        $this->selectedStandardId = null;
        $this->resetValidation();
    }

    public function startAddingStandard(): void
    {
        $this->addingStandard = true;
        $this->selectedStandardId = null;
        $this->removingAssignmentId = null;
        $this->newInstrumentName = '';
        $this->newTypeSpecification = '';
        $this->newSerialNumber = '';
        $this->resetValidation();
    }

    public function cancelAddingStandard(): void
    {
        $this->addingStandard = false;
        $this->newInstrumentName = '';
        $this->newTypeSpecification = '';
        $this->newSerialNumber = '';
        $this->resetValidation();
    }

    public function createStandard(): void
    {
        $this->validate([
            'newInstrumentName' => ['required', 'string', 'max:'.StandardMasterWriter::NAME_MAX],
            'newTypeSpecification' => ['nullable', 'string', 'max:'.StandardMasterWriter::TYPE_MAX],
            'newSerialNumber' => ['nullable', 'string', 'max:'.StandardMasterWriter::SERIAL_MAX],
        ], [
            'newInstrumentName.required' => 'Instrument Name is required.',
        ]);

        $writer = app(StandardMasterWriter::class);

        try {
            $standard = $writer->create(
                $this->newInstrumentName,
                $this->newTypeSpecification,
                $this->newSerialNumber,
            );
        } catch (StandardMasterException $exception) {
            $this->addError($exception->field === 'standard' ? 'newInstrumentName' : $exception->field, $exception->getMessage());

            return;
        }

        $this->status = 'Standard saved. Assign it to this instrument type when ready.';
        $this->addingStandard = false;
        $this->selectedStandardId = $standard->standard_id;
        $this->newInstrumentName = '';
        $this->newTypeSpecification = '';
        $this->newSerialNumber = '';
        $this->resetPage();
        $this->reloadAssignments();
    }

    public function assignStandard(): void
    {
        $this->validate([
            'selectedStandardId' => ['required', 'integer'],
        ], [
            'selectedStandardId.required' => 'Select a standard to assign.',
        ]);

        $writer = app(InstrumentTypeStandardAssignmentWriter::class);
        $standard = StandardMaster::query()->find($this->selectedStandardId);

        if ($standard === null) {
            $this->addError('selectedStandardId', 'The selected standard could not be found.');

            return;
        }

        try {
            $writer->assign($this->instrumentType, $standard);
        } catch (InstrumentTypeStandardAssignmentException $exception) {
            $this->addError($exception->field, $exception->getMessage());

            return;
        }

        $this->status = 'Standard assigned.';
        $this->selectedStandardId = null;
        $this->resetPage();
        $this->reloadAssignments();
    }

    public function askToRemove(int $assignmentId): void
    {
        if ($this->assignmentOnType($assignmentId) === null) {
            $this->addError('standard', 'This standard assignment could not be found.');

            return;
        }

        $this->removingAssignmentId = $assignmentId;
        $this->resetValidation();
    }

    public function cancelRemoving(): void
    {
        $this->removingAssignmentId = null;
    }

    public function removeAssignment(): void
    {
        if ($this->removingAssignmentId === null) {
            return;
        }

        $writer = app(InstrumentTypeStandardAssignmentWriter::class);

        try {
            $writer->remove($this->instrumentType, $this->removingAssignmentId);
        } catch (InstrumentTypeStandardAssignmentException $exception) {
            $this->addError($exception->field, $exception->getMessage());

            return;
        }

        $this->status = 'Standard assignment removed.';
        $this->removingAssignmentId = null;
        $this->reloadAssignments();
    }

    public function moveAssignment(int $assignmentId, int $direction): void
    {
        $writer = app(InstrumentTypeStandardAssignmentWriter::class);

        try {
            $writer->move($this->instrumentType, $assignmentId, $direction);
        } catch (InstrumentTypeStandardAssignmentException $exception) {
            $this->addError($exception->field, $exception->getMessage());

            return;
        }

        $this->status = 'Standard order updated.';
        $this->reloadAssignments();
    }

    /**
     * @return Collection<int, InstrumentTypeStandardAssignment>
     */
    public function assignedStandards(): Collection
    {
        return $this->instrumentType->standardAssignments;
    }

    public function selectedCandidate(): ?StandardMaster
    {
        if ($this->selectedStandardId === null) {
            return null;
        }

        return StandardMaster::query()->find($this->selectedStandardId);
    }

    /**
     * @return LengthAwarePaginator<int, StandardMaster>
     */
    public function candidates(): LengthAwarePaginator
    {
        $assignedIds = $this->instrumentType->standardAssignments
            ->pluck('standard_id')
            ->filter()
            ->all();

        return StandardMaster::query()
            ->when($assignedIds !== [], fn ($query) => $query->whereNotIn('standard_master.standard_id', $assignedIds))
            ->whereStandardMasterMatches($this->search)
            ->orderBy('standard_master.standard_code')
            ->paginate(10);
    }

    protected function reloadAssignments(): void
    {
        if (! Schema::hasTable('instrument_type_standard_assignment')) {
            return;
        }

        $this->instrumentType->load([
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

    private function assignmentOnType(int $assignmentId): ?InstrumentTypeStandardAssignment
    {
        return $this->instrumentType->standardAssignments->first(
            fn (InstrumentTypeStandardAssignment $assignment): bool => (int) $assignment->instrument_type_standard_assignment_id === $assignmentId,
        );
    }
}
