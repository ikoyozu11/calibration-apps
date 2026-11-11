<?php

namespace App\Reports;

use App\Dashboard\CalibrationAnnualOverview;
use App\Dashboard\CalibrationAnnualStatus;
use App\Dashboard\CalibrationStanding;
use App\Models\Calibration;
use App\Models\Location;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

final class CalibrationReport
{
    /** @var list<string> */
    public const HEADERS = [
        'Instrument Name',
        'Brand',
        'Type',
        'Model',
        'Serial Number',
        'Asset Number',
        'Calibration Date',
        'Calibration Due',
        'Calibration Status',
        'Location',
        'Detail Location',
    ];

    /**
     * @return list<int>
     */
    public static function years(Connection $connection): array
    {
        return CalibrationAnnualOverview::availableYears($connection);
    }

    /**
     * @return list<string>
     */
    public static function locations(Connection $connection, int $year = 0): array
    {
        return Location::on($connection->getName())
            ->newQuery()
            ->whereNotNull('location_name')
            ->whereRaw("trim(location_name) <> ''")
            ->distinct()
            ->orderBy('location_name')
            ->pluck('location_name')
            ->map(fn (mixed $location): string => (string) $location)
            ->values()
            ->all();
    }

    public static function sanitizeYear(mixed $year, Connection $connection): int
    {
        $years = self::years($connection);
        $requested = (int) $year;

        return in_array($requested, $years, true)
            ? $requested
            : CalibrationAnnualOverview::defaultYear((int) today()->year);
    }

    public static function sanitizeLocation(mixed $location): string
    {
        return is_string($location) ? trim($location) : '';
    }

    /**
     * The selected year is applied before ranking. Search and location are
     * then applied to the resulting latest completed row for each pair.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function paginate(
        Connection $connection,
        int $year,
        string $location,
        string $search,
        int $perPage,
        string $pageName = 'reportPage',
    ): LengthAwarePaginator {
        $rows = self::reportQuery($connection, $year, $location, $search)
            ->paginate($perPage, ['*'], $pageName);

        return $rows->through(fn (object $row): array => self::present($row));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function all(
        Connection $connection,
        int $year,
        string $location,
        string $search,
    ): Collection {
        return self::reportQuery($connection, $year, $location, $search)
            ->get()
            ->map(fn (object $row): array => self::present($row));
    }

    /**
     * Stream all matching rows for exports without loading the query result
     * set into an intermediate collection.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public static function cursor(
        Connection $connection,
        int $year,
        string $location,
        string $search,
    ): LazyCollection {
        return self::reportQuery($connection, $year, $location, $search)
            ->cursor()
            ->map(fn (object $row): array => self::present($row));
    }

    private static function reportQuery(
        Connection $connection,
        int $year,
        string $location,
        string $search,
    ): Builder {
        [$from, $until] = self::yearBounds($year);
        $database = DB::connection($connection->getName());

        // created_at is the canonical session start timestamp. The schema has
        // no started_at column; Start Calibration creates this row immediately.
        $annualSessions = Calibration::on($connection->getName())
            ->newQuery()
            ->whereBetween('created_at', [$from, $until])
            ->select('calibration.*');

        $annualRanked = $database
            ->query()
            ->fromSub($annualSessions, 's')
            ->select('s.*')
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY s.instrument_id, s.instrument_type_id '
                .'ORDER BY s.created_at DESC, s.completed_at IS NULL ASC, '
                .'s.completed_at DESC, s.cancelled_at IS NULL ASC, '
                .'s.cancelled_at DESC, s.calibration_id DESC) AS report_rank'
            );

        $currentCompleted = Calibration::on($connection->getName())
            ->newQuery()
            ->completed()
            ->select('calibration.*');

        $currentRanked = $database
            ->query()
            ->fromSub($currentCompleted, 'completed')
            ->select('completed.*')
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY completed.instrument_id, completed.instrument_type_id '
                .'ORDER BY completed.calibration_date DESC NULLS LAST, '
                .'completed.completed_at DESC NULLS LAST, completed.calibration_id DESC) AS current_rank'
            );

        $query = $database
            ->query()
            ->from('instrument_type as it')
            ->join('instrument as i', 'i.instrument_id', '=', 'it.instrument_id')
            ->leftJoin('detail_location as dl', 'dl.detail_location_id', '=', 'i.detail_location_id')
            ->leftJoin('location as l', 'l.location_id', '=', 'dl.location_id')
            ->leftJoinSub($annualRanked, 'r', function ($join): void {
                $join->on('r.instrument_id', '=', 'it.instrument_id')
                    ->on('r.instrument_type_id', '=', 'it.instrument_type_id')
                    ->where('r.report_rank', 1);
            })
            ->leftJoinSub($currentRanked, 'cr', function ($join): void {
                $join->on('cr.instrument_id', '=', 'it.instrument_id')
                    ->on('cr.instrument_type_id', '=', 'it.instrument_type_id')
                    ->where('cr.current_rank', 1);
            })
            ->select([
                'i.instrument_id',
                'it.instrument_type_id',
                'r.calibration_id',
                'r.session_status',
                'r.calibration_date',
                'r.calibration_due',
                'cr.calibration_due as current_calibration_due',
                'l.location_name',
                'dl.detail_location_name',
                DB::raw("COALESCE(r.snapshot_instrument_name, i.instrument_name, '—') AS instrument_name"),
                DB::raw("COALESCE(r.snapshot_brand, it.brand, '—') AS brand"),
                DB::raw("COALESCE(r.snapshot_instrument_type_name, it.type_name, '—') AS instrument_type"),
                DB::raw("COALESCE(r.snapshot_model, it.model, '—') AS model"),
                DB::raw("COALESCE(r.snapshot_serial_number, i.serial_number, '—') AS serial_number"),
                DB::raw("COALESCE(r.snapshot_asset_number, it.asset_number, '—') AS asset_number"),
            ])
            ->when($location !== '', fn (Builder $query): Builder => $query->where('l.location_name', $location))
            ->orderByRaw(self::annualStatusOrderSql(), [
                today()->toDateString(),
                today()->addDays(60)->toDateString(),
                today()->toDateString(),
            ])
            ->orderByDesc('r.created_at')
            ->orderBy('i.instrument_name')
            ->orderBy('it.instrument_type_id');

        self::applySearch($query, $search);

        return $query;
    }

    private static function applySearch(Builder $query, string $search): void
    {
        $search = trim($search);

        if ($search === '') {
            return;
        }

        $like = '%'.addcslashes($search, '%_\\').'%';

        $query->where(function (Builder $query) use ($like): void {
            foreach ([
                "COALESCE(r.snapshot_instrument_name, i.instrument_name, '')",
                "COALESCE(r.snapshot_brand, it.brand, '')",
                "COALESCE(r.snapshot_instrument_type_name, it.type_name, '')",
                "COALESCE(r.snapshot_model, it.model, '')",
                "COALESCE(r.snapshot_serial_number, i.serial_number, '')",
                "COALESCE(r.snapshot_asset_number, it.asset_number, '')",
                'l.location_name',
                'dl.detail_location_name',
            ] as $column) {
                $query->orWhereRaw("LOWER({$column}) LIKE LOWER(?)", [$like]);
            }
        });
    }

    /** @return array{0: string, 1: string} */
    private static function yearBounds(int $year): array
    {
        $from = Carbon::createSafe($year, 1, 1)->startOfDay();
        $until = Carbon::createSafe($year, 12, 31)->endOfDay();

        return [$from->toDateTimeString(), $until->toDateTimeString()];
    }

