<?php

namespace App\StagingSqlDeployment;

/**
 * In-memory atomic deployment package. Original SQL files are never rewritten.
 *
 * Live execution must consume {@see orderedBodySql()} separately inside one
 * explicit transaction: BEGIN → baseline checks → bodies → post-verify → COMMIT.
 * This package never emits a preassembled script that includes COMMIT.
 *
 * @phpstan-type ArtifactBody array{
 *     relative_path: string,
 *     source_sha256: string,
 *     body_sql: string,
 *     body_sha256: string
 * }
 */
final class AtomicStagingSqlPackage
{
    /**
     * @param  list<ArtifactBody>  $artifacts
     */
    public function __construct(
        public readonly string $approvedHead,
        public readonly string $resolvedHead,
        public readonly array $artifacts,
        public readonly string $combinedBodySql,
        public readonly string $combinedBodySha256,
    ) {
        if (count($this->artifacts) !== 3) {
            throw new StagingSqlDeploymentException('Atomic package must contain exactly three artifacts.');
        }
    }

    /**
     * Ordered SQL bodies with outer BEGIN/COMMIT wrappers already removed.
     *
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
