<?php

namespace App\StagingSqlDeployment;

final class StagingSqlDeploymentGateValidator
{
    public function assertPackageReady(AtomicStagingSqlPackage $package): void
    {
        if ($package->resolvedHead !== $package->approvedHead) {
            throw new StagingSqlDeploymentException('Package HEAD gate failed.');
        }

        if ($package->resolvedHead !== ApprovedStagingSqlArtifacts::APPROVED_HEAD) {
            throw new StagingSqlDeploymentException('Package HEAD is not the approved release.');
        }

        if (count($package->artifacts) !== 3) {
            throw new StagingSqlDeploymentException('Package artifact count gate failed.');
        }

        foreach (ApprovedStagingSqlArtifacts::ordered() as $index => $expected) {
            $actual = $package->artifacts[$index] ?? null;
            if ($actual === null || $actual['relative_path'] !== $expected['relative_path']) {
                throw new StagingSqlDeploymentException('Wrong staging SQL artifact order.');
            }
            if ($actual['source_sha256'] !== $expected['sha256']) {
                throw new StagingSqlDeploymentException('Package source hash gate failed.');
            }
        }
    }

    public function assertTargetAllowed(StagingSqlDeploymentTarget $target): void
    {
        $host = strtolower($target->host);

        if (! in_array($host, ApprovedStagingSqlArtifacts::APPROVED_HOSTS, true)) {
            throw new StagingSqlDeploymentException('Staging SQL deployment host is not approved.');
        }

        if ($target->port !== ApprovedStagingSqlArtifacts::APPROVED_PORT) {
            throw new StagingSqlDeploymentException('Staging SQL deployment port is not approved.');
        }

        if ($target->database === ApprovedStagingSqlArtifacts::FORBIDDEN_DATABASE_PRODUCTION) {
            throw new StagingSqlDeploymentException('Production database is forbidden.');
        }

        if ($target->database === ApprovedStagingSqlArtifacts::FORBIDDEN_DATABASE_MAINTENANCE) {
            throw new StagingSqlDeploymentException('Maintenance database is forbidden.');
        }

        if ($target->database !== ApprovedStagingSqlArtifacts::APPROVED_DATABASE) {
            throw new StagingSqlDeploymentException('Staging SQL deployment database is not approved.');
        }
    }

    public function assertAuthorization(StagingSqlDeploymentAuthorization $authorization): void
    {
        if (! $authorization->operatorWriteApproved) {
            throw new StagingSqlDeploymentException('Missing explicit write authorization.');
        }

        if (! $authorization->backupBaselineVerified || $authorization->approvedBackupSha256 === '') {
            throw new StagingSqlDeploymentException('Missing verified backup baseline.');
        }

        if ($authorization->approvedBackupSha256 !== ApprovedStagingSqlArtifacts::APPROVED_BACKUP_SHA256) {
            throw new StagingSqlDeploymentException('Backup hash mismatch.');
        }

        if (! $authorization->freshSchemaInventoryVerified) {
            throw new StagingSqlDeploymentException('Missing fresh schema inventory verification.');
        }

        if (! $authorization->freshTriggerFingerprintVerified) {
            throw new StagingSqlDeploymentException('Missing fresh trigger fingerprint verification.');
        }

        if (! $authorization->operatorConfirmedExecution) {
            throw new StagingSqlDeploymentException('Missing explicit operator execution confirmation.');
        }

        if ($authorization->approvedCheckpoint !== StagingSqlDeploymentAuthorization::APPROVED_CHECKPOINT) {
            throw new StagingSqlDeploymentException('Authorization checkpoint mismatch.');
        }

        if ($authorization->approvedDatabase !== ApprovedStagingSqlArtifacts::APPROVED_DATABASE) {
            throw new StagingSqlDeploymentException('Authorization database mismatch.');
        }

        if (! in_array(strtolower($authorization->approvedHost), ApprovedStagingSqlArtifacts::APPROVED_HOSTS, true)) {
            throw new StagingSqlDeploymentException('Authorization host mismatch.');
        }

        if ($authorization->approvedPort !== ApprovedStagingSqlArtifacts::APPROVED_PORT) {
            throw new StagingSqlDeploymentException('Authorization port mismatch.');
        }

        if ($authorization->approvedReleaseHead !== ApprovedStagingSqlArtifacts::APPROVED_HEAD) {
            throw new StagingSqlDeploymentException('Authorization release HEAD mismatch.');
        }

        $expectedHashes = array_column(ApprovedStagingSqlArtifacts::ordered(), 'sha256');
        if ($authorization->approvedArtifactSha256 !== $expectedHashes) {
            throw new StagingSqlDeploymentException('Authorization artifact hash set mismatch.');
        }
    }

    public function assertAuthorizationMatchesTarget(
        StagingSqlDeploymentAuthorization $authorization,
        StagingSqlDeploymentTarget $target,
    ): void {
        $authHost = strtolower($authorization->approvedHost);
        $targetHost = strtolower($target->host);
        $hostsCompatible = $authHost === $targetHost
            || ($authHost === '127.0.0.1' && $targetHost === 'localhost')
            || ($authHost === 'localhost' && $targetHost === '127.0.0.1');

        if (! $hostsCompatible) {
            throw new StagingSqlDeploymentException('Authorization host does not match deployment target.');
        }

        if ($authorization->approvedPort !== $target->port) {
            throw new StagingSqlDeploymentException('Authorization port does not match deployment target.');
        }

        if ($authorization->approvedDatabase !== $target->database) {
            throw new StagingSqlDeploymentException('Authorization database does not match deployment target.');
        }
    }

    public function assertNoDefaultConnectionFallback(bool $usingDefaultConnection): void
    {
        if ($usingDefaultConnection) {
            throw new StagingSqlDeploymentException('Default database connection fallback is forbidden.');
        }
    }

    /**
     * Fresh live fingerprint must match the approved baseline before DDL.
     * Matching MD5 alone is not semantic proof; callers must also assert markers.
     */
    public function assertBaselineTriggerFingerprint(string $expectedMd5, string $actualMd5, int $expectedLength, int $actualLength): void
    {
        if ($expectedMd5 === '' || $actualMd5 === '') {
            throw new StagingSqlDeploymentException('Baseline trigger fingerprint is missing.');
        }

        if (! hash_equals(strtolower($expectedMd5), strtolower($actualMd5))) {
            throw new StagingSqlDeploymentException('Baseline trigger fingerprint drift detected.');
        }

        if ($expectedLength !== $actualLength) {
            throw new StagingSqlDeploymentException('Baseline trigger definition length drift detected.');
        }
    }

    /**
     * @param  list<string>  $bodies
     */
    public function assertBodiesContainNoTopLevelCommit(array $bodies, StagingSqlScriptParser $parser = new StagingSqlScriptParser): void
    {
        foreach ($bodies as $index => $body) {
            foreach ($parser->splitStatements($body) as $statement) {
                $normalized = $parser->normalizeCommand($statement);
                if ($normalized === 'COMMIT' || str_starts_with($normalized, 'COMMIT ')) {
                    throw new StagingSqlDeploymentException(
                        'Ordered SQL body #'.($index + 1).' contains top-level COMMIT.'
                    );
                }
                if ($normalized === 'BEGIN' || str_starts_with($normalized, 'BEGIN ')) {
                    throw new StagingSqlDeploymentException(
                        'Ordered SQL body #'.($index + 1).' contains top-level BEGIN.'
                    );
                }
            }
        }
    }
}
