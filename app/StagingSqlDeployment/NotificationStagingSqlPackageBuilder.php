<?php

namespace App\StagingSqlDeployment;

/**
 * Builds and validates the release-specific notification SQL package offline.
 */
final class NotificationStagingSqlPackageBuilder
{
    public const ARTIFACT_PATH = 'database/sql/calibration_notifications.sql';

    public const EXPECTED_ARTIFACT_SHA256 = '731f68df17d1ca18fcdd75395d67e865b669bf463c18982053d5bf1a81d8f9bc';

    public const EXPECTED_TABLE = 'public.calibration_notifications';

    public const RELEASE_ID_PREFIX = 'calibration-notifications-schema-v1:sha256:';

    /**
     * @param  list<string>|null  $requiredTables
     */
    public function __construct(
        private string $repositoryRoot,
        private string $expectedArtifactSha256 = self::EXPECTED_ARTIFACT_SHA256,
        private ?array $requiredTables = null,
    ) {}

    public function build(string $artifactPath = self::ARTIFACT_PATH): NotificationStagingSqlPackage
    {
        if ($artifactPath !== self::ARTIFACT_PATH) {
            throw new StagingSqlDeploymentException('Unknown notification SQL artifact rejected.');
        }

        $absolutePath = $this->repositoryRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $artifactPath);
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new StagingSqlDeploymentException('Notification SQL artifact is missing or unreadable.');
        }

        $sql = file_get_contents($absolutePath);
        if ($sql === false) {
            throw new StagingSqlDeploymentException('Notification SQL artifact could not be read.');
        }

        $checksum = hash('sha256', $sql);
        if (! hash_equals(strtolower($this->expectedArtifactSha256), $checksum)) {
            throw new StagingSqlDeploymentException('Notification SQL artifact checksum mismatch.');
        }

        $this->assertCanonicalSql($sql);

        return new NotificationStagingSqlPackage(
            artifactPath: self::ARTIFACT_PATH,
            artifactSha256: $checksum,
            releaseIdentity: self::RELEASE_ID_PREFIX.$checksum,
            targetDatabase: ApprovedStagingSqlArtifacts::APPROVED_DATABASE,
            targetHost: '127.0.0.1',
            targetPort: ApprovedStagingSqlArtifacts::APPROVED_PORT,
            sql: $sql,
            requiredTables: $this->requiredTables ?? [
                'public.users',
                'public.instrument',
                'public.instrument_type',
                'public.calibration',
            ],
            expectedTable: self::EXPECTED_TABLE,
        );
    }

    private function assertCanonicalSql(string $sql): void
    {
        $requiredFragments = [
            "current_database() IS DISTINCT FROM 'calibration_staging_db'",
            "to_regclass('public.users')",
            "to_regclass('public.instrument')",
            "to_regclass('public.instrument_type')",
            "to_regclass('public.calibration')",
            "to_regclass('public.calibration_notifications')",
            'BEGIN;',
            'COMMIT;',
            'CREATE TABLE public.calibration_notifications',
            "CHECK (event_type IN ('due_soon', 'overdue'))",
            'CHECK (length(trim(event_key)) > 0)',
            'CHECK (occurrence_revision > 0)',
            'CHECK (length(trim(instrument_name_snapshot)) > 0)',
            'CHECK (length(trim(instrument_type_snapshot)) > 0)',
            'UNIQUE (recipient_user_id, event_key, occurrence_revision)',
            'REFERENCES public.users (id)',
            'REFERENCES public.instrument (instrument_id)',
            'REFERENCES public.instrument_type (instrument_type_id)',
            'REFERENCES public.calibration (calibration_id)',
            'CREATE INDEX ix_calibration_notifications_recipient_active_read',
            'CREATE INDEX ix_calibration_notifications_recipient_created',
            'CREATE INDEX ix_calibration_notifications_pair_event',
            'CREATE INDEX ix_calibration_notifications_source_calibration',
            'CREATE INDEX ix_calibration_notifications_recipient_event_revision',
        ];

        foreach ($requiredFragments as $fragment) {
            if (! str_contains($sql, $fragment)) {
                throw new StagingSqlDeploymentException('Notification SQL canonical safety contract is incomplete.');
            }
        }

        if (! preg_match('/\bBEGIN\s*;/i', $sql)
            || ! preg_match('/COMMIT\s*;\s*$/i', $sql)) {
            throw new StagingSqlDeploymentException('Notification SQL must retain its outer transaction boundaries.');
        }
    }
}
