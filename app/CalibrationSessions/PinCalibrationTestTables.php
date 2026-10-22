<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use App\Models\TestProfileVersion;
use App\TestColumns\TestTableSnapshotBuilder;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use ReflectionProperty;

/**
 * Pins every current Active Test Table for one in-progress internal session.
 *
 * The instrument type comes from the locked calibration. Each eligible profile
 * contributes one scope with its Active version id and a schema-version 2
 * snapshot from TestTableSnapshotBuilder. A later Active version does not
 * replace a consistent frozen pin. Effective mode comes from
 * CalibrationSessionCompleteness::effectiveMode only. This service does not
 * call CalibrationScopePinning, Model::on(), auth(), or the complete checks.
 * It does not calculate Average or Deviation. SQLite compiles lockForUpdate()
 * without a real row lock or the PostgreSQL version triggers.
 */
class PinCalibrationTestTables
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
        private TestTableSnapshotBuilder $snapshots,
    ) {}

    public function pin(int $calibrationId, Connection $connection): void
    {
        $connection = $this->sessionConnection->resolve($connection);

        if ($calibrationId < 1) {
            throw PinCalibrationTestTablesException::invalidCalibrationId();
        }

        $connection->transaction(function () use ($connection, $calibrationId): void {
            $calibration = $connection->table('calibration')
                ->where('calibration_id', $calibrationId)
                ->lockForUpdate()
                ->first();

            if ($calibration === null) {
                throw PinCalibrationTestTablesException::missingCalibration();
            }

            if ($calibration->calibration_method !== Calibration::METHOD_INTERNAL) {
                throw PinCalibrationTestTablesException::invalidMethod();
            }

            if ($calibration->session_status !== Calibration::STATUS_IN_PROGRESS) {
                throw PinCalibrationTestTablesException::invalidSessionStatus();
            }

            $typeId = (int) $calibration->instrument_type_id;

            $requirement = $connection->table('calibration_requirement_snapshot')
                ->where('calibration_id', $calibrationId)
                ->where('requirement_category', InstrumentTypeRequirement::CATEGORY_TEST_PROFILE)
                ->lockForUpdate()
                ->first();

            if ($requirement === null) {
                throw PinCalibrationTestTablesException::missingSnapshot();
            }

            $this->assertEffectiveMode($requirement);

            $profiles = $connection->table('test_profile')
                ->where('instrument_type_id', $typeId)
                ->orderBy('test_profile_id')
                ->lockForUpdate()
                ->get();

            $profileIds = $profiles->pluck('test_profile_id')->all();
            $versions = $profileIds === []
                ? new Collection
                : $connection->table('test_profile_version')
                    ->whereIn('test_profile_id', $profileIds)
                    ->orderBy('test_profile_id')
                    ->orderBy('test_profile_version_id')
                    ->lockForUpdate()
                    ->get();

            $scopes = $connection->table('calibration_scope')
                ->where('calibration_id', $calibrationId)
                ->where('instrument_type_id', $typeId)
                ->orderBy('scope_order')
                ->orderBy('calibration_scope_id')
                ->lockForUpdate()
                ->get();

            if ($scopes->isNotEmpty()) {
                $this->assertFrozenScopes($connection, $typeId, $scopes);

                return;
            }

            if ($typeId < 1) {
                throw PinCalibrationTestTablesException::noEligibleActiveTable();
            }

            $this->insertScopes($connection, $calibrationId, $typeId, $this->activeVersions($profiles, $versions));
        });
    }

    private function assertEffectiveMode(object $requirement): void
    {
        $resolvedMode = $requirement->resolved_mode !== null ? (string) $requirement->resolved_mode : null;
        $effectiveMode = CalibrationSessionCompleteness::effectiveMode(
            (string) $requirement->frozen_mode,
            $resolvedMode,
        );

        if ($effectiveMode === CalibrationSessionCompleteness::MODE_NOT_CONFIGURED) {
            throw PinCalibrationTestTablesException::unresolvedMode();
        }

        if (! in_array($effectiveMode, [
            CalibrationSessionCompleteness::MODE_REQUIRED,
            CalibrationSessionCompleteness::MODE_OPTIONAL,
        ], true)) {
            throw PinCalibrationTestTablesException::invalidEffectiveMode();
        }
    }

    /**
     * @param  Collection<int, object>  $profiles
     * @param  Collection<int, object>  $versions
     * @return list<object>
     */
    private function activeVersions(Collection $profiles, Collection $versions): array
    {
        $selected = [];

        foreach ($profiles as $profile) {
            if ($profile->archived_at !== null) {
                continue;
            }

            $active = $versions
                ->filter(fn (object $version): bool => (int) $version->test_profile_id === (int) $profile->test_profile_id
                    && $version->status === TestProfileVersion::STATUS_ACTIVE)
                ->values();

            if ($active->count() > 1) {
                throw PinCalibrationTestTablesException::ambiguousActiveVersion();
            }

            if ($active->isEmpty()) {
                continue;
            }

            $version = $active->first();

            if ((int) $version->test_profile_id !== (int) $profile->test_profile_id) {
                throw PinCalibrationTestTablesException::ambiguousActiveVersion();
            }

            $selected[] = $version;
        }

        if ($selected === []) {
            throw PinCalibrationTestTablesException::noEligibleActiveTable();
        }

        return $selected;
    }

    /**
     * @param  Collection<int, object>  $scopes
     */
    private function assertFrozenScopes(Connection $connection, int $typeId, Collection $scopes): void
    {
        $profileIds = [];

        foreach ($scopes as $scope) {
            if ((int) $scope->instrument_type_id !== $typeId || $scope->test_profile_id === null) {
                throw PinCalibrationTestTablesException::conflictingExistingScopes();
            }

            $snapshot = $this->decodedSnapshot($scope->test_table_snapshot);

            if ($scope->test_profile_version_id === null || $snapshot === null) {
                throw PinCalibrationTestTablesException::partiallyPinnedScopes();
            }

            if (! TestTableSnapshotBuilder::isWellformed($snapshot)
                || (int) $snapshot['test_profile_id'] !== (int) $scope->test_profile_id
                || (int) $snapshot['test_profile_version_id'] !== (int) $scope->test_profile_version_id) {
                throw PinCalibrationTestTablesException::inconsistentPinnedScopes();
            }

            $profileId = (int) $scope->test_profile_id;

            if (isset($profileIds[$profileId])) {
                throw PinCalibrationTestTablesException::conflictingExistingScopes();
            }

            $profileIds[$profileId] = true;
        }

        $profiles = $connection->table('test_profile')
            ->whereIn('test_profile_id', array_keys($profileIds))
            ->get();

        if ($profiles->count() !== count($profileIds)) {
            throw PinCalibrationTestTablesException::conflictingExistingScopes();
        }

        foreach ($profiles as $profile) {
            if ((int) $profile->instrument_type_id !== $typeId) {
                throw PinCalibrationTestTablesException::conflictingExistingScopes();
            }
        }
    }

    /**
     * @param  list<object>  $versions
     */
    private function insertScopes(Connection $connection, int $calibrationId, int $typeId, array $versions): void
    {
        $baseOrder = (int) $connection->table('calibration_scope')
            ->where('calibration_id', $calibrationId)
            ->max('scope_order');
        $nextScopeId = (int) $connection->table('calibration_scope')->max('calibration_scope_id');
        $scopeOrder = 1;

        foreach ($versions as $version) {
            $snapshot = $this->snapshot($connection, (int) $version->test_profile_version_id);
            $nextScopeId++;
            $payload = [
                'calibration_id' => $calibrationId,
                'instrument_type_id' => $typeId,
                'test_profile_id' => (int) $version->test_profile_id,
                'test_profile_version_id' => (int) $version->test_profile_version_id,
                'test_table_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'scope_order' => $baseOrder + $scopeOrder,
                'remarks' => null,
                'created_at' => now(),
            ];

            if ($connection->getDriverName() !== 'pgsql') {
                $payload['calibration_scope_id'] = $nextScopeId;
            }

            $connection->table('calibration_scope')->insert($payload);
            $scopeOrder++;
        }
    }

    /**
     * The snapshot builder copies Connection::getName() onto hydrated models.
     * An empty or unregistered name is reported as the manager key only while
     * that builder runs, then restored so the supplied connection is unchanged.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Connection $connection, int $versionId): array
    {
        $version = new TestProfileVersion;
        $version->exists = true;
        $version->setAttribute($version->getKeyName(), $versionId);

        $eloquentName = $this->eloquentConnectionName($connection);

        if ($eloquentName === null) {
            throw CalibrationSessionConnectionException::ambiguous();
        }

        $reportedName = $connection->getName();
        $renamed = false;

        if (is_string($eloquentName) && $reportedName !== $eloquentName) {
            $this->replaceConnectionName($connection, $eloquentName);
            $renamed = true;
        }

        if ($eloquentName !== null) {
            $version->setConnection($eloquentName);
        }

        try {
            return $this->snapshots->build(
                $version,
                $this->connectionIsRegistered($connection->getName()) ? $connection : null,
            );
        } finally {
            if ($renamed) {
                $this->replaceConnectionName($connection, is_string($reportedName) ? $reportedName : '');
            }
        }
    }

    private function replaceConnectionName(Connection $connection, string $name): void
    {
        $config = new ReflectionProperty($connection, 'config');
        $settings = $config->getValue($connection);

        if (! is_array($settings)) {
            return;
        }

        $settings['name'] = $name;
        $config->setValue($connection, $settings);
    }

    private function eloquentConnectionName(Connection $connection): ?string
    {
        $name = $connection->getName();

        if ($this->connectionIsRegistered($name)) {
            return $name;
        }

        $manager = app('db');

        if (! $manager instanceof DatabaseManager) {
            return null;
        }

        foreach ($manager->getConnections() as $registeredName => $existing) {
            if ($existing === $connection && $this->connectionIsRegistered($registeredName)) {
                return $registeredName;
            }
        }

        return null;
    }

    private function connectionIsRegistered(mixed $name): bool
    {
        return is_string($name)
            && $name !== ''
            && is_array(config('database.connections.'.$name));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodedSnapshot(mixed $snapshot): ?array
    {
        if (is_array($snapshot)) {
            return $snapshot === [] ? null : $snapshot;
        }

        if (! is_string($snapshot) || trim($snapshot) === '') {
            return null;
        }

        $decoded = json_decode($snapshot, true);

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }
}
