<?php

namespace App\CalibrationSessions;

use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use stdClass;

/**
 * Assigns existing environment parameters to one Instrument Type.
 *
 * Future HTTP callers must authenticate and authorize before calling this
 * service. Assignment rows have no actor column. Laravel users.id is not an
 * app_user.app_user_id.
 *
 * Writes stay on the caller-supplied Connection. This service does not call
 * resolveNamed() and does not open pgsql_staging. It does not create catalog
 * rows, edit parameter definitions, or update calibration session snapshots.
 *
 * Direct SQL and writers that skip this service can still remove the last
 * required parameter while the environment category is required. PostgreSQL
 * does not enforce that reverse rule. These methods do.
 *
 * Reorder uses two updates per row because
 * uq_instrument_type_environment_parameter_order is not deferrable. Each
 * temporary display_order is unused, at least 1, and outside the final
 * positions 1..n, so neither PostgreSQL nor SQLite hits a unique collision
 * mid-update. Temporary values stay within a 32-bit signed integer. This
 * checkpoint does not execute that path on PostgreSQL.
 */
class AssignInstrumentTypeEnvironmentParameter
{
    private const POSTGRES_INTEGER_MAX = 2147483647;

    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    public function assign(
        int $instrumentTypeId,
        int $parameterId,
        string $mode,
        int $displayOrder,
        Connection $connection,
    ): void {
        $connection = $this->sessionConnection->resolve($connection);
        $mode = $this->assignmentMode($mode);
        $this->assertDisplayOrder($displayOrder);

        $connection->transaction(function () use ($connection, $instrumentTypeId, $parameterId, $mode, $displayOrder): void {
            $this->lockInstrumentType($connection, $instrumentTypeId);
            $this->assertParameterExists($connection, $parameterId);

            $existing = $this->assignment($connection, $instrumentTypeId, $parameterId);

            if ($existing !== null) {
                throw AssignInstrumentTypeEnvironmentParameterException::duplicateAssignment();
            }

            $orderTaken = $connection->table('instrument_type_environment_parameter')
                ->where('instrument_type_id', $instrumentTypeId)
                ->where('display_order', $displayOrder)
                ->exists();

            if ($orderTaken) {
                throw AssignInstrumentTypeEnvironmentParameterException::displayOrderTaken();
            }

            $requiredCount = $this->requiredCount($connection, $instrumentTypeId);

            if ($mode === InstrumentTypeRequirement::MODE_REQUIRED) {
                $requiredCount++;
            }

            $this->assertRequiredInvariant($connection, $instrumentTypeId, $requiredCount);

            $connection->table('instrument_type_environment_parameter')->insert([
                'instrument_type_id' => $instrumentTypeId,
                'parameter_id' => $parameterId,
                'requirement_mode' => $mode,
                'display_order' => $displayOrder,
            ]);
        });
    }

    public function changeMode(
        int $instrumentTypeId,
        int $parameterId,
        string $mode,
        Connection $connection,
    ): void {
        $connection = $this->sessionConnection->resolve($connection);
        $mode = $this->assignmentMode($mode);

        $connection->transaction(function () use ($connection, $instrumentTypeId, $parameterId, $mode): void {
            $this->lockInstrumentType($connection, $instrumentTypeId);

            $existing = $this->assignment($connection, $instrumentTypeId, $parameterId);

            if ($existing === null) {
                throw AssignInstrumentTypeEnvironmentParameterException::assignmentMissing();
            }

            $requiredCount = $this->requiredCount($connection, $instrumentTypeId);

            if ($existing->requirement_mode === InstrumentTypeRequirement::MODE_REQUIRED) {
                $requiredCount--;
            }

            if ($mode === InstrumentTypeRequirement::MODE_REQUIRED) {
                $requiredCount++;
            }

            $this->assertRequiredInvariant($connection, $instrumentTypeId, $requiredCount);

            $connection->table('instrument_type_environment_parameter')
                ->where('instrument_type_id', $instrumentTypeId)
                ->where('parameter_id', $parameterId)
                ->update([
                    'requirement_mode' => $mode,
                ]);
        });
    }

    public function remove(
        int $instrumentTypeId,
        int $parameterId,
        Connection $connection,
    ): void {
        $connection = $this->sessionConnection->resolve($connection);

        $connection->transaction(function () use ($connection, $instrumentTypeId, $parameterId): void {
            $this->lockInstrumentType($connection, $instrumentTypeId);

            $existing = $this->assignment($connection, $instrumentTypeId, $parameterId);

            if ($existing === null) {
                throw AssignInstrumentTypeEnvironmentParameterException::assignmentMissing();
            }

            $requiredCount = $this->requiredCount($connection, $instrumentTypeId);

            if ($existing->requirement_mode === InstrumentTypeRequirement::MODE_REQUIRED) {
                $requiredCount--;
            }

            $this->assertRequiredInvariant($connection, $instrumentTypeId, $requiredCount);

            $connection->table('instrument_type_environment_parameter')
                ->where('instrument_type_id', $instrumentTypeId)
                ->where('parameter_id', $parameterId)
                ->delete();
        });
    }

