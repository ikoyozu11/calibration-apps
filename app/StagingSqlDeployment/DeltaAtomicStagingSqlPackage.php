<?php

namespace App\StagingSqlDeployment;

/**
 * Atomic package for the notification-history delta release.
 *
 * This is deliberately separate from AtomicStagingSqlPackage so the historical
 * three-artifact release cannot accidentally accept a one-artifact package.
 *
 * @phpstan-type ArtifactBody array{
 *     relative_path: string,
 *     source_sha256: string,
 *     body_sql: string,
 *     body_sha256: string
 * }
 */
final class DeltaAtomicStagingSqlPackage
{
    /**
     * @param  list<ArtifactBody>  $artifacts
     */
    public function __construct(
        public readonly string $releaseId,
        public readonly string $approvedHead,
        public readonly string $resolvedHead,
        public readonly array $artifacts,
        public readonly string $combinedBodySql,
        public readonly string $combinedBodySha256,
    ) {
        if (count($this->artifacts) !== 1) {
            throw new StagingSqlDeploymentException('Delta package must contain exactly one artifact.');
        }
    }

    /**
     * @return list<string>
     */
    public function orderedBodySql(): array
    {
        return array_values(array_map(
            static fn (array $artifact): string => $artifact['body_sql'],
            $this->artifacts,
        ));
    }

    /**
     * @return list<string>
     */
    public function orderedRelativePaths(): array
    {
        return array_values(array_map(
            static fn (array $artifact): string => $artifact['relative_path'],
            $this->artifacts,
        ));
    }
}
