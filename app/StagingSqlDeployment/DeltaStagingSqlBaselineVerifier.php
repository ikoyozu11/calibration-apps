<?php

namespace App\StagingSqlDeployment;

final class DeltaStagingSqlBaselineVerifier
{
    public function __construct(
        private DeltaStagingSqlDeploymentGateValidator $gates = new DeltaStagingSqlDeploymentGateValidator,
    ) {}

    public function assertReadyForDdl(StagingSqlSession $session, StagingSqlDeploymentTarget $target): void
    {
        $identity = $session->identitySnapshot();
        if ($identity->currentDatabase !== ApprovedStagingSqlArtifacts::APPROVED_DATABASE
            || $identity->serverPort !== ApprovedStagingSqlArtifacts::APPROVED_PORT
            || ! str_starts_with($identity->serverAddress, '127.0.0.1') && ! str_starts_with($identity->serverAddress, '::1')) {
            throw new StagingSqlDeploymentException('Delta live database identity is not the approved staging endpoint.');
        }

        $this->gates->assertTargetAllowed($target);
        foreach (['public.instrument', 'public.instrument_type', 'public.calibration'] as $relation) {
            if (! $session->relationExists($relation)) {
                throw new StagingSqlDeploymentException('Delta parent relation missing: '.$relation);
            }
        }

        foreach (['public.calibration_due_notification_batches', 'public.calibration_due_notification_items'] as $relation) {
            if ($session->relationExists($relation)) {
                throw new StagingSqlDeploymentException('Delta notification relation already exists: '.$relation);
            }
        }
    }
}
