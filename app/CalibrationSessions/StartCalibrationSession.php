<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use App\Models\CalibrationEnvironmentParameterSnapshot;
use App\Models\CalibrationRequirementSnapshot;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\InstrumentTypeEnvironmentParameter;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

class StartCalibrationSession
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
        private CalibrationNumberAllocator $allocator,
    ) {}

    public function start(
        int $instrumentTypeId,
        string $calibrationMethod,
        ?Connection $connection = null,
    ): Calibration {
        $method = $this->normalizeMethod($calibrationMethod);
        $connection = $this->resolveConnection($connection);

        try {
            return $connection->transaction(
                fn (): Calibration => $this->startOnConnection($connection, $instrumentTypeId, $method),
            );
        } catch (QueryException $exception) {
            if ($this->isInProgressUniqueConflict($exception)) {
                throw StartCalibrationException::inProgressExists();
            }

            throw $exception;
        }
    }

    private function resolveConnection(?Connection $connection): Connection
    {
        try {
            return $this->sessionConnection->resolve($connection);
        } catch (CalibrationSessionConnectionException $exception) {
            if ($exception->isProduction()) {
                throw StartCalibrationException::productionDatabase();
            }

            throw $exception;
        }
    }

    private function startOnConnection(Connection $connection, int $instrumentTypeId, string $method): Calibration
    {
        $connectionName = $connection->getName();

        $instrumentType = InstrumentType::on($connectionName)
            ->whereKey($instrumentTypeId)
            ->lockForUpdate()
            ->first();

        if ($instrumentType === null) {
            throw StartCalibrationException::instrumentTypeMissing();
        }

        $instrument = Instrument::on($connectionName)
            ->with(['detailLocation.location'])
            ->whereKey($instrumentType->instrument_id)
            ->first();

        if ($instrument === null) {
            throw StartCalibrationException::parentInstrumentMissing();
        }

        $alreadyInProgress = Calibration::on($connectionName)
            ->where('instrument_type_id', $instrumentType->instrument_type_id)
            ->inProgress()
            ->exists();

        if ($alreadyInProgress) {
            throw StartCalibrationException::inProgressExists();
        }

        $identitySnapshot = $this->identitySnapshotAttributes($instrument, $instrumentType);

        $calibration = (new Calibration)->setConnection($connectionName);
        $calibration->forceFill([
            'instrument_id' => $instrument->instrument_id,
            'instrument_type_id' => $instrumentType->instrument_type_id,
            'calibration_provider_id' => $this->providerIdForMethod($connection, $method),
            'calibration_number' => $this->allocator->allocate($connection, now()),
            'calibration_date' => null,
            'calibration_due' => null,
            'calibration_method' => $method,
            'session_status' => Calibration::STATUS_IN_PROGRESS,
            'completed_at' => null,
            'cancelled_at' => null,
            'cancelled_reason' => null,
            'cancelled_by_user_id' => null,
            ...$identitySnapshot,
        ]);
        $calibration->save();

        $this->freezeRequirementSnapshots($connectionName, $calibration, $instrumentType);
        $this->freezeEnvironmentParameterSnapshots($connectionName, $calibration, $instrumentType);

        return $calibration->refresh();
    }

    private function normalizeMethod(string $calibrationMethod): string
    {
        $method = strtoupper(trim($calibrationMethod));

        if (! in_array($method, [Calibration::METHOD_INTERNAL, Calibration::METHOD_EXTERNAL], true)) {
            throw StartCalibrationException::invalidMethod();
        }

        return $method;
    }

    private function providerIdForMethod(Connection $connection, string $method): ?int
    {
        if ($method === Calibration::METHOD_EXTERNAL) {
            return null;
        }

        $providerId = $connection->table('calibration_provider')
            ->where('provider_code', 'INTERNAL_TEAM')
            ->value('calibration_provider_id');

        if ($providerId === null) {
            throw StartCalibrationException::internalTeamMissing();
        }

        return (int) $providerId;
    }

    /**
     * @return array{
     *     snapshot_instrument_name: string,
     *     snapshot_instrument_type_name: string,
     *     snapshot_brand: ?string,
     *     snapshot_model: ?string,
     *     snapshot_serial_number: ?string,
     *     snapshot_asset_number: ?string,
     *     snapshot_max_capacity: mixed,
     *     snapshot_max_capacity_unit: ?string,
     *     snapshot_resolution: mixed,
     *     snapshot_resolution_unit: ?string,
     *     snapshot_location_id: ?int,
     *     snapshot_detail_location_id: ?int,
     *     snapshot_location_code: ?string,
     *     snapshot_location_name: ?string,
     *     snapshot_detail_location_code: ?string,
     *     snapshot_detail_location_name: ?string
     * }
     */
    private function identitySnapshotAttributes(Instrument $instrument, InstrumentType $instrumentType): array
    {
        $detailLocation = $instrument->detailLocation;
        $location = $detailLocation?->location;

        if ($instrument->detail_location_id !== null && ($detailLocation === null || $location === null)) {
            throw StartCalibrationException::locationMissing();
        }

        return [
            'snapshot_instrument_name' => $instrument->instrument_name,
            'snapshot_instrument_type_name' => $instrumentType->type_name,
            'snapshot_brand' => $instrumentType->brand,
            'snapshot_model' => $instrumentType->model,
            'snapshot_serial_number' => $instrument->serial_number,
            'snapshot_asset_number' => $instrumentType->asset_number,
            'snapshot_max_capacity' => $instrumentType->max_capacity,
            'snapshot_max_capacity_unit' => $instrumentType->max_capacity_unit,
            'snapshot_resolution' => $instrumentType->resolution,
            'snapshot_resolution_unit' => $instrumentType->resolution_unit,
            'snapshot_location_id' => $location === null ? null : (int) $location->location_id,
            'snapshot_detail_location_id' => $detailLocation === null ? null : (int) $detailLocation->detail_location_id,
            'snapshot_location_code' => $location?->location_code,
            'snapshot_location_name' => $location?->location_name,
            'snapshot_detail_location_code' => $detailLocation?->detail_location_code,
            'snapshot_detail_location_name' => $detailLocation?->detail_location_name,
        ];
    }

    private function freezeRequirementSnapshots(
        string $connectionName,
        Calibration $calibration,
        InstrumentType $instrumentType,
    ): void {
        $configured = InstrumentTypeRequirement::on($connectionName)
            ->where('instrument_type_id', $instrumentType->instrument_type_id)
            ->get()
            ->keyBy('requirement_category');

        foreach (InstrumentTypeRequirement::CATEGORIES as $category) {
            $mode = $configured->get($category)?->requirement_mode
                ?? InstrumentTypeRequirement::MODE_NOT_CONFIGURED;

            $snapshot = (new CalibrationRequirementSnapshot)->setConnection($connectionName);
            $snapshot->forceFill([
                'calibration_id' => $calibration->calibration_id,
                'requirement_category' => $category,
                'frozen_mode' => $mode,
                'resolved_mode' => null,
                'resolved_at' => null,
                'resolved_reason' => null,
                'configuration_confirmed_at' => null,
                'configuration_confirmed_reason' => null,
            ]);
            $snapshot->save();
        }
    }

    private function freezeEnvironmentParameterSnapshots(
        string $connectionName,
        Calibration $calibration,
        InstrumentType $instrumentType,
    ): void {
        $configurations = InstrumentTypeEnvironmentParameter::on($connectionName)
            ->with('parameter')
            ->where('instrument_type_id', $instrumentType->instrument_type_id)
            ->orderBy('display_order')
            ->orderBy('parameter_id')
            ->get();

        foreach ($configurations as $configuration) {
            $parameter = $configuration->parameter;

            if ($parameter === null) {
                throw StartCalibrationException::environmentParameterMissing();
            }

            $snapshot = (new CalibrationEnvironmentParameterSnapshot)->setConnection($connectionName);
            $snapshot->forceFill([
                'calibration_id' => $calibration->calibration_id,
                'parameter_id' => $parameter->parameter_id,
                'parameter_code' => $parameter->parameter_code,
                'parameter_name' => $parameter->parameter_name,
                'unit' => $parameter->unit,
                'data_type' => $parameter->data_type,
                'requirement_mode' => $configuration->requirement_mode,
                'display_order' => $configuration->display_order,
                'included_at' => now(),
                'inclusion_confirmed' => true,
                'inclusion_reason' => null,
            ]);
            $snapshot->save();
        }
    }

    private function isInProgressUniqueConflict(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'uq_calibration_in_progress_per_type')
            || str_contains($message, 'UNIQUE constraint failed: calibration.instrument_type_id');
    }
}
