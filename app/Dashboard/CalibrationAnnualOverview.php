<?php

namespace App\Dashboard;

use App\Models\Calibration;
use App\Models\InstrumentType;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class CalibrationAnnualOverview
{
    public const MINIMUM_YEAR = 2026;

    public const TAB_OVERVIEW = 'overview';

    public const TAB_HISTORY = 'history';

    /**
     * @return list<int>
     */
    public static function yearOptions(int $currentYear, ?int $latestDataYear = null): array
    {
        $max = max(self::MINIMUM_YEAR, $currentYear, $latestDataYear ?? self::MINIMUM_YEAR);

        return range(self::MINIMUM_YEAR, $max);
    }

    public static function defaultYear(int $currentYear): int
    {
        return max(self::MINIMUM_YEAR, $currentYear);
    }

    /**
     * @param  list<int>  $allowed
     */
    public static function sanitizeYear(mixed $year, array $allowed, int $currentYear): int
    {
        $requested = (int) $year;

        if (in_array($requested, $allowed, true)) {
            return $requested;
        }

        return self::defaultYear($currentYear);
    }

    public static function sanitizeTab(mixed $tab): string
    {
        return $tab === self::TAB_HISTORY ? self::TAB_HISTORY : self::TAB_OVERVIEW;
    }

    /**
     * @return list<int>
     */
    public static function availableYears(Connection $connection, ?Carbon $today = null): array
    {
        $currentYear = (int) ($today ?? today())->year;

        return self::yearOptions($currentYear, self::latestDataYear($connection));
    }

    /**
     * @param  list<int>  $inProgressTypeIds
     * @param  list<int>  $completedTypeIds
     * @return array{types: int, completed: int, inProgress: int, notCalibrated: int}
     */
    public static function composeTypeTotals(int $types, array $inProgressTypeIds, array $completedTypeIds): array
    {
        $inProgress = array_values(array_unique($inProgressTypeIds));
        $completed = array_values(array_diff(array_unique($completedTypeIds), $inProgress));
        $inProgressCount = count($inProgress);
        $completedCount = count($completed);

        return [
            'types' => $types,
            'completed' => $completedCount,
            'inProgress' => $inProgressCount,
            'notCalibrated' => max(0, $types - $completedCount - $inProgressCount),
        ];
    }

    /**
     * @return array{types: int, completed: int, inProgress: int, notCalibrated: int}
     */
    public static function typeTotals(int $year, Connection $connection): array
    {
        $registered = self::instrumentTypes($connection)
            ->pluck('instrument_type_id')
            ->map(fn(mixed $id): int => (int) $id)
            ->all();

        return self::composeTypeTotals(
            count($registered),
            array_values(array_intersect(self::typeIdsWithInProgress($year, $connection), $registered)),
            array_values(array_intersect(self::typeIdsWithCompleted($year, $connection), $registered)),
        );
    }

    /**
     * @param  list<int>  $typeIds
     * @return array<int, Calibration>
     */
    public static function completedByType(array $typeIds, int $year, Connection $connection): array
    {
        return self::latestCompletedByType($typeIds, $year, $connection);
    }

    public static function progress(?Calibration $yearActive, ?Calibration $yearCompleted): CalibrationAnnualStatus
    {
        if ($yearActive !== null) {
            return CalibrationAnnualStatus::InProgress;
        }

        if ($yearCompleted !== null) {
            return CalibrationAnnualStatus::Completed;
        }

        return CalibrationAnnualStatus::NotCalibrated;
    }

    public static function yearActive(?Calibration $active, int $selectedYear): ?Calibration
    {
        if ($active === null || $active->session_status !== Calibration::STATUS_IN_PROGRESS) {
            return null;
        }

        if (self::crossYearActiveYear($active, $selectedYear) !== null) {
            return null;
        }

        return $active;
    }

    /**
     * @param  list<int>  $typeIds
     * @return array<int, Calibration>
     */
    public static function latestCompletedOverallByType(array $typeIds, Connection $connection): array
    {
        return self::latestCompletedByType($typeIds, null, $connection);
    }

    /**
     * @param  list<int>  $typeIds
     * @return array<int, Calibration>
     */
    public static function activeByType(array $typeIds, Connection $connection): array
    {
        return self::inProgressByType($typeIds, null, $connection);
    }

    public static function status(?Calibration $calibration): CalibrationAnnualStatus
    {
        if ($calibration === null) {
            return CalibrationAnnualStatus::NotCalibrated;
        }

        return match ($calibration->session_status) {
            Calibration::STATUS_IN_PROGRESS => CalibrationAnnualStatus::InProgress,
            Calibration::STATUS_COMPLETED => CalibrationAnnualStatus::Completed,
            default => CalibrationAnnualStatus::NotCalibrated,
        };
    }

    public static function crossYearActiveYear(?Calibration $active, int $selectedYear): ?int
    {
        if ($active === null || $active->session_status !== Calibration::STATUS_IN_PROGRESS) {
            return null;
        }

        $activeYear = self::inProgressYear($active);

        if ($activeYear === null || $activeYear === $selectedYear) {
            return null;
        }

        return $activeYear;
    }

    /**
     * @param  list<int>  $typeIds
     * @return array<int, Calibration>
     */
    private static function inProgressByType(array $typeIds, ?int $year, Connection $connection): array
    {
        if ($typeIds === []) {
            return [];
        }

        $query = self::calibrations($connection)
            ->inProgress()
            ->select(self::sessionColumns($connection))
            ->whereIn('calibration.instrument_type_id', $typeIds);

        if ($year !== null && self::hasCreatedAt($connection)) {
            $query->whereYear('calibration.created_at', $year);
        }

        return $query
            ->get()
            ->keyBy(fn(Calibration $calibration): int => (int) $calibration->instrument_type_id)
            ->all();
    }

    /**
     * @param  list<int>  $typeIds
     * @return array<int, Calibration>
     */
    private static function latestCompletedByType(array $typeIds, ?int $year, Connection $connection): array
    {
        if ($typeIds === []) {
            return [];
        }

        $latest = [];
        $query = self::calibrations($connection)
            ->completed()
            ->select(self::sessionColumns($connection))
            ->whereIn('calibration.instrument_type_id', $typeIds);

        if ($year !== null) {
            $query->whereYear('calibration.calibration_date', $year);
        }

        $query
            ->get()
            ->groupBy(fn(Calibration $calibration): int => (int) $calibration->instrument_type_id)
            ->each(function (Collection $calibrations, int|string $typeId) use (&$latest): void {
                $calibration = self::pickLatest($calibrations);

                if ($calibration instanceof Calibration) {
                    $latest[(int) $typeId] = $calibration;
                }
            });

        return $latest;
    }

    /**
     * @param  Collection<int, Calibration>  $calibrations
     */
    private static function pickLatest(Collection $calibrations): ?Calibration
    {
        $calibration = $calibrations
            ->sortByDesc(fn(Calibration $calibration): array => [
                $calibration->calibration_date?->getTimestamp() ?? PHP_INT_MIN,
                $calibration->calibration_id,
            ])
            ->first();

        return $calibration instanceof Calibration ? $calibration : null;
    }

    /**
     * @return list<int>
     */
    private static function typeIdsWithInProgress(int $year, Connection $connection): array
    {
        $query = self::calibrations($connection)
            ->inProgress()
            ->whereNotNull('calibration.instrument_type_id');

        if (self::hasCreatedAt($connection)) {
            $query->whereYear('calibration.created_at', $year);
        }

        return $query
            ->pluck('calibration.instrument_type_id')
            ->map(fn(mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private static function typeIdsWithCompleted(int $year, Connection $connection): array
    {
        return self::calibrations($connection)
            ->completed()
            ->whereNotNull('calibration.instrument_type_id')
            ->whereYear('calibration.calibration_date', $year)
            ->pluck('calibration.instrument_type_id')
            ->map(fn(mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private static function inProgressYear(Calibration $calibration): ?int
    {
        if (! self::hasCreatedAt($calibration->getConnection()) || $calibration->created_at === null) {
            return null;
        }

        return (int) $calibration->created_at->year;
    }

    /**
     * @return list<string>
     */
    private static function sessionColumns(Connection $connection): array
    {
        $columns = [
            'calibration.calibration_id',
            'calibration.instrument_id',
            'calibration.instrument_type_id',
            'calibration.session_status',
            'calibration.calibration_date',
            'calibration.calibration_due',
        ];

        if (self::hasCreatedAt($connection)) {
            $columns[] = 'calibration.created_at';
        }

        return $columns;
    }

    private static function latestDataYear(Connection $connection): ?int
    {
        $years = [];
        $completed = self::calibrations($connection)
            ->completed()
            ->whereNotNull('calibration.calibration_date')
            ->max('calibration.calibration_date');

        if (is_string($completed) && $completed !== '') {
            $years[] = (int) Carbon::parse($completed)->year;
        }

        if (self::hasCreatedAt($connection)) {
            $inProgress = self::calibrations($connection)->inProgress()->max('calibration.created_at');

            if (is_string($inProgress) && $inProgress !== '') {
                $years[] = (int) Carbon::parse($inProgress)->year;
            }
        }

        $years = array_values(array_filter(
            $years,
            fn(int $year): bool => $year >= self::MINIMUM_YEAR,
        ));

        return $years === [] ? null : max($years);
    }

    private static function hasCreatedAt(Connection $connection): bool
    {
        return Schema::connection(self::connectionName($connection))
            ->hasColumn((new Calibration)->getTable(), 'created_at');
    }

    /**
     * @return Builder<Calibration>
     */
    private static function calibrations(Connection $connection): Builder
    {
        return Calibration::on(self::connectionName($connection));
    }

    /**
     * @return Builder<InstrumentType>
     */
    private static function instrumentTypes(Connection $connection): Builder
    {
        return InstrumentType::on(self::connectionName($connection));
    }

    private static function connectionName(Connection $connection): string
    {
        $name = $connection->getName();

        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException('Calibration dashboard queries require a named connection.');
        }

        return $name;
    }
}