    /** @return array<string, mixed> */
    private static function present(object $row): array
    {
        $annualDue = $row->calibration_due === null ? null : Carbon::parse((string) $row->calibration_due);
        $currentDue = $row->current_calibration_due === null
            ? null
            : Carbon::parse((string) $row->current_calibration_due);
        $annualStatus = match ($row->session_status) {
            Calibration::STATUS_IN_PROGRESS => CalibrationAnnualStatus::InProgress->value,
            Calibration::STATUS_CANCELLED => 'Cancelled',
            Calibration::STATUS_COMPLETED => CalibrationAnnualStatus::Completed->value,
            default => CalibrationAnnualStatus::NotCalibrated->value,
        };

        return [
            'instrument_id' => (int) $row->instrument_id,
            'instrument_type_id' => (int) $row->instrument_type_id,
            'instrument_name' => (string) $row->instrument_name,
            'brand' => (string) $row->brand,
            'instrument_type' => (string) $row->instrument_type,
            'model' => (string) $row->model,
            'serial_number' => (string) $row->serial_number,
            'asset_number' => (string) $row->asset_number,
            'calibration_date' => $row->calibration_date === null ? null : (string) $row->calibration_date,
            'calibration_due' => $annualDue?->toDateString(),
            'due_status' => CalibrationStanding::fromDueDate($currentDue)->value,
            'annual_status' => $annualStatus,
            'location' => filled($row->location_name) ? (string) $row->location_name : '—',
            'detail_location' => filled($row->detail_location_name) ? (string) $row->detail_location_name : '—',
            'calibration_id' => $row->calibration_id === null ? null : (int) $row->calibration_id,
        ];
    }

    private static function annualStatusOrderSql(): string
    {
        return <<<'SQL'
            CASE
                WHEN r.session_status = 'completed' AND r.calibration_due < ? THEN 3
                WHEN r.session_status = 'completed' AND r.calibration_due <= ? THEN 2
                WHEN r.session_status = 'completed' AND r.calibration_due > ? THEN 1
                WHEN r.session_status = 'in_progress' THEN 4
                WHEN r.session_status = 'cancelled' THEN 5
                ELSE 6
            END
        SQL;
    }
}
