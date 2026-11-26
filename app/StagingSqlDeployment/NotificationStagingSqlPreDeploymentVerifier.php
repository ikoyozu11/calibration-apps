<?php

namespace App\StagingSqlDeployment;

/**
 * Verifies only conditions that must hold before notification DDL executes.
 */
final class NotificationStagingSqlPreDeploymentVerifier implements NotificationStagingSqlSchemaPreflight
{
    public function __construct(
        private NotificationStagingSqlSchemaInspection $inspection,
    ) {}

    public function assertReady(
        NotificationStagingSqlPackage $package,
        StagingSqlDeploymentTarget $target,
    ): void {
        if ($this->inspection->database !== $package->targetDatabase || $this->inspection->database !== $target->database) {
            throw new StagingSqlDeploymentException('Pre-deployment database identity mismatch.');
        }

        if ($this->inspection->host !== $package->targetHost || $this->inspection->port !== $package->targetPort
            || $this->inspection->host !== $target->host || $this->inspection->port !== $target->port) {
            throw new StagingSqlDeploymentException('Pre-deployment endpoint mismatch.');
        }

        foreach ($package->requiredTables as $relation) {
            if (! in_array($relation, $this->inspection->relations, true)) {
                throw new StagingSqlDeploymentException('Required prerequisite is missing: '.$relation);
            }
        }

        foreach ([
            'public.users.id' => 'bigint',
            'public.instrument.instrument_id' => 'bigint',
            'public.instrument_type.instrument_type_id' => 'bigint',
            'public.calibration.calibration_id' => 'bigint',
        ] as $key => $type) {
            if (($this->inspection->keyTypes[$key] ?? null) !== $type) {
                throw new StagingSqlDeploymentException('Required prerequisite key type mismatch: '.$key);
            }
        }

        if ($this->inspection->notificationTableExists) {
            throw new StagingSqlDeploymentException('Notification table already exists before deployment.');
        }

        if (($this->inspection->permissions['connect'] ?? false) !== true
            || ($this->inspection->permissions['create_table'] ?? false) !== true
            || ($this->inspection->permissions['create_index'] ?? false) !== true) {
            throw new StagingSqlDeploymentException('Required notification schema permissions are missing.');
        }
    }
}
