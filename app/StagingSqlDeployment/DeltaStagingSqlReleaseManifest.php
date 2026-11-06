<?php

namespace App\StagingSqlDeployment;

final class DeltaStagingSqlReleaseManifest
{
    public const RELEASE_ID = 'calibration-due-notification-history';

    public const ARTIFACT_PATH = 'database/sql/calibration_due_notification_history.sql';

    public static function approvalFromFile(string $path): DeltaStagingSqlReleaseApproval
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new StagingSqlDeploymentException('Delta approval manifest is missing or unreadable.');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new StagingSqlDeploymentException('Unable to read delta approval manifest.');
        }

        try {
            $values = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new StagingSqlDeploymentException('Delta approval manifest is not valid JSON.', 0, $exception);
        }

        if (! is_array($values) || ($values['artifact_path'] ?? null) !== self::ARTIFACT_PATH) {
            throw new StagingSqlDeploymentException('Delta approval manifest artifact path is invalid.');
        }

        if (($values['approved'] ?? false) !== true || ($values['staging_write_authorized'] ?? false) !== true) {
            throw new StagingSqlDeploymentException('Delta approval manifest is not approved for staging write.');
        }

        $approval = DeltaStagingSqlReleaseApproval::fromArray($values);
        $approval->assertComplete();

        return $approval;
    }

    /**
     * @return array{
     *     release_id: string,
     *     artifact: array{relative_path: string, source_sha256: string, body_sha256: string},
     *     approved_head: null,
     *     checkpoint: null,
     *     fresh_backup_sha256: null
     * }
     */
    public static function pending(string $repositoryRoot): array
    {
        $path = $repositoryRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, self::ARTIFACT_PATH);
        if (! is_file($path)) {
            throw new StagingSqlDeploymentException('Delta SQL artifact is missing: '.self::ARTIFACT_PATH);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new StagingSqlDeploymentException('Unable to read delta SQL artifact: '.self::ARTIFACT_PATH);
        }

        $body = (new StagingSqlScriptParser)->stripOuterTransactionWrapper($contents);

        return [
            'release_id' => self::RELEASE_ID,
            'artifact' => [
                'relative_path' => self::ARTIFACT_PATH,
                'source_sha256' => hash('sha256', $contents),
                'body_sha256' => hash('sha256', $body),
            ],
            'approved_head' => null,
            'checkpoint' => null,
            'fresh_backup_sha256' => null,
        ];
    }
}
