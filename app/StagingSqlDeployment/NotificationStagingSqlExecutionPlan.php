<?php

namespace App\StagingSqlDeployment;

/**
 * Immutable execution view of the approved notification SQL artifact.
 *
 * The source artifact remains unchanged. Only its verified outer transaction
 * wrapper is removed from the in-memory execution view.
 */
final class NotificationStagingSqlExecutionPlan
{
    /**
     * @param  list<string>  $statements
     */
    private function __construct(
        public readonly NotificationStagingSqlPackage $package,
        public readonly string $bodySql,
        public readonly array $statements,
    ) {}

    public static function fromPackage(
        NotificationStagingSqlPackage $package,
        StagingSqlScriptParser $parser = new StagingSqlScriptParser,
    ): self {
        if ($package->artifactPath !== NotificationStagingSqlPackageBuilder::ARTIFACT_PATH
            || $package->targetDatabase !== ApprovedStagingSqlArtifacts::APPROVED_DATABASE
            || $package->targetHost !== '127.0.0.1'
            || $package->targetPort !== ApprovedStagingSqlArtifacts::APPROVED_PORT) {
            throw new StagingSqlDeploymentException('Notification execution plan target or artifact is not approved.');
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $package->artifactSha256)) {
            throw new StagingSqlDeploymentException('Notification execution plan checksum is malformed.');
        }

        $actualChecksum = hash('sha256', $package->sql);
        if (! hash_equals($package->artifactSha256, $actualChecksum)) {
            throw new StagingSqlDeploymentException('Notification execution plan checksum mismatch.');
        }

        $expectedRelease = NotificationStagingSqlPackageBuilder::RELEASE_ID_PREFIX.$actualChecksum;
        if ($package->releaseIdentity !== $expectedRelease) {
            throw new StagingSqlDeploymentException('Notification execution plan release identity mismatch.');
        }

        $bodySql = $parser->stripOuterTransactionWrapper($package->sql);
        $statements = $parser->splitStatements($bodySql);
        if ($statements === []) {
            throw new StagingSqlDeploymentException('Notification execution plan body is empty.');
        }

        foreach ($statements as $statement) {
            $parser->assertBodyStatementAllowed($statement);
        }

        return new self($package, $bodySql, $statements);
    }
}
