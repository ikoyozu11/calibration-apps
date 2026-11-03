<?php

namespace App\CalibrationSessions;

use App\Authorization\ApplicationCapability;
use App\Authorization\ResolveApplicationRole;
use App\Models\Calibration;
use App\Models\CalibrationEnvironmentParameterSnapshot;
use App\Models\CalibrationRequirementSnapshot;
use App\Models\EnvironmentParameter;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\InstrumentTypeEnvironmentParameter;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * Starts one in-progress calibration session and attributes immutable ownership.
 *
 * Ownership: ResolveDomainActor(laravelUserId) → app_user_id written once on
 * INSERT as started_by_app_user_id. Callers pass a trusted server-side
 * Laravel users.id (never browser-supplied app_user_id or role strings).
 *
 * Authorization: ResolveApplicationRole must allow session.start (Administrator
 * or Engineer) on the Laravel auth database before any domain write.
 *
 * Connection: explicit session Connection required. Roles are read from the
 * default Laravel auth connection; domain actor mapping uses this Connection.
 */
class StartCalibrationSession
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
        private CalibrationNumberAllocator $allocator,
        private ResolveDomainActor $actors,
        private ResolveApplicationRole $roles,
        private RecordCalibrationStandardUsage $standardUsages,
    ) {}

    public function start(
        int $instrumentTypeId,
        string $calibrationMethod,
        int $laravelUserId,
        Connection $connection,
    ): Calibration {
        $method = $this->normalizeMethod($calibrationMethod);
        $connection = $this->resolveConnection($connection);

        if ($laravelUserId < 1) {
            throw StartCalibrationException::invalidLaravelUserId();
        }

        if (! $this->roles->allows($laravelUserId, ApplicationCapability::SessionStart)) {
            throw StartCalibrationException::unauthorized();
        }

        try {
            return $connection->transaction(
                fn (): Calibration => $this->startOnConnection(
                    $connection,
                    $instrumentTypeId,
                    $method,
                    $laravelUserId,
                ),
            );
        } catch (QueryException $exception) {
            if ($this->isInProgressUniqueConflict($exception)) {
                throw StartCalibrationException::inProgressExists();
            }

            throw $exception;
        }
    }

    private function resolveConnection(Connection $connection): Connection
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

    private function startOnConnection(
        Connection $connection,
        int $instrumentTypeId,
        string $method,
        int $laravelUserId,
    ): Calibration {
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

        if ($method === Calibration::METHOD_INTERNAL) {
            $this->requiredRoomEnvironmentCatalog($connectionName);
        }

        $identitySnapshot = $this->identitySnapshotAttributes($instrument, $instrumentType);
        $providerId = $this->providerIdForMethod($connection, $method);

        $ownerAppUserId = $this->actors->resolve($laravelUserId, $connection);
        $calibrationNumber = $this->allocator->allocate($connection, now());

        $calibration = (new Calibration)->setConnection($connectionName);
        $calibration->forceFill([
            'instrument_id' => $instrument->instrument_id,
            'instrument_type_id' => $instrumentType->instrument_type_id,
            'calibration_provider_id' => $providerId,
            'calibration_number' => $calibrationNumber,
            'calibration_date' => null,
            'calibration_due' => null,
            'calibration_method' => $method,
            'session_status' => Calibration::STATUS_IN_PROGRESS,
            'completed_at' => null,
            'cancelled_at' => null,
            'cancelled_reason' => null,
            'cancelled_by_user_id' => null,
            'started_by_app_user_id' => $ownerAppUserId,
            ...$identitySnapshot,
        ]);
        $calibration->save();

        $this->freezeRequirementSnapshots($connectionName, $calibration, $instrumentType, $method);
        $this->freezeEnvironmentParameterSnapshots($connectionName, $calibration, $instrumentType, $method);
        $this->persistAssignedStandards($connection, $calibration, $method);

        return $calibration->refresh();
    }

    private function persistAssignedStandards(
        Connection $connection,
        Calibration $calibration,
        string $method,
    ): void {
        if ($method !== Calibration::METHOD_INTERNAL) {
            return;
        }

        $schema = $connection->getSchemaBuilder();

        foreach (['calibration_standard_usage', 'instrument_type_standard_assignment', 'standard_master'] as $table) {
            if (! $schema->hasTable($table)) {
                return;
            }
        }

        if (! $schema->hasColumn('instrument_type_standard_assignment', 'standard_id')) {
            return;
        }

        $standardIds = $connection->table('instrument_type_standard_assignment')
            ->where('instrument_type_id', $calibration->instrument_type_id)
            ->whereNotNull('standard_id')
            ->orderBy('assignment_order')
            ->orderBy('instrument_type_standard_assignment_id')
            ->pluck('standard_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($standardIds === []) {
            return;
        }

        $this->standardUsages->sync(
            (int) $calibration->calibration_id,
            $standardIds,
            $connection,
        );
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
     *     snapshot_location_id: int,
     *     snapshot_detail_location_id: int,
     *     snapshot_location_code: string,
     *     snapshot_location_name: string,
     *     snapshot_detail_location_code: string,
     *     snapshot_detail_location_name: string
     * }
     */
    private function identitySnapshotAttributes(Instrument $instrument, InstrumentType $instrumentType): array
    {
        $detailLocation = $instrument->detailLocation;
        $location = $detailLocation?->location;

        if ($instrument->detail_location_id === null) {
            throw StartCalibrationException::incompleteLocation();
        }

        if ($detailLocation === null || $location === null) {
            throw StartCalibrationException::locationMissing();
        }

        if ($location->location_id === null || $detailLocation->detail_location_id === null) {
            throw StartCalibrationException::incompleteLocation();
        }

        $locationCode = $location->location_code;
        $locationName = $location->location_name;
        $detailLocationCode = $detailLocation->detail_location_code;
        $detailLocationName = $detailLocation->detail_location_name;

        foreach ([$locationCode, $locationName, $detailLocationCode, $detailLocationName] as $text) {
            if (! is_string($text) || trim($text) === '') {
                throw StartCalibrationException::incompleteLocation();
            }
        }

        $instrumentName = $instrument->instrument_name;
        $typeName = $instrumentType->type_name;

        if (! is_string($instrumentName) || trim($instrumentName) === '') {
            throw StartCalibrationException::incompleteInstrumentName();
        }

        if (! is_string($typeName) || trim($typeName) === '') {
            throw StartCalibrationException::incompleteTypeName();
        }

        return [
            'snapshot_instrument_name' => $instrumentName,
            'snapshot_instrument_type_name' => $typeName,
            'snapshot_brand' => $instrumentType->brand,
            'snapshot_model' => $instrumentType->model,
            'snapshot_serial_number' => $instrument->serial_number,
            'snapshot_asset_number' => $instrumentType->asset_number,
            'snapshot_max_capacity' => $instrumentType->max_capacity,
            'snapshot_max_capacity_unit' => $instrumentType->max_capacity_unit,
            'snapshot_resolution' => $instrumentType->resolution,
            'snapshot_resolution_unit' => $instrumentType->resolution_unit,
            'snapshot_location_id' => (int) $location->location_id,
            'snapshot_detail_location_id' => (int) $detailLocation->detail_location_id,
            'snapshot_location_code' => $locationCode,
            'snapshot_location_name' => $locationName,
            'snapshot_detail_location_code' => $detailLocationCode,
            'snapshot_detail_location_name' => $detailLocationName,
        ];
    }

    private function freezeRequirementSnapshots(
        string $connectionName,
        Calibration $calibration,
        InstrumentType $instrumentType,
        string $method,
    ): void {
        $configured = InstrumentTypeRequirement::on($connectionName)
            ->where('instrument_type_id', $instrumentType->instrument_type_id)
            ->get()
            ->keyBy('requirement_category');

        foreach (InstrumentTypeRequirement::CATEGORIES as $category) {
            $mode = $configured->get($category)?->requirement_mode
                ?? InstrumentTypeRequirement::MODE_NOT_CONFIGURED;

            if (
                $method === Calibration::METHOD_INTERNAL
                && $category === InstrumentTypeRequirement::CATEGORY_ENVIRONMENT
                && $mode === InstrumentTypeRequirement::MODE_NOT_CONFIGURED
            ) {
                $mode = InstrumentTypeRequirement::MODE_REQUIRED;
            }

            if (
                $method === Calibration::METHOD_INTERNAL
                && $category === InstrumentTypeRequirement::CATEGORY_ACTIVITY
            ) {
                $mode = InstrumentTypeRequirement::MODE_OPTIONAL;
            }

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

    /**
     * @return list<array{parameter: EnvironmentParameter, requirement_mode: string}>
     */
    private function sessionEnvironmentSnapshots(
        string $connectionName,
        InstrumentType $instrumentType,
        string $method,
    ): array {
        $configurations = InstrumentTypeEnvironmentParameter::on($connectionName)
            ->with('parameter')
            ->where('instrument_type_id', $instrumentType->instrument_type_id)
            ->orderBy('display_order')
            ->orderBy('parameter_id')
            ->get();

        $snapshots = [];
        $injectedRoomCodes = [];

        if ($method === Calibration::METHOD_INTERNAL) {
            foreach ($this->requiredRoomEnvironmentCatalog($connectionName) as $parameter) {
                $snapshots[] = [
                    'parameter' => $parameter,
                    'requirement_mode' => InstrumentTypeRequirement::MODE_REQUIRED,
                ];
                $injectedRoomCodes[] = $parameter->parameter_code;
            }
        }

        foreach ($configurations as $configuration) {
            $parameter = $configuration->parameter;

            if ($parameter === null) {
                throw StartCalibrationException::environmentParameterMissing();
            }

            if (in_array($parameter->parameter_code, $injectedRoomCodes, true)) {
                continue;
            }

            $snapshots[] = [
                'parameter' => $parameter,
                'requirement_mode' => $configuration->requirement_mode,
            ];
        }

        return $snapshots;
    }

    /**
     * @return list<EnvironmentParameter>
     */
    private function requiredRoomEnvironmentCatalog(string $connectionName): array
    {
        $parameters = [];

        foreach ([
            EnvironmentParameter::CODE_TEMPERATURE,
            EnvironmentParameter::CODE_RELATIVE_HUMIDITY,
        ] as $parameterCode) {
            $parameter = EnvironmentParameter::on($connectionName)
                ->where('parameter_code', $parameterCode)
                ->first();

            if ($parameter === null) {
                throw StartCalibrationException::roomEnvironmentCatalogMissing();
            }

            $parameters[] = $parameter;
        }

        return $parameters;
    }

    private function freezeEnvironmentParameterSnapshots(
        string $connectionName,
        Calibration $calibration,
        InstrumentType $instrumentType,
        string $method,
    ): void {
        foreach ($this->sessionEnvironmentSnapshots($connectionName, $instrumentType, $method) as $index => $row) {
            $parameter = $row['parameter'];
            $snapshot = (new CalibrationEnvironmentParameterSnapshot)->setConnection($connectionName);
            $snapshot->forceFill([
                'calibration_id' => $calibration->calibration_id,
                'parameter_id' => $parameter->parameter_id,
                'parameter_code' => $parameter->parameter_code,
                'parameter_name' => $parameter->parameter_name,
                'unit' => $parameter->unit,
                'data_type' => $parameter->data_type,
                'requirement_mode' => $row['requirement_mode'],
                'display_order' => $index + 1,
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
