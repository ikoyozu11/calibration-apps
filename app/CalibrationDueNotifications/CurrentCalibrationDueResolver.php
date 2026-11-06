<?php

namespace App\CalibrationDueNotifications;

use App\CalibrationSessions\CalibrationSessionConnection;
use App\Models\Calibration;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;

final class CurrentCalibrationDueResolver
{
    public function __construct(private CalibrationSessionConnection $sessionConnection) {}

    /**
     * @return Collection<int, object>
     */
    public function resolve(Connection $connection): Collection
    {
        $connection = $this->sessionConnection->resolve($connection);
        $completed = $connection
            ->table('calibration')
            ->where('session_status', Calibration::STATUS_COMPLETED)
            ->select('calibration.*');

        $ranked = $connection
            ->query()
            ->fromSub($completed, 'completed')
            ->select('completed.*')
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY completed.instrument_id, completed.instrument_type_id '
                .'ORDER BY CASE WHEN completed.calibration_date IS NULL THEN 1 ELSE 0 END, '
                .'completed.calibration_date DESC, '
                .'CASE WHEN completed.completed_at IS NULL THEN 1 ELSE 0 END, '
                .'completed.completed_at DESC, completed.calibration_id DESC) AS current_rank'
            );

        return $connection
            ->query()
            ->from('instrument_type as it')
            ->join('instrument as i', 'i.instrument_id', '=', 'it.instrument_id')
            ->leftJoin('detail_location as dl', 'dl.detail_location_id', '=', 'i.detail_location_id')
            ->leftJoin('location as l', 'l.location_id', '=', 'dl.location_id')
            ->leftJoinSub($ranked, 'cr', function ($join): void {
                $join->on('cr.instrument_id', '=', 'it.instrument_id')
                    ->on('cr.instrument_type_id', '=', 'it.instrument_type_id')
                    ->where('cr.current_rank', 1);
            })
            ->select([
                'i.instrument_id',
                'it.instrument_type_id',
                'cr.calibration_id as latest_calibration_id',
                'cr.calibration_method',
                'cr.calibration_due',
                'i.instrument_name',
                'it.type_name as instrument_type',
                'i.serial_number',
                'l.location_name',
            ])
            ->orderBy('i.instrument_name')
            ->orderBy('it.instrument_type_id')
            ->get();
    }
}
