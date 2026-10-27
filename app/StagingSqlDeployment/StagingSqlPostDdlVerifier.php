<?php

namespace App\StagingSqlDeployment;

final class StagingSqlPostDdlVerifier
{
    public function assertReadyToCommit(StagingSqlSession $session): void
    {
        if ($session instanceof FakeStagingSqlSession && $session->shouldFailPostDdl()) {
            throw new StagingSqlDeploymentException('Post-DDL verification failed.');
        }

        if (! $session->relationExists('public.roles') || ! $session->relationExists('public.user_roles')) {
            throw new StagingSqlDeploymentException('Post-DDL verification failed: roles tables missing.');
        }

        $roles = $session->roleCatalogNames();
        foreach (['Administrator', 'Engineer', 'Viewer'] as $role) {
            if (! in_array($role, $roles, true)) {
                throw new StagingSqlDeploymentException('Post-DDL verification failed: role catalog missing '.$role);
            }
        }

        $owner = $session->columnMetadata('public', 'calibration', 'started_by_app_user_id');
        if ($owner === null) {
            throw new StagingSqlDeploymentException('Post-DDL verification failed: owner column missing.');
        }
        if ($owner['data_type'] !== 'bigint') {
            throw new StagingSqlDeploymentException('Post-DDL verification failed: owner column type is not bigint.');
        }
        if ($owner['is_nullable'] !== 'YES') {
            throw new StagingSqlDeploymentException('Post-DDL verification failed: owner column must be nullable.');
        }

        if ($session->foreignKeyTarget('public', 'calibration', 'started_by_app_user_id') !== 'public.app_user(app_user_id)') {
            throw new StagingSqlDeploymentException('Post-DDL verification failed: owner FK target mismatch.');
        }
        if ($session->foreignKeyDeleteAction('public', 'calibration', 'started_by_app_user_id') !== 'RESTRICT') {
            throw new StagingSqlDeploymentException('Post-DDL verification failed: owner FK delete action must be RESTRICT.');
        }

        $fingerprint = $session->functionFingerprint('public', 'trg_calibration_session_guard');
        if ($fingerprint === null) {
            throw new StagingSqlDeploymentException('Post-DDL verification failed: guard function missing.');
        }

        $definition = $fingerprint['definition'];
        foreach ([
            'calibration sessions cannot be deleted',
            'calibration method is immutable after start',
            'calibration_session_assert_complete',
            'cancellation requires a reason and timestamp',
            'calibration session owner is immutable after insert',
            'started_by_app_user_id IS DISTINCT FROM',
        ] as $marker) {
            if (! str_contains($definition, $marker)) {
                throw new StagingSqlDeploymentException('Post-DDL verification failed: guard marker missing: '.$marker);
            }
        }

        $triggers = $session->triggersOnTable('public', 'calibration', 'trg_calibration_session_guard');
        if (count($triggers) !== 1) {
            throw new StagingSqlDeploymentException('Post-DDL verification failed: unexpected guard trigger count.');
        }
        if (($triggers[0]['enabled'] ?? '') !== 'O') {
            throw new StagingSqlDeploymentException('Post-DDL verification failed: guard trigger disabled.');
        }
    }
}
