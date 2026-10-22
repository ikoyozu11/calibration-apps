<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use App\Models\EnvironmentParameter;
use App\Models\InstrumentTypeRequirement;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Records or clears one environment measurement for an in-progress internal session.
 *
 * The frozen parameter snapshot chooses numeric_value or text_value. A null
 * value deletes the existing measurement row and does not insert a both-null
 * row. This service does not confirm configuration, resolve the requirement,
 * write calibration_environment, or call catalog writers. SQLite compiles
 * lockForUpdate() without a real row lock or the PostgreSQL legacy projection.
 */
class RecordCalibrationEnvironmentMeasurement
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    /**
     * @throws RecordCalibrationEnvironmentMeasurementException
     * @throws CalibrationSessionConnectionException
     * @throws QueryException
     */
    public function record(
        int $calibrationId,
        int $parameterId,
        ?string $value,
        Connection $connection,
    ): void {
        $connection = $this->sessionConnection->resolve($connection);

        if ($calibrationId < 1) {
            throw RecordCalibrationEnvironmentMeasurementException::invalidCalibrationId();
        }

        if ($parameterId < 1) {
            throw RecordCalibrationEnvironmentMeasurementException::invalidParameterId();
        }

        $connection->transaction(function () use ($connection, $calibrationId, $parameterId, $value): void {
            $this->lockCalibration($connection, $calibrationId);
            $this->assertOperableMode($this->lockRequirement($connection, $calibrationId));
            $parameter = $this->frozenParameter($this->lockParameters($connection, $calibrationId), $parameterId);
            $dataType = $this->dataType($parameter);
            $existing = $this->existingValue($this->lockValues($connection, $calibrationId), $parameterId);

            if ($value === null) {
                $this->clear($connection, $calibrationId, $parameterId, $existing);

                return;
            }

            [$numericValue, $textValue] = $this->measurement($dataType, $value);

            if ($existing === null) {
                $this->insert($connection, $calibrationId, $parameterId, $numericValue, $textValue);

                return;
            }

            $connection->table('calibration_environment_value')
                ->where('calibration_environment_value_id', $existing->calibration_environment_value_id)
                ->where('calibration_id', $calibrationId)
                ->where('parameter_id', $parameterId)
                ->update([
                    'numeric_value' => $numericValue,
                    'text_value' => $textValue,
                    'recorded_at' => now(),
                ]);
        });
    }

    private function lockCalibration(Connection $connection, int $calibrationId): void
    {
        $calibration = $connection->table('calibration')
            ->where('calibration_id', $calibrationId)
            ->lockForUpdate()
            ->first();

        if ($calibration === null) {
            throw RecordCalibrationEnvironmentMeasurementException::missingCalibration();
        }

        if ((string) $calibration->calibration_method !== Calibration::METHOD_INTERNAL) {
            throw RecordCalibrationEnvironmentMeasurementException::unsupportedCalibrationMethod();
        }

        if ((string) $calibration->session_status !== Calibration::STATUS_IN_PROGRESS) {
            throw RecordCalibrationEnvironmentMeasurementException::invalidSessionStatus();
        }
    }

    private function lockRequirement(Connection $connection, int $calibrationId): object
    {
        $snapshot = $connection->table('calibration_requirement_snapshot')
            ->where('calibration_id', $calibrationId)
            ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_ENVIRONMENT)
            ->orderBy('calibration_requirement_snapshot_id')
            ->lockForUpdate()
            ->first();

        if ($snapshot === null) {
            throw RecordCalibrationEnvironmentMeasurementException::missingEnvironmentRequirement();
        }

        return $snapshot;
    }

    private function assertOperableMode(object $snapshot): void
    {
        $frozen = (string) $snapshot->frozen_mode;
        $resolved = $snapshot->resolved_mode !== null ? (string) $snapshot->resolved_mode : null;
        $mode = $this->effectiveMode($frozen, $resolved);

        if (in_array($mode, [InstrumentTypeRequirement::MODE_REQUIRED, InstrumentTypeRequirement::MODE_OPTIONAL], true)) {
            return;
        }

        if ($frozen === InstrumentTypeRequirement::MODE_NOT_CONFIGURED) {
            throw RecordCalibrationEnvironmentMeasurementException::unresolvedEnvironmentMode();
        }

        throw RecordCalibrationEnvironmentMeasurementException::invalidEffectiveMode();
    }

    /**
     * PostgreSQL calibration_requirement_effective_mode CASE.
     */
    private function effectiveMode(string $frozenMode, ?string $resolvedMode): string
    {
        if (in_array($frozenMode, [InstrumentTypeRequirement::MODE_REQUIRED, InstrumentTypeRequirement::MODE_OPTIONAL], true)) {
            return $frozenMode;
        }

        if (
            $frozenMode === InstrumentTypeRequirement::MODE_NOT_CONFIGURED
            && in_array($resolvedMode, [InstrumentTypeRequirement::MODE_REQUIRED, InstrumentTypeRequirement::MODE_OPTIONAL], true)
        ) {
            return $resolvedMode;
        }

        return InstrumentTypeRequirement::MODE_NOT_CONFIGURED;
    }

    /**
     * @return Collection<int, object>
     */
    private function lockParameters(Connection $connection, int $calibrationId): Collection
    {
        return $connection->table('calibration_environment_parameter_snapshot')
            ->where('calibration_id', $calibrationId)
            ->orderBy('display_order')
            ->orderBy('parameter_id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, object>  $parameters
     */
    private function frozenParameter(Collection $parameters, int $parameterId): object
    {
        foreach ($parameters as $parameter) {
            if ((int) $parameter->parameter_id === $parameterId) {
                return $parameter;
            }
        }

        throw RecordCalibrationEnvironmentMeasurementException::unknownSessionParameter();
    }

    private function dataType(object $parameter): string
    {
        $dataType = (string) $parameter->data_type;

        if (! in_array($dataType, [EnvironmentParameter::DATA_TYPE_NUMERIC, EnvironmentParameter::DATA_TYPE_TEXT], true)) {
            throw RecordCalibrationEnvironmentMeasurementException::unsupportedParameterDataType();
        }

        return $dataType;
    }

    /**
     * @return Collection<int, object>
     */
    private function lockValues(Connection $connection, int $calibrationId): Collection
    {
        return $connection->table('calibration_environment_value')
            ->where('calibration_id', $calibrationId)
            ->orderBy('parameter_id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, object>  $values
     */
    private function existingValue(Collection $values, int $parameterId): ?object
    {
        foreach ($values as $value) {
            if ((int) $value->parameter_id === $parameterId) {
                return $value;
            }
        }

        return null;
    }

    private function clear(Connection $connection, int $calibrationId, int $parameterId, ?object $existing): void
    {
        if ($existing === null) {
            return;
        }

        $connection->table('calibration_environment_value')
            ->where('calibration_environment_value_id', $existing->calibration_environment_value_id)
            ->where('calibration_id', $calibrationId)
            ->where('parameter_id', $parameterId)
            ->delete();
    }

    /**
     * Locale-independent decimal: an optional minus, one or more digits, then
     * an optional fraction. The accepted string is stored unchanged. Commas,
     * exponents, a leading plus, and surrounding whitespace are rejected.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function measurement(string $dataType, string $value): array
    {
        if ($dataType === EnvironmentParameter::DATA_TYPE_NUMERIC) {
            if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
                throw RecordCalibrationEnvironmentMeasurementException::invalidNumericValue();
            }

            return [$value, null];
        }

        if (trim($value) === '') {
            throw RecordCalibrationEnvironmentMeasurementException::invalidTextValue();
        }

        return [null, $value];
    }

    private function insert(
        Connection $connection,
        int $calibrationId,
        int $parameterId,
        ?string $numericValue,
        ?string $textValue,
    ): void {
        $payload = [
            'calibration_id' => $calibrationId,
            'parameter_id' => $parameterId,
            'numeric_value' => $numericValue,
            'text_value' => $textValue,
            'recorded_at' => now(),
        ];

        if ($connection->getDriverName() !== 'pgsql') {
            $payload['calibration_environment_value_id'] = ((int) $connection->table('calibration_environment_value')->max('calibration_environment_value_id')) + 1;
        }

        $connection->table('calibration_environment_value')->insert($payload);
    }
}
