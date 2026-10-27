<?php

namespace App\StagingSqlDeployment;

final class StagingSqlBaselineVerifier
{
    public function __construct(
        private StagingSqlDeploymentGateValidator $gates = new StagingSqlDeploymentGateValidator,
    ) {}

    public function assertReadyForDdl(StagingSqlSession $session, StagingSqlDeploymentTarget $target): void
    {
        $identity = $session->identitySnapshot();

        if ($identity->currentDatabase !== ApprovedStagingSqlArtifacts::APPROVED_DATABASE) {
            throw new StagingSqlDeploymentException('Live database identity is not calibration_staging_db.');
        }

        if ($identity->serverPort !== ApprovedStagingSqlArtifacts::APPROVED_PORT) {
            throw new StagingSqlDeploymentException('Live server port is not approved.');
        }

        $addr = $identity->serverAddress;
        if (! str_starts_with($addr, '127.0.0.1') && ! str_starts_with($addr, '::1')) {
            throw new StagingSqlDeploymentException('Live server address is not the approved loopback endpoint.');
        }

        $this->gates->assertTargetAllowed($target);

        foreach (['public.users', 'public.app_user', 'public.user_app_user_map', 'public.calibration'] as $relation) {
            if (! $session->relationExists($relation)) {
                throw new StagingSqlDeploymentException('Baseline relation missing: '.$relation);
            }
        }

        foreach (['public.calibration_session_setting', 'public.calibration_requirement_snapshot', 'public.calibration_environment_value'] as $relation) {
            if (! $session->relationExists($relation)) {
                throw new StagingSqlDeploymentException('Session foundation/CRUD relation missing: '.$relation);
            }
        }

        if ($session->relationExists('public.roles') || $session->relationExists('public.user_roles')) {
            throw new StagingSqlDeploymentException('Baseline drift: roles tables unexpectedly present.');
        }

        if ($session->columnMetadata('public', 'calibration', 'started_by_app_user_id') !== null) {
            throw new StagingSqlDeploymentException('Baseline drift: started_by_app_user_id unexpectedly present.');
        }

        if ($session->columnMetadata('public', 'calibration', 'session_status') === null) {
            throw new StagingSqlDeploymentException('Baseline drift: session_status missing.');
        }

        $appUserId = $session->columnMetadata('public', 'app_user', 'app_user_id');
        if ($appUserId === null || $appUserId['data_type'] !== 'bigint') {
            throw new StagingSqlDeploymentException('Baseline drift: app_user.app_user_id is not bigint.');
        }

        $fingerprint = $session->functionFingerprint('public', 'trg_calibration_session_guard');
        if ($fingerprint === null) {
            throw new StagingSqlDeploymentException('Baseline guard function missing.');
        }

        $this->gates->assertBaselineTriggerFingerprint(
            ApprovedStagingSqlArtifacts::APPROVED_BASELINE_TRIGGER_MD5,
            $fingerprint['md5'],
            ApprovedStagingSqlArtifacts::APPROVED_BASELINE_TRIGGER_LENGTH,
            $fingerprint['length'],
        );

        $definition = $fingerprint['definition'];
        foreach ([
            'calibration sessions cannot be deleted',
            'calibration method is immutable after start',
            'calibration_session_assert_complete',
            'cancellation requires a reason and timestamp',
        ] as $marker) {
            if (! str_contains($definition, $marker)) {
                throw new StagingSqlDeploymentException('Baseline guard marker missing: '.$marker);
            }
        }

        if (str_contains($definition, 'calibration session owner is immutable after insert')) {
            throw new StagingSqlDeploymentException('Baseline drift: owner immutability unexpectedly present.');
        }

        $triggers = $session->triggersOnTable('public', 'calibration', 'trg_calibration_session_guard');
        if (count($triggers) !== 1) {
            throw new StagingSqlDeploymentException('Expected exactly one trg_calibration_session_guard trigger.');
        }
        if (($triggers[0]['enabled'] ?? '') !== 'O') {
            throw new StagingSqlDeploymentException('Baseline guard trigger is not enabled.');
        }
    }
}
