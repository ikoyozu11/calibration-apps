<?php

namespace App\Livewire\Concerns;

use App\Authorization\AuthorizesMasterDataWrites;
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
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;

/**
 * @property Instrument $instrument
 * @property InstrumentType $instrumentType
 */
trait ManagesStandardAssignments
{
    use AuthorizesMasterDataWrites;
    use WithPagination;

    public string $search = '';

    public string $newInstrumentName = '';

    public string $newTypeSpecification = '';

    public string $newSerialNumber = '';

    public bool $addingStandard = false;

    public ?int $selectedStandardId = null;

    public string $status = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function selectCandidate(int $standardId): void
    {
        $this->authorizeMasterDataManage();

        $standard = StandardMaster::on($this->assignmentConnectionName())->find($standardId);

        if ($standard === null) {
            $this->addError('standard', 'The selected standard could not be found.');

            return;
        }

        $this->selectedStandardId = $standardId;
        $this->addingStandard = false;
        $this->resetValidation();
        $this->dispatch(
            'instruments-desk-confirm-standard-assignment',
            componentId: $this->getId(),
            standardId: $standard->standard_id,
            standardCode: $standard->standard_code,
            instrumentName: $standard->instrument_name,
            typeSpecification: $standard->type_specification,
            serialNumber: $standard->serial_number,
        );
    }

    public function clearSelection(): void
    {
        $this->selectedStandardId = null;
        $this->resetValidation();
    }

    public function startAddingStandard(): void
    {
        $this->authorizeMasterDataManage();

        $this->addingStandard = true;
        $this->selectedStandardId = null;
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

    public function prepareNewStandardCreation(): void
    {
        $this->authorizeMasterDataManage();

        $this->validateNewStandard();

        $this->dispatch(
            'instruments-desk-confirm-new-standard-creation',
            componentId: $this->getId(),
            instrumentName: $this->newInstrumentName,
        );
    }

    /**
     * @return array{created: bool, validation?: bool}
     */
    public function createStandard(): array
    {
        $this->authorizeMasterDataManage();

        try {
            $this->validateNewStandard();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return ['created' => false, 'validation' => true];
        }

        $writer = app(StandardMasterWriter::class);

        try {
            $standard = $writer->create(
                $this->newInstrumentName,
                $this->newTypeSpecification,
                $this->newSerialNumber,
            );
        } catch (StandardMasterException $exception) {
            $this->addError($exception->field === 'standard' ? 'newInstrumentName' : $exception->field, $exception->getMessage());

            return ['created' => false, 'validation' => true];
        }

        $this->status = '';
        $this->addingStandard = false;
        $this->selectedStandardId = $standard->standard_id;
        $this->newInstrumentName = '';
        $this->newTypeSpecification = '';
        $this->newSerialNumber = '';
        $this->resetPage();
        $this->reloadAssignments();

        return ['created' => true];
    }

    private function validateNewStandard(): void
    {
        $this->validate([
            'newInstrumentName' => ['required', 'string', 'max:'.StandardMasterWriter::NAME_MAX],
            'newTypeSpecification' => ['nullable', 'string', 'max:'.StandardMasterWriter::TYPE_MAX],
            'newSerialNumber' => ['nullable', 'string', 'max:'.StandardMasterWriter::SERIAL_MAX],
        ], [
            'newInstrumentName.required' => 'Instrument Name is required.',
        ]);
    }

    /**
     * @return array{assigned: bool, validation?: bool, message?: string}
     */
    public function assignStandard(): array
    {
        $this->authorizeMasterDataManage();

        try {
            $this->validate([
                'selectedStandardId' => ['required', 'integer'],
            ], [
                'selectedStandardId.required' => 'Select a standard to assign.',
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return ['assigned' => false, 'validation' => true, 'message' => 'Select a standard to assign.'];
        }

        $writer = app(InstrumentTypeStandardAssignmentWriter::class);
        $standard = StandardMaster::on($this->assignmentConnectionName())->find($this->selectedStandardId);

        if ($standard === null) {
            $this->addError('selectedStandardId', 'The selected standard could not be found.');

            return ['assigned' => false, 'validation' => true, 'message' => 'The selected standard could not be found.'];
        }

        try {
            $writer->assign($this->instrumentType, $standard);
        } catch (InstrumentTypeStandardAssignmentException $exception) {
            $this->addError($exception->field, $exception->getMessage());

            return ['assigned' => false, 'validation' => true, 'message' => $exception->getMessage()];
        }

        $this->status = '';
        $this->selectedStandardId = null;
        $this->resetPage();
        $this->reloadAssignments();

        return ['assigned' => true];
    }

    /**
     * @return array{removed: bool, message?: string}
     */
    public function removeAssignment(int $assignmentId): array
    {
        $this->authorizeMasterDataManage();

        if ($this->assignmentOnType($assignmentId) === null) {
            return [
                'removed' => false,
                'message' => 'This standard assignment could not be found.',
            ];
        }

        $writer = app(InstrumentTypeStandardAssignmentWriter::class);

        try {
            $writer->remove($this->instrumentType, $assignmentId);
        } catch (InstrumentTypeStandardAssignmentException $exception) {
            $this->addError($exception->field, $exception->getMessage());

            return [
                'removed' => false,
                'message' => $exception->getMessage(),
            ];
        }

        $this->status = '';
        $this->reloadAssignments();

        return ['removed' => true];
    }

    public function moveAssignment(int $assignmentId, int $direction): void
    {
        $this->authorizeMasterDataManage();

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

    /**
     * @return LengthAwarePaginator<int, StandardMaster>
     */
    public function candidates(): LengthAwarePaginator
    {
        $assignedIds = $this->instrumentType->standardAssignments
            ->pluck('standard_id')
            ->filter()
            ->all();

        return StandardMaster::on($this->assignmentConnectionName())
            ->when($assignedIds !== [], fn ($query) => $query->whereNotIn('standard_master.standard_id', $assignedIds))
            ->whereStandardMasterMatches($this->search)
            ->orderBy('standard_master.standard_code')
            ->paginate(10);
    }

    protected function reloadAssignments(): void
    {
        if (! Schema::connection($this->assignmentConnectionName())->hasTable('instrument_type_standard_assignment')) {
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

    private function assignmentConnectionName(): string
    {
        $name = $this->instrumentType->getConnectionName();

        if (! is_string($name) || $name === '') {
            $name = $this->instrument->getConnectionName();
        }

        if (! is_string($name) || $name === '') {
            abort(503, 'Reading this calibration session is not available in this environment.');
        }

        return $name;
    }
}