    /**
     * @param  list<int>  $parameterIds
     */
    public function reorder(
        int $instrumentTypeId,
        array $parameterIds,
        Connection $connection,
    ): void {
        $connection = $this->sessionConnection->resolve($connection);

        $connection->transaction(function () use ($connection, $instrumentTypeId, $parameterIds): void {
            $this->lockInstrumentType($connection, $instrumentTypeId);

            $assignments = $connection->table('instrument_type_environment_parameter')
                ->where('instrument_type_id', $instrumentTypeId)
                ->orderBy('parameter_id')
                ->get();

            $this->assertRequiredInvariant(
                $connection,
                $instrumentTypeId,
                $this->requiredCount($connection, $instrumentTypeId),
            );

            $orderedIds = $this->reorderIds($parameterIds, $assignments->pluck('parameter_id')->map(
                fn (mixed $parameterId): int => (int) $parameterId,
            )->all());

            if ($orderedIds === []) {
                return;
            }

            $temporaryOrders = $this->temporaryDisplayOrders(
                count($orderedIds),
                $assignments->pluck('display_order')->map(fn (mixed $order): int => (int) $order)->all(),
            );

            foreach ($orderedIds as $index => $parameterId) {
                $this->updateDisplayOrder($connection, $instrumentTypeId, $parameterId, $temporaryOrders[$index]);
            }

            foreach ($orderedIds as $index => $parameterId) {
                $this->updateDisplayOrder($connection, $instrumentTypeId, $parameterId, $index + 1);
            }
        });
    }

    private function lockInstrumentType(Connection $connection, int $instrumentTypeId): void
    {
        $instrumentType = $connection->table('instrument_type')
            ->where('instrument_type_id', $instrumentTypeId)
            ->lockForUpdate()
            ->first();

        if ($instrumentType === null) {
            throw AssignInstrumentTypeEnvironmentParameterException::instrumentTypeMissing();
        }
    }

    private function assignmentMode(string $mode): string
    {
        if (! in_array($mode, [
            InstrumentTypeRequirement::MODE_REQUIRED,
            InstrumentTypeRequirement::MODE_OPTIONAL,
        ], true)) {
            throw AssignInstrumentTypeEnvironmentParameterException::unknownMode();
        }

        return $mode;
    }

    private function assertDisplayOrder(int $displayOrder): void
    {
        if ($displayOrder < 1 || $displayOrder > self::POSTGRES_INTEGER_MAX) {
            throw AssignInstrumentTypeEnvironmentParameterException::invalidDisplayOrder();
        }
    }

    private function assertParameterExists(Connection $connection, int $parameterId): void
    {
        $exists = $connection->table('environment_parameter')
            ->where('parameter_id', $parameterId)
            ->exists();

        if (! $exists) {
            throw AssignInstrumentTypeEnvironmentParameterException::parameterMissing();
        }
    }

    private function assignment(Connection $connection, int $instrumentTypeId, int $parameterId): ?stdClass
    {
        $assignment = $connection->table('instrument_type_environment_parameter')
            ->where('instrument_type_id', $instrumentTypeId)
            ->where('parameter_id', $parameterId)
            ->first();

        return $assignment instanceof stdClass ? $assignment : null;
    }

    private function requiredCount(Connection $connection, int $instrumentTypeId): int
    {
        return $connection->table('instrument_type_environment_parameter')
            ->where('instrument_type_id', $instrumentTypeId)
            ->where('requirement_mode', InstrumentTypeRequirement::MODE_REQUIRED)
            ->count();
    }

    private function assertRequiredInvariant(Connection $connection, int $instrumentTypeId, int $requiredCountAfter): void
    {
        $categoryMode = $connection->table('instrument_type_requirement')
            ->where('instrument_type_id', $instrumentTypeId)
            ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
            ->value('requirement_mode');

        if ($categoryMode === InstrumentTypeRequirement::MODE_REQUIRED && $requiredCountAfter < 1) {
            throw AssignInstrumentTypeEnvironmentParameterException::lastRequiredParameter();
        }
    }

    /**
     * @param  list<mixed>  $parameterIds
     * @param  list<int>  $assignedIds
     * @return list<int>
     */
    private function reorderIds(array $parameterIds, array $assignedIds): array
    {
        $assigned = array_fill_keys($assignedIds, true);
        $seen = [];
        $orderedIds = [];

        foreach ($parameterIds as $parameterId) {
            if (! is_int($parameterId)) {
                throw AssignInstrumentTypeEnvironmentParameterException::reorderUnknown();
            }

            if (isset($seen[$parameterId])) {
                throw AssignInstrumentTypeEnvironmentParameterException::reorderDuplicate();
            }

            if (! isset($assigned[$parameterId])) {
                throw AssignInstrumentTypeEnvironmentParameterException::reorderUnknown();
            }

            $seen[$parameterId] = true;
            $orderedIds[] = $parameterId;
        }

        if (count($orderedIds) !== count($assignedIds)) {
            throw AssignInstrumentTypeEnvironmentParameterException::reorderIncomplete();
        }

        return $orderedIds;
    }

    /**
     * @param  list<int>  $currentOrders
     * @return list<int>
     */
    private function temporaryDisplayOrders(int $count, array $currentOrders): array
    {
        $used = [];

        foreach ($currentOrders as $currentOrder) {
            $used[$currentOrder] = true;
        }

        $temporaryOrders = [];
        $candidate = $count + 1;

        while (count($temporaryOrders) < $count) {
            if ($candidate > self::POSTGRES_INTEGER_MAX) {
                throw AssignInstrumentTypeEnvironmentParameterException::reorderOrderExhausted();
            }

            if (! isset($used[$candidate])) {
                $temporaryOrders[] = $candidate;
                $used[$candidate] = true;
            }

            $candidate++;
        }

        return $temporaryOrders;
    }

    private function updateDisplayOrder(
        Connection $connection,
        int $instrumentTypeId,
        int $parameterId,
        int $displayOrder,
    ): void {
        $connection->table('instrument_type_environment_parameter')
            ->where('instrument_type_id', $instrumentTypeId)
            ->where('parameter_id', $parameterId)
            ->update([
                'display_order' => $displayOrder,
            ]);
    }
}
