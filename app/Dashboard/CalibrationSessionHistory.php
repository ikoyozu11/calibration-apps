<?php

namespace App\Dashboard;

use App\Models\Calibration;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class CalibrationSessionHistory
{
    public const STATUS_ALL = 'all';

    /**
     * @return list<string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_ALL,
            Calibration::STATUS_IN_PROGRESS,
            Calibration::STATUS_COMPLETED,
            Calibration::STATUS_CANCELLED,
        ];
    }

    public static function sanitizeStatus(mixed $status): string
    {
        $requested = is_string($status) ? $status : '';

        return in_array($requested, self::statusOptions(), true) ? $requested : self::STATUS_ALL;
    }

    public static function sanitizeIdentityId(mixed $id): ?int
    {
        $value = (int) $id;

        return $value > 0 ? $value : null;
    }

    /**
     * @return list<int>
     */
    public static function availableYears(Connection $connection, ?Carbon $today = null): array
    {
        $currentYear = (int) ($today ?? today())->year;

        return CalibrationAnnualOverview::yearOptions($currentYear, self::latestSessionYear($connection));
    }

    /**
     * @return LengthAwarePaginator<int, array{key: string, calibration_id: int, name: string, type: string, serial_number: string, calibration_number: string, session_date: string, status: string, method: string, engineer: string, detail_url: string}>
     */
    public static function paginate(
        int $year,
        string $status,
        string $search,
        ?int $instrumentId,
        ?int $instrumentTypeId,
        int $perPage,
        Connection $connection,
    ): LengthAwarePaginator {
        $query = self::calibrations($connection)
            ->select('calibration.*')
            ->leftJoin('instrument', 'instrument.instrument_id', '=', 'calibration.instrument_id')
            ->leftJoin('instrument_type', 'instrument_type.instrument_type_id', '=', 'calibration.instrument_type_id')
            ->addSelect([
                'instrument.instrument_name as live_instrument_name',
                'instrument.serial_number as live_serial_number',
                'instrument_type.type_name as live_type_name',
            ]);

        if (self::hasAppUser($connection)) {
            $query->leftJoin('app_user', 'app_user.app_user_id', '=', 'calibration.started_by_app_user_id')
                ->addSelect('app_user.username as engineer_username');
        }

        self::constrainYear($query, $year, $connection);
        self::constrainStatus($query, $status);
        self::constrainIdentity($query, $instrumentId, $instrumentTypeId);
        self::constrainSearch($query, $search, $connection);

        if (self::hasCreatedAt($connection)) {
            $query->orderByDesc('calibration.created_at');
        }

        return $query
            ->orderByDesc('calibration.calibration_id')
            ->paginate($perPage)
            ->through(fn (Calibration $calibration): array => self::present($calibration));
    }

    /**
     * @return array{key: string, calibration_id: int, name: string, type: string, serial_number: string, calibration_number: string, session_date: string, status: string, method: string, engineer: string, detail_url: string}
     */
    public static function present(Calibration $calibration): array
    {
        return [
            'key' => (string) $calibration->calibration_id,
            'calibration_id' => (int) $calibration->calibration_id,
            'name' => self::display(self::firstFilled([
                $calibration->getAttribute('snapshot_instrument_name'),
                $calibration->getAttribute('live_instrument_name'),
            ])),
            'type' => self::display(self::firstFilled([
                $calibration->getAttribute('snapshot_instrument_type_name'),
                $calibration->getAttribute('live_type_name'),
            ])),
            'serial_number' => self::display(self::firstFilled([
                $calibration->getAttribute('snapshot_serial_number'),
                $calibration->getAttribute('live_serial_number'),
            ])),
            'calibration_number' => self::display($calibration->getAttribute('calibration_number')),
            'session_date' => self::sessionDate($calibration),
            'status' => self::statusLabel((string) $calibration->session_status),
            'method' => self::display($calibration->getAttribute('calibration_method')),
            'engineer' => self::display($calibration->getAttribute('engineer_username')),
            'detail_url' => route('calibrations.show', $calibration->calibration_id),
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            Calibration::STATUS_IN_PROGRESS => 'In Progress',
            Calibration::STATUS_COMPLETED => 'Completed',
            Calibration::STATUS_CANCELLED => 'Cancelled',
            default => '—',
        };
    }

    /**
     * @param  Builder<Calibration>  $query
     */
    private static function constrainYear(Builder $query, int $year, Connection $connection): void
    {
        $query->where(function (Builder $query) use ($year, $connection): void {
            $query->where(function (Builder $query) use ($year): void {
                $query->where('calibration.session_status', Calibration::STATUS_COMPLETED)
                    ->whereNotNull('calibration.calibration_date')
                    ->whereYear('calibration.calibration_date', $year);
            });

            if (self::hasCreatedAt($connection)) {
                $query->orWhere(function (Builder $query) use ($year): void {
                    $query->where('calibration.session_status', Calibration::STATUS_COMPLETED)
                        ->whereNull('calibration.calibration_date')
                        ->whereYear('calibration.created_at', $year);
                })->orWhere(function (Builder $query) use ($year): void {
                    $query->whereIn('calibration.session_status', [
                        Calibration::STATUS_IN_PROGRESS,
                        Calibration::STATUS_CANCELLED,
                    ])->whereYear('calibration.created_at', $year);
                });
            }
        });
    }

    /**
     * @param  Builder<Calibration>  $query
     */
    private static function constrainStatus(Builder $query, string $status): void
    {
        if ($status === self::STATUS_ALL) {
            return;
        }

        $query->where('calibration.session_status', $status);
    }

    /**
     * @param  Builder<Calibration>  $query
     */
    private static function constrainIdentity(Builder $query, ?int $instrumentId, ?int $instrumentTypeId): void
    {
        if ($instrumentId === null && $instrumentTypeId === null) {
            return;
        }

        if ($instrumentId === null || $instrumentTypeId === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->where('calibration.instrument_id', $instrumentId)
            ->where('calibration.instrument_type_id', $instrumentTypeId);
    }

    /**
     * @param  Builder<Calibration>  $query
     */
    private static function constrainSearch(Builder $query, string $search, Connection $connection): void
    {
        $needle = mb_substr(trim($search), 0, 100);

        if ($needle === '') {
            return;
        }

        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $pattern = '%'.addcslashes($needle, '\\%_').'%';
        $columns = [
            'instrument.instrument_name',
            'instrument.serial_number',
            'instrument_type.brand',
            'instrument_type.type_name',
            'instrument_type.model',
            'instrument_type.serial_number',
        ];

        if (self::hasColumn($connection, 'snapshot_instrument_name')) {
            $columns[] = 'calibration.snapshot_instrument_name';
        }

        if (self::hasColumn($connection, 'snapshot_instrument_type_name')) {
            $columns[] = 'calibration.snapshot_instrument_type_name';
        }

        if (self::hasColumn($connection, 'snapshot_serial_number')) {
            $columns[] = 'calibration.snapshot_serial_number';
        }

        if (self::hasColumn($connection, 'calibration_number')) {
            $columns[] = 'calibration.calibration_number';
        }

        $query->where(function (Builder $query) use ($like, $pattern, $columns): void {
            foreach ($columns as $index => $column) {
                $sql = "{$column} {$like} ? escape '\\'";

                if ($index === 0) {
                    $query->whereRaw($sql, [$pattern]);
                } else {
                    $query->orWhereRaw($sql, [$pattern]);
                }
            }
        });
    }

    private static function sessionDate(Calibration $calibration): string
    {
        if ($calibration->session_status === Calibration::STATUS_COMPLETED && $calibration->calibration_date !== null) {
            return $calibration->calibration_date->format('Y-m-d');
        }

        $createdAt = $calibration->getAttribute('created_at');

        if ($createdAt instanceof Carbon) {
            return $createdAt->toDateString();
        }

        return '—';
    }

    private static function latestSessionYear(Connection $connection): ?int
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
            $open = self::calibrations($connection)
                ->where(function (Builder $query): void {
                    $query->whereIn('calibration.session_status', [
                        Calibration::STATUS_IN_PROGRESS,
                        Calibration::STATUS_CANCELLED,
                    ])->orWhere(function (Builder $query): void {
                        $query->where('calibration.session_status', Calibration::STATUS_COMPLETED)
                            ->whereNull('calibration.calibration_date');
                    });
                })
                ->max('calibration.created_at');

            if (is_string($open) && $open !== '') {
                $years[] = (int) Carbon::parse($open)->year;
            }
        }

        $years = array_values(array_filter(
            $years,
            fn (int $year): bool => $year >= CalibrationAnnualOverview::MINIMUM_YEAR,
        ));

        return $years === [] ? null : max($years);
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function firstFilled(array $values): mixed
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function display(mixed $value): string
    {
        return filled($value) ? (string) $value : '—';
    }

    private static function hasCreatedAt(Connection $connection): bool
    {
        return self::hasColumn($connection, 'created_at');
    }

    private static function hasAppUser(Connection $connection): bool
    {
        return Schema::connection(self::connectionName($connection))->hasTable('app_user')
            && self::hasColumn($connection, 'started_by_app_user_id');
    }

    private static function hasColumn(Connection $connection, string $column): bool
    {
        return Schema::connection(self::connectionName($connection))
            ->hasColumn((new Calibration)->getTable(), $column);
    }

    /**
     * @return Builder<Calibration>
     */
    private static function calibrations(Connection $connection): Builder
    {
        return Calibration::on(self::connectionName($connection));
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
