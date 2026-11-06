<?php

namespace App\StagingSqlDeployment;

final class DeltaStagingSqlPostDdlVerifier
{
    /**
     * @var array<string, list<string>>
     */
    private const REQUIRED_COLUMNS = [
        'calibration_due_notification_batches' => [
            'id', 'notification_date', 'timezone', 'recipient', 'status', 'attempts',
            'claimed_at', 'sent_at', 'failed_at', 'failure_message', 'created_at', 'updated_at',
        ],
        'calibration_due_notification_items' => [
            'id', 'batch_id', 'instrument_id', 'instrument_type_id', 'latest_calibration_id',
            'calibration_method', 'instrument_name_snapshot', 'instrument_type_snapshot',
            'serial_number_snapshot', 'location_snapshot', 'due_date_snapshot', 'milestone_code',
            'milestone_date', 'recipient', 'status', 'attempts', 'last_attempt_at',
            'failure_message', 'created_at', 'updated_at',
        ],
    ];

    public function assertReadyToCommit(StagingSqlSession $session): void
    {
        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            $relation = 'public.'.$table;
            if (! $session->relationExists($relation)) {
                throw new StagingSqlDeploymentException('Delta post-DDL verification failed: '.$relation.' is missing.');
            }

            foreach ($columns as $column) {
                if ($session->columnMetadata('public', $table, $column) === null) {
                    throw new StagingSqlDeploymentException('Delta post-DDL verification failed: '.$relation.'.'.$column.' is missing.');
                }
            }
        }
    }
}
