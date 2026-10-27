<?php

namespace App\StagingSqlDeployment;

final class AtomicStagingSqlPackageBuilder
{
    /**
     * @param  list<array{relative_path: string, sha256: string}>|null  $artifacts
     */
    public function __construct(
        private string $repositoryRoot,
        private GitRevisionReader $gitRevisionReader,
        private StagingSqlScriptParser $parser = new StagingSqlScriptParser,
        private ?array $artifacts = null,
        private ?string $requiredHead = null,
    ) {}

    public function build(): AtomicStagingSqlPackage
    {
        $requiredHead = $this->requiredHead ?? ApprovedStagingSqlArtifacts::APPROVED_HEAD;
        $resolvedHead = $this->gitRevisionReader->head();

        if ($resolvedHead !== $requiredHead) {
            throw new StagingSqlDeploymentException(
                'Git HEAD does not match the approved release for staging SQL deployment.'
            );
        }

        $descriptors = $this->artifacts ?? ApprovedStagingSqlArtifacts::ordered();

        if (count($descriptors) !== 3) {
            throw new StagingSqlDeploymentException('Unexpected staging SQL artifact count.');
        }

        $built = [];
        $bodies = [];

        foreach ($descriptors as $index => $descriptor) {
            $relative = $descriptor['relative_path'];
            $expectedHash = strtolower($descriptor['sha256']);
            $absolute = $this->resolveArtifactPath($relative);

            if (! is_file($absolute)) {
                throw new StagingSqlDeploymentException('Approved SQL artifact is missing: '.$relative);
            }

            $contents = file_get_contents($absolute);

            if ($contents === false) {
                throw new StagingSqlDeploymentException('Unable to read SQL artifact: '.$relative);
            }

            $actualHash = hash('sha256', $contents);

            if ($actualHash !== $expectedHash) {
                throw new StagingSqlDeploymentException('SQL artifact hash mismatch: '.$relative);
            }

            if ($this->artifacts === null) {
                $expectedOrder = ApprovedStagingSqlArtifacts::ordered()[$index]['relative_path'] ?? null;
                if ($relative !== $expectedOrder) {
                    throw new StagingSqlDeploymentException('Wrong staging SQL artifact order.');
                }
            }

            $body = $this->parser->stripOuterTransactionWrapper($contents);
            $built[] = [
                'relative_path' => $relative,
                'source_sha256' => $actualHash,
                'body_sql' => $body,
                'body_sha256' => hash('sha256', $body),
            ];
            $bodies[] = $body;
        }

        $combined = implode("\n\n", $bodies);

        return new AtomicStagingSqlPackage(
            approvedHead: $requiredHead,
            resolvedHead: $resolvedHead,
            artifacts: $built,
            combinedBodySql: $combined,
            combinedBodySha256: hash('sha256', $combined),
        );
    }

    private function resolveArtifactPath(string $relativeOrAbsolute): string
    {
        if ($this->isAbsolutePath($relativeOrAbsolute)) {
            return $relativeOrAbsolute;
        }

        return $this->repositoryRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeOrAbsolute);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return strlen($path) > 2 && \ctype_alnum($path[0]) && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/');
    }
}
