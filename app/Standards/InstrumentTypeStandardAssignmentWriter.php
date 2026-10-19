<?php

namespace App\Standards;

use App\Models\InstrumentType;
use App\Models\InstrumentTypeStandardAssignment;
use App\Models\StandardMaster;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class InstrumentTypeStandardAssignmentWriter
{
    public const RANGE_MAX = 150;

    public const UNIT_MAX = 50;

    public const DISPLAY_NAME_MAX = 255;

    public function assign(
        InstrumentType $instrumentType,
        StandardMaster $standard,
        ?string $defaultRange = null,
        ?string $defaultUnit = null,
    ): InstrumentTypeStandardAssignment {
        try {
            return DB::transaction(function () use ($instrumentType, $standard, $defaultRange, $defaultUnit): InstrumentTypeStandardAssignment {
                $this->lockType($instrumentType);

                if ($this->alreadyAssigned($instrumentType, $standard)) {
                    throw new InstrumentTypeStandardAssignmentException('This standard is already assigned.');
                }

                $assignment = new InstrumentTypeStandardAssignment;
                $assignment->instrument_type_id = $instrumentType->instrument_type_id;
                $assignment->standard_id = $standard->standard_id;
                $assignment->standard_instrument_id = null;
                $assignment->assignment_order = $this->nextOrder($instrumentType);
                $assignment->default_range = $this->nullableText($defaultRange, self::RANGE_MAX, 'defaultRange');
                $assignment->default_unit = $this->nullableText($defaultUnit, self::UNIT_MAX, 'defaultUnit');
                $assignment->save();

                return $assignment;
            });
        } catch (InstrumentTypeStandardAssignmentException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            throw new InstrumentTypeStandardAssignmentException(
                InstrumentTypeStandardAssignmentConstraintMessage::fromDatabaseMessage($exception->getMessage()),
            );
        }
    }

    public function updateDefaults(
        InstrumentType $instrumentType,
        int $assignmentId,
        ?string $defaultRange,
        ?string $defaultUnit,
    ): InstrumentTypeStandardAssignment {
        try {
            return DB::transaction(function () use ($instrumentType, $assignmentId, $defaultRange, $defaultUnit): InstrumentTypeStandardAssignment {
                $assignment = $this->lockedAssignment($instrumentType, $assignmentId);
                $assignment->default_range = $this->nullableText($defaultRange, self::RANGE_MAX, 'defaultRange');
                $assignment->default_unit = $this->nullableText($defaultUnit, self::UNIT_MAX, 'defaultUnit');
                $assignment->save();

                return $assignment;
            });
        } catch (InstrumentTypeStandardAssignmentException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            throw new InstrumentTypeStandardAssignmentException(
                InstrumentTypeStandardAssignmentConstraintMessage::fromDatabaseMessage($exception->getMessage()),
            );
        }
    }

    public function updateAssignment(
        InstrumentType $instrumentType,
        int $assignmentId,
        ?string $standardDisplayName,
        ?string $defaultRange,
        ?string $defaultUnit,
    ): InstrumentTypeStandardAssignment {
        try {
            return DB::transaction(function () use ($instrumentType, $assignmentId, $standardDisplayName, $defaultRange, $defaultUnit): InstrumentTypeStandardAssignment {
                $assignment = $this->lockedAssignment($instrumentType, $assignmentId);
                $assignment->standard_display_name = $this->nullableText($standardDisplayName, self::DISPLAY_NAME_MAX, 'standardDisplayName');
                $assignment->default_range = $this->nullableText($defaultRange, self::RANGE_MAX, 'defaultRange');
                $assignment->default_unit = $this->nullableText($defaultUnit, self::UNIT_MAX, 'defaultUnit');
                $assignment->save();

                return $assignment;
            });
        } catch (InstrumentTypeStandardAssignmentException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            throw new InstrumentTypeStandardAssignmentException(
                InstrumentTypeStandardAssignmentConstraintMessage::fromDatabaseMessage($exception->getMessage()),
            );
        }
    }

    public function remove(InstrumentType $instrumentType, int $assignmentId): void
    {
        try {
            DB::transaction(function () use ($instrumentType, $assignmentId): void {
                $assignment = $this->lockedAssignment($instrumentType, $assignmentId);
                $assignment->delete();
                $this->writeOrder($instrumentType, $this->orderedIds($instrumentType));
            });
        } catch (InstrumentTypeStandardAssignmentException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            throw new InstrumentTypeStandardAssignmentException(
                InstrumentTypeStandardAssignmentConstraintMessage::fromDatabaseMessage($exception->getMessage()),
            );
        }
    }

    public function move(InstrumentType $instrumentType, int $assignmentId, int $direction): void
    {
        if ($direction !== -1 && $direction !== 1) {
            throw new InstrumentTypeStandardAssignmentException('The standard order could not be updated. Refresh the page and try again.');
        }

        try {
            DB::transaction(function () use ($instrumentType, $assignmentId, $direction): void {
                $this->lockType($instrumentType);
                $ids = $this->orderedIds($instrumentType);
                $index = array_search($assignmentId, $ids, true);

                if ($index === false) {
                    throw new InstrumentTypeStandardAssignmentException('This standard assignment could not be found.');
                }

                $swapWith = $index + $direction;

                if ($swapWith < 0 || $swapWith >= count($ids)) {
                    return;
                }

                $ids[$index] = $ids[$swapWith];
                $ids[$swapWith] = $assignmentId;
                $this->writeOrder($instrumentType, array_values($ids));
            });
        } catch (InstrumentTypeStandardAssignmentException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            throw new InstrumentTypeStandardAssignmentException(
                InstrumentTypeStandardAssignmentConstraintMessage::fromDatabaseMessage($exception->getMessage()),
            );
        }
    }

    private function alreadyAssigned(InstrumentType $instrumentType, StandardMaster $standard): bool
    {
        return InstrumentTypeStandardAssignment::query()
            ->where('instrument_type_id', $instrumentType->instrument_type_id)
            ->where('standard_id', $standard->standard_id)
            ->exists();
    }

    private function nextOrder(InstrumentType $instrumentType): int
    {
        $max = InstrumentTypeStandardAssignment::query()
            ->where('instrument_type_id', $instrumentType->instrument_type_id)
            ->max('assignment_order');

        return ((int) $max) + 1;
    }

    /**
     * @return list<int>
     */
    private function orderedIds(InstrumentType $instrumentType): array
    {
        return InstrumentTypeStandardAssignment::query()
            ->where('instrument_type_id', $instrumentType->instrument_type_id)
            ->orderBy('assignment_order')
            ->orderBy('instrument_type_standard_assignment_id')
            ->pluck('instrument_type_standard_assignment_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $orderedIds
     */
    private function writeOrder(InstrumentType $instrumentType, array $orderedIds): void
    {
        $assignments = InstrumentTypeStandardAssignment::query()
            ->where('instrument_type_id', $instrumentType->instrument_type_id)
            ->lockForUpdate()
            ->get()
            ->keyBy('instrument_type_standard_assignment_id');

        if (count($orderedIds) !== $assignments->count()) {
            throw new InstrumentTypeStandardAssignmentException('The standard order could not be updated. Refresh the page and try again.');
        }

        foreach ($orderedIds as $id) {
            if (! $assignments->has($id)) {
                throw new InstrumentTypeStandardAssignmentException('This standard assignment could not be found.');
            }
        }

        $offset = 10000;

        foreach ($assignments->values() as $index => $assignment) {
            $assignment->assignment_order = $offset + $index + 1;
            $assignment->save();
        }

        foreach ($orderedIds as $index => $id) {
            $assignment = $assignments->get($id);

            if ($assignment === null) {
                throw new InstrumentTypeStandardAssignmentException('This standard assignment could not be found.');
            }

            $assignment->assignment_order = $index + 1;
            $assignment->save();
        }
    }

    private function lockType(InstrumentType $instrumentType): void
    {
        $locked = InstrumentType::query()
            ->where('instrument_type_id', $instrumentType->instrument_type_id)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw new InstrumentTypeStandardAssignmentException('This instrument type could not be found.');
        }

        InstrumentTypeStandardAssignment::query()
            ->where('instrument_type_id', $instrumentType->instrument_type_id)
            ->lockForUpdate()
            ->get();
    }

    private function lockedAssignment(InstrumentType $instrumentType, int $assignmentId): InstrumentTypeStandardAssignment
    {
        $assignment = InstrumentTypeStandardAssignment::query()
            ->where('instrument_type_id', $instrumentType->instrument_type_id)
            ->where('instrument_type_standard_assignment_id', $assignmentId)
            ->lockForUpdate()
            ->first();

        if ($assignment === null) {
            throw new InstrumentTypeStandardAssignmentException('This standard assignment could not be found.');
        }

        return $assignment;
    }

    private function nullableText(?string $value, int $max, string $field): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $max) {
            throw new InstrumentTypeStandardAssignmentException(
                'The '.$this->fieldLabel($field).' may not be greater than '.$max.' characters.',
                $field,
            );
        }

        return $text;
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'standardDisplayName' => 'standard display name',
            'defaultRange' => 'default range',
            'defaultUnit' => 'default unit',
            default => $field,
        };
    }
}
