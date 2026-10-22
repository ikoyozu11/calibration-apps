<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use App\Models\InstrumentTypeRequirement;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;

/**
 * Completes one in-progress calibration session.
 *
 * Dates come from the caller. There is no domain actor and no completion
 * reason. External completion requires exactly one active vendor PDF row and
 * does not read the file. Internal completion mirrors
 * calibration_session_assert_complete, including typed environment values and
 * schema version 2 result cells. SQLite compiles lockForUpdate() without the
 * PostgreSQL completion trigger or a real row lock.
 */
class CompleteCalibrationSession
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    public function complete(
        int $calibrationId,
        string $calibrationDate,
        string $calibrationDue,
        Connection $connection,
    ): void {
        $connection = $this->sessionConnection->resolve($connection);

        if ($calibrationId < 1) {
            throw CompleteCalibrationSessionException::invalidCalibrationId();
        }

        $calibrationDate = $this->canonicalDate($calibrationDate);
        $calibrationDue = $this->canonicalDate($calibrationDue);

        if ($calibrationDue < $calibrationDate) {
            throw CompleteCalibrationSessionException::dueBeforeCalibrationDate();
        }

        $connection->transaction(function () use ($connection, $calibrationId, $calibrationDate, $calibrationDue): void {
            $calibration = $connection->table('calibration')
                ->where('calibration_id', $calibrationId)
                ->lockForUpdate()
                ->first();

            if ($calibration === null) {
                throw CompleteCalibrationSessionException::missingCalibration();
            }

            if ($calibration->session_status !== Calibration::STATUS_IN_PROGRESS) {
                throw CompleteCalibrationSessionException::invalidSessionStatus();
            }

            $this->assertFrozenIdentity($calibration);

            $method = (string) $calibration->calibration_method;

            if (! in_array($method, [Calibration::METHOD_INTERNAL, Calibration::METHOD_EXTERNAL], true)) {
                throw CompleteCalibrationSessionException::unsupportedMethod();
            }

            if ($method === Calibration::METHOD_EXTERNAL) {
                $this->assertOneActiveDocument($connection, $calibrationId);
            } else {
                $this->assertInternal($connection, $calibration);
            }

            $updated = $connection->table('calibration')
                ->where('calibration_id', $calibrationId)
                ->where('session_status', Calibration::STATUS_IN_PROGRESS)
                ->update([
                    'session_status' => Calibration::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'calibration_date' => $calibrationDate,
                    'calibration_due' => $calibrationDue,
                ]);

            if ($updated !== 1) {
                throw CompleteCalibrationSessionException::invalidSessionStatus();
            }
        });
    }

    private function canonicalDate(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw CompleteCalibrationSessionException::invalidDates();
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            ! $date instanceof DateTimeImmutable
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw CompleteCalibrationSessionException::invalidDates();
        }

        return $value;
    }

    private function assertFrozenIdentity(object $calibration): void
    {
        if (
            $this->blank($calibration->snapshot_instrument_name ?? null)
            || $this->blank($calibration->snapshot_instrument_type_name ?? null)
            || $calibration->snapshot_location_id === null
            || $calibration->snapshot_detail_location_id === null
            || $this->blank($calibration->snapshot_location_code ?? null)
            || $this->blank($calibration->snapshot_location_name ?? null)
            || $this->blank($calibration->snapshot_detail_location_code ?? null)
            || $this->blank($calibration->snapshot_detail_location_name ?? null)
        ) {
            throw CompleteCalibrationSessionException::missingFrozenIdentity();
        }
    }

    private function assertOneActiveDocument(Connection $connection, int $calibrationId): void
    {
        $active = $connection->table('calibration_external_document')
            ->where('calibration_id', $calibrationId)
            ->orderBy('document_id')
            ->lockForUpdate()
            ->get()
            ->filter(fn (object $document): bool => $this->isAffirmative($document->is_active))
            ->count();

        if ($active !== 1) {
            throw CompleteCalibrationSessionException::invalidActivePdfCount();
        }
    }

    private function assertInternal(Connection $connection, object $calibration): void
    {
        $calibrationId = (int) $calibration->calibration_id;
        $snapshots = $connection->table('calibration_requirement_snapshot')
            ->where('calibration_id', $calibrationId)
            ->orderBy('requirement_category')
            ->orderBy('calibration_requirement_snapshot_id')
            ->lockForUpdate()
            ->get();

        $this->assertInternalProvider($connection, $calibration);
        $modes = $this->effectiveModes($snapshots);

        $usageCount = ($modes[InstrumentTypeRequirement::CATEGORY_STANDARDS] ?? null) === InstrumentTypeRequirement::MODE_REQUIRED
            ? $this->lockedCount($connection, 'calibration_standard_usage', $calibrationId, 'calibration_standard_usage_id')
            : null;
        $activityCount = ($modes[InstrumentTypeRequirement::CATEGORY_ACTIVITY] ?? null) === InstrumentTypeRequirement::MODE_REQUIRED
            ? $this->lockedCount($connection, 'calibration_activity', $calibrationId, 'calibration_activity_id')
            : null;

        $parameters = $connection->table('calibration_environment_parameter_snapshot')
            ->where('calibration_id', $calibrationId)
            ->orderBy('display_order')
            ->orderBy('parameter_id')
            ->lockForUpdate()
            ->get();
        $environmentValues = $connection->table('calibration_environment_value')
            ->where('calibration_id', $calibrationId)
            ->orderBy('parameter_id')
            ->lockForUpdate()
            ->get();
        $scopes = $connection->table('calibration_scope')
            ->where('calibration_id', $calibrationId)
            ->orderBy('scope_order')
            ->orderBy('calibration_scope_id')
            ->lockForUpdate()
            ->get();
        $results = $this->fullyPinned($scopes)->isNotEmpty()
            ? $this->lockedResults($connection, $scopes)
            : ['results' => new Collection, 'values' => new Collection];

        if ($usageCount === 0) {
            throw CompleteCalibrationSessionException::missingRequiredStandards();
        }

        if ($activityCount === 0) {
            throw CompleteCalibrationSessionException::missingRequiredActivity();
        }

        $this->assertTestProfile(
            $modes[InstrumentTypeRequirement::CATEGORY_TEST_PROFILE] ?? InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
            $scopes,
            $results['results'],
            $results['values'],
        );
        $this->assertEnvironment(
            $snapshots->first(fn (object $snapshot): bool => $snapshot->requirement_category === InstrumentTypeRequirement::CATEGORY_ENVIRONMENT),
            $modes[InstrumentTypeRequirement::CATEGORY_ENVIRONMENT] ?? InstrumentTypeRequirement::MODE_NOT_CONFIGURED,
            $parameters,
            $environmentValues,
        );
    }

    private function assertInternalProvider(Connection $connection, object $calibration): void
    {
        $providers = $connection->table('calibration_provider')
            ->where('provider_code', 'INTERNAL_TEAM')
            ->orderBy('calibration_provider_id')
            ->get(['calibration_provider_id']);

        $providerId = $providers->count() === 1
            ? $providers->first()->calibration_provider_id
            : null;

        if (
            $calibration->calibration_provider_id === null
            || $providerId === null
            || (int) $calibration->calibration_provider_id !== (int) $providerId
        ) {
            throw CompleteCalibrationSessionException::missingInternalProvider();
        }
    }

    /**
     * @param  Collection<int, object>  $snapshots
     * @return array<string, string>
     */
    private function effectiveModes(Collection $snapshots): array
    {
        $present = [];

        foreach (InstrumentTypeRequirement::CATEGORIES as $category) {
            $matches = $snapshots->filter(
                fn (object $snapshot): bool => $snapshot->requirement_category === $category,
            );

            if ($matches->count() !== 1) {
                throw CompleteCalibrationSessionException::missingRequirementSnapshot();
            }

            $present[$category] = $matches->first();
        }

        $modes = [];

        foreach ($present as $category => $snapshot) {
            $mode = $this->effectiveMode(
                (string) $snapshot->frozen_mode,
                $snapshot->resolved_mode !== null ? (string) $snapshot->resolved_mode : null,
            );

            if (! in_array($mode, [InstrumentTypeRequirement::MODE_REQUIRED, InstrumentTypeRequirement::MODE_OPTIONAL], true)) {
                throw CompleteCalibrationSessionException::unresolvedRequirementMode($category);
            }

            $modes[$category] = $mode;
        }

        return $modes;
    }

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

    private function lockedCount(Connection $connection, string $table, int $calibrationId, string $orderColumn): int
    {
        return $connection->table($table)
            ->where('calibration_id', $calibrationId)
            ->orderBy($orderColumn)
            ->lockForUpdate()
            ->count();
    }

    /**
     * @param  Collection<int, object>  $scopes
     * @return array{results: Collection<int, object>, values: Collection<int, object>}
     */
    private function lockedResults(Connection $connection, Collection $scopes): array
    {
        $scopeIds = $this->scopesWithSnapshot($scopes)
            ->map(fn (object $scope): int => (int) $scope->calibration_scope_id)
            ->all();
        $results = $scopeIds === []
            ? new Collection
            : $connection->table('calibration_test_result')
                ->whereIn('calibration_scope_id', $scopeIds)
                ->orderBy('calibration_scope_id')
                ->orderBy('test_point_id')
                ->orderBy('calibration_test_result_id')
                ->lockForUpdate()
                ->get();
        $resultIds = $results->map(fn (object $result): int => (int) $result->calibration_test_result_id)->all();
        $values = $resultIds === []
            ? new Collection
            : $connection->table('calibration_result_value')
                ->whereIn('calibration_test_result_id', $resultIds)
                ->orderBy('calibration_test_result_id')
                ->orderBy('column_key')
                ->lockForUpdate()
                ->get();

        return ['results' => $results, 'values' => $values];
    }

    /**
     * @param  Collection<int, object>  $scopes
     * @param  Collection<int, object>  $results
     * @param  Collection<int, object>  $values
     */
    private function assertTestProfile(string $mode, Collection $scopes, Collection $results, Collection $values): void
    {
        $pinned = $this->fullyPinned($scopes);

        if ($mode === InstrumentTypeRequirement::MODE_REQUIRED && $pinned->isEmpty()) {
            throw CompleteCalibrationSessionException::missingRequiredPin();
        }

        if ($pinned->isEmpty()) {
            return;
        }

        foreach ($this->scopesWithSnapshot($scopes) as $scope) {
            $snapshot = $this->decodedSnapshot($scope->test_table_snapshot);

            if ($snapshot === null || (string) ($snapshot['schema_version'] ?? '') !== '2') {
                throw CompleteCalibrationSessionException::incompleteTestComponents(
                    'A used test profile without schema version 2 completion rules cannot be completed.',
                );
            }

            $columns = $snapshot['columns'] ?? null;
            $rows = $snapshot['rows'] ?? null;

            if (! is_array($columns) || ! array_is_list($columns) || ! is_array($rows) || ! array_is_list($rows)) {
                throw CompleteCalibrationSessionException::incompleteTestComponents(
                    'A used test profile snapshot cannot be completed.',
                );
            }

            foreach ($columns as $column) {
                if (! is_array($column)) {
                    throw CompleteCalibrationSessionException::incompleteTestComponents(
                        'A used test profile snapshot cannot be completed.',
                    );
                }

                $rule = $column['completion_rule'] ?? null;
                $columnKey = $column['column_key'] ?? null;

                if ($rule !== InstrumentTypeRequirement::MODE_REQUIRED && $rule !== InstrumentTypeRequirement::MODE_OPTIONAL) {
                    throw CompleteCalibrationSessionException::incompleteTestComponents(
                        'An unconfigured test component blocks complete.',
                    );
                }

                if ($rule !== InstrumentTypeRequirement::MODE_REQUIRED) {
                    continue;
                }

                if (! is_string($columnKey) || $columnKey === '') {
                    throw CompleteCalibrationSessionException::incompleteTestComponents(
                        'An unconfigured test component blocks complete.',
                    );
                }

                foreach ($rows as $row) {
                    if (! is_array($row) || ! isset($row['test_point_id']) || ! is_numeric($row['test_point_id'])) {
                        throw CompleteCalibrationSessionException::incompleteTestComponents(
                            "Required test component {$columnKey} is missing a result row.",
                        );
                    }

                    $pointId = (int) $row['test_point_id'];
                    $matched = $results->filter(
                        fn (object $result): bool => (int) $result->calibration_scope_id === (int) $scope->calibration_scope_id
                            && (int) $result->test_point_id === $pointId,
                    );

                    if ($matched->count() !== 1) {
                        throw CompleteCalibrationSessionException::incompleteTestComponents(
                            "Required test component {$columnKey} is missing a result row.",
                        );
                    }

                    $resultId = (int) $matched->first()->calibration_test_result_id;
                    $cell = $values->filter(
                        fn (object $value): bool => (int) $value->calibration_test_result_id === $resultId
                            && $value->column_key === $columnKey,
                    );

                    if ($cell->count() !== 1 || ! $this->resultValuePresent($cell->first())) {
                        throw CompleteCalibrationSessionException::incompleteTestComponents(
                            "Required test component {$columnKey} is missing an engineer-entered value.",
                        );
                    }
                }
            }
        }
    }

    /**
     * @param  Collection<int, object>  $parameters
     * @param  Collection<int, object>  $values
     */
    private function assertEnvironment(mixed $snapshot, string $mode, Collection $parameters, Collection $values): void
    {
        if (! is_object($snapshot)) {
            throw CompleteCalibrationSessionException::missingRequirementSnapshot();
        }

        if ($snapshot->configuration_confirmed_at === null) {
            throw CompleteCalibrationSessionException::unconfirmedEnvironment();
        }

        foreach ($parameters as $parameter) {
            if ($this->isExplicitlyFalse($parameter->inclusion_confirmed)) {
                throw CompleteCalibrationSessionException::unconfirmedEnvironmentInclusion();
            }
        }

        $required = $parameters->filter(
            fn (object $parameter): bool => $this->isAffirmative($parameter->inclusion_confirmed)
                && $parameter->requirement_mode === InstrumentTypeRequirement::MODE_REQUIRED,
        );

        if ($mode === InstrumentTypeRequirement::MODE_REQUIRED && $required->isEmpty()) {
            throw CompleteCalibrationSessionException::missingRequiredEnvironmentParameter();
        }

        foreach ($required as $parameter) {
            $value = $values->first(
                fn (object $row): bool => (int) $row->parameter_id === (int) $parameter->parameter_id,
            );

            if ($parameter->data_type === 'numeric' && ($value === null || $value->numeric_value === null)) {
                throw CompleteCalibrationSessionException::incompleteEnvironment();
            }

            if ($parameter->data_type === 'text' && ($value === null || $this->blank($value->text_value))) {
                throw CompleteCalibrationSessionException::incompleteEnvironment();
            }
        }
    }

    /**
     * @param  Collection<int, object>  $scopes
     * @return Collection<int, object>
     */
    private function fullyPinned(Collection $scopes): Collection
    {
        return $scopes->filter(
            fn (object $scope): bool => $scope->test_profile_version_id !== null && $this->snapshotPresent($scope->test_table_snapshot),
        )->values();
    }

    /**
     * @param  Collection<int, object>  $scopes
     * @return Collection<int, object>
     */
    private function scopesWithSnapshot(Collection $scopes): Collection
    {
        return $scopes->filter(
            fn (object $scope): bool => $this->snapshotPresent($scope->test_table_snapshot),
        )->values();
    }

    private function snapshotPresent(mixed $snapshot): bool
    {
        if ($snapshot === null) {
            return false;
        }

        if (is_string($snapshot)) {
            return trim($snapshot) !== '';
        }

        return is_array($snapshot) && $snapshot !== [];
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

        return is_array($decoded) ? $decoded : null;
    }

    private function resultValuePresent(object $value): bool
    {
        if ($value->numeric_value !== null) {
            return true;
        }

        return is_string($value->text_value) && trim($value->text_value) !== '';
    }

    private function blank(mixed $value): bool
    {
        return ! is_string($value) || trim($value) === '';
    }

    private function isAffirmative(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private function isExplicitlyFalse(mixed $value): bool
    {
        return $value === false || $value === 0 || $value === '0';
    }
}
