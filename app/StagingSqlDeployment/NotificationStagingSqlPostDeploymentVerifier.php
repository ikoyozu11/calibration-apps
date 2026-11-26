<?php

namespace App\StagingSqlDeployment;

/**
 * Verifies the installed notification schema without changing it.
 */
final class NotificationStagingSqlPostDeploymentVerifier
{
    public function __construct(
        private NotificationStagingSqlSchemaInspection $inspection,
    ) {}

    /**
     * @var array<string, array{data_type: string, is_nullable: bool}>
     */
    private const EXPECTED_COLUMNS = [
        'calibration_notification_id' => ['data_type' => 'bigint', 'is_nullable' => false],
        'recipient_user_id' => ['data_type' => 'bigint', 'is_nullable' => false],
        'instrument_id' => ['data_type' => 'bigint', 'is_nullable' => true],
        'instrument_type_id' => ['data_type' => 'bigint', 'is_nullable' => true],
        'source_calibration_id' => ['data_type' => 'bigint', 'is_nullable' => true],
        'event_type' => ['data_type' => 'character varying', 'is_nullable' => false],
        'event_key' => ['data_type' => 'character varying', 'is_nullable' => false],
        'occurrence_revision' => ['data_type' => 'smallint', 'is_nullable' => false],
        'instrument_name_snapshot' => ['data_type' => 'text', 'is_nullable' => false],
        'instrument_type_snapshot' => ['data_type' => 'text', 'is_nullable' => false],
        'serial_number_snapshot' => ['data_type' => 'text', 'is_nullable' => true],
        'due_date_snapshot' => ['data_type' => 'date', 'is_nullable' => false],
        'read_at' => ['data_type' => 'timestamp with time zone', 'is_nullable' => true],
        'resolved_at' => ['data_type' => 'timestamp with time zone', 'is_nullable' => true],
        'resolution_calibration_id' => ['data_type' => 'bigint', 'is_nullable' => true],
        'created_at' => ['data_type' => 'timestamp with time zone', 'is_nullable' => false],
        'updated_at' => ['data_type' => 'timestamp with time zone', 'is_nullable' => false],
    ];

    /** @var list<string> */
    private const EXPECTED_FOREIGN_KEYS = [
        'fk_calibration_notifications_recipient',
        'fk_calibration_notifications_instrument',
        'fk_calibration_notifications_instrument_type',
        'fk_calibration_notifications_source_calibration',
        'fk_calibration_notifications_resolution_calibration',
    ];

    /** @var list<string> */
    private const EXPECTED_CHECKS = [
        'chk_calibration_notifications_event_type',
        'chk_calibration_notifications_event_key',
        'chk_calibration_notifications_occurrence_revision',
        'chk_calibration_notifications_instrument_name',
        'chk_calibration_notifications_type_name',
    ];

    /** @var list<string> */
    private const EXPECTED_UNIQUES = ['uq_calibration_notifications_recipient_event'];

    /** @var list<string> */
    private const EXPECTED_INDEXES = [
        'ix_calibration_notifications_recipient_active_read',
        'ix_calibration_notifications_recipient_created',
        'ix_calibration_notifications_pair_event',
        'ix_calibration_notifications_source_calibration',
        'ix_calibration_notifications_recipient_event_revision',
    ];

    public function assertReady(
        NotificationStagingSqlPackage $package,
    ): void {
        if ($this->inspection->database !== $package->targetDatabase
            || $this->inspection->host !== $package->targetHost
            || $this->inspection->port !== $package->targetPort) {
            throw new StagingSqlDeploymentException('Post-deployment database identity or endpoint mismatch.');
        }

        if (! $this->inspection->notificationTableExists || $package->expectedTable !== 'public.calibration_notifications') {
            throw new StagingSqlDeploymentException('Post-deployment notification table is missing.');
        }

        foreach (self::EXPECTED_COLUMNS as $column => $expected) {
            if (($this->inspection->columns[$column] ?? null) !== $expected) {
                throw new StagingSqlDeploymentException('Post-deployment column mismatch: '.$column);
            }
        }

        if (! in_array('calibration_notifications_pkey', $this->inspection->primaryKeys, true)) {
            throw new StagingSqlDeploymentException('Post-deployment primary key is missing.');
        }

        if ($this->inspection->primaryKeyColumns !== ['calibration_notification_id']) {
            throw new StagingSqlDeploymentException('Post-deployment primary key column mismatch.');
        }

        $this->assertContainsAll($this->inspection->foreignKeys, self::EXPECTED_FOREIGN_KEYS, 'foreign key');
        $this->assertContainsAll($this->inspection->checkConstraints, self::EXPECTED_CHECKS, 'CHECK constraint');
        $this->assertContainsAll($this->inspection->uniqueConstraints, self::EXPECTED_UNIQUES, 'unique constraint');
        $this->assertContainsAll($this->inspection->indexes, self::EXPECTED_INDEXES, 'index');

        if (! $this->inspection->identitySequenceExists) {
            throw new StagingSqlDeploymentException('Post-deployment identity sequence is missing.');
        }

        if (($this->inspection->permissions['owner'] ?? false) !== true || $this->inspection->initialRowCount < 0) {
            throw new StagingSqlDeploymentException('Post-deployment ownership or row-count verification failed.');
        }
    }

    /**
     * @param  list<string>  $actual
     * @param  list<string>  $expected
     */
    private function assertContainsAll(array $actual, array $expected, string $kind): void
    {
        foreach ($expected as $name) {
            if (! in_array($name, $actual, true)) {
                throw new StagingSqlDeploymentException('Post-deployment '.$kind.' missing: '.$name);
            }
        }
    }
}
