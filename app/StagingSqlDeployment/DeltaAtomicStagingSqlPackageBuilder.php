<?php

namespace App\StagingSqlDeployment;

final class DeltaAtomicStagingSqlPackageBuilder
{
    public function __construct(
        private string $repositoryRoot,
        private GitRevisionReader $gitRevisionReader,
        private DeltaStagingSqlReleaseApproval $approval,
        private StagingSqlScriptParser $parser = new StagingSqlScriptParser,
    ) {}

    public function build(): DeltaAtomicStagingSqlPackage
    {
        $this->approval->assertComplete();
        $resolvedHead = $this->gitRevisionReader->head();
        if ($resolvedHead !== $this->approval->approvedHead) {
            throw new StagingSqlDeploymentException('Delta release HEAD does not match the approved release.');
        }

        $relative = DeltaStagingSqlReleaseManifest::ARTIFACT_PATH;
        $absolute = $this->repositoryRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (! is_file($absolute)) {
            throw new StagingSqlDeploymentException('Approved delta SQL artifact is missing: '.$relative);
        }

        $contents = file_get_contents($absolute);
        if ($contents === false) {
            throw new StagingSqlDeploymentException('Unable to read approved delta SQL artifact: '.$relative);
        }

        $sourceHash = hash('sha256', $contents);
        if ($sourceHash !== strtolower($this->approval->artifactSha256)) {
            throw new StagingSqlDeploymentException('Delta SQL artifact hash mismatch.');
        }

        $body = $this->parser->stripOuterTransactionWrapper($contents);
        $bodyHash = hash('sha256', $body);
        if ($bodyHash !== strtolower($this->approval->artifactBodySha256)) {
            throw new StagingSqlDeploymentException('Delta SQL artifact body hash mismatch.');
        }

        $artifact = [[
            'relative_path' => $relative,
            'source_sha256' => $sourceHash,
            'body_sql' => $body,
            'body_sha256' => $bodyHash,
        ]];

        return new DeltaAtomicStagingSqlPackage(
            releaseId: $this->approval->releaseId,
            approvedHead: $this->approval->approvedHead,
            resolvedHead: $resolvedHead,
            artifacts: $artifact,
            combinedBodySql: $body,
            combinedBodySha256: $bodyHash,
        );
    }
}
