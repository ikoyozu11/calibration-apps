<?php

namespace App\StagingSqlDeployment;

use App\CalibrationSessions\CalibrationSessionConnection;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Explicitly guarded staging-only schema inspection facade.
 */
final class NotificationStagingSqlSchemaInspector
{
    public function __construct(
        private CalibrationSessionConnection $connectionGuard,
        private NotificationStagingSqlReadOnlyMetadataReader $metadataReader = new PostgreSqlNotificationStagingSqlMetadataReader,
    ) {}

    public function inspectPreDeployment(
        Connection $connection,
    ): NotificationStagingSqlSchemaInspection {
        $this->assertApprovedConnection($connection);

        return $this->readMetadata($connection);
    }

    public function inspectPostDeployment(
        Connection $connection,
    ): NotificationStagingSqlSchemaInspection {
        $this->assertApprovedConnection($connection);

        return $this->readMetadata($connection);
    }

    private function readMetadata(Connection $connection): NotificationStagingSqlSchemaInspection
    {
        try {
            return $this->metadataReader->inspect($connection);
        } catch (StagingSqlDeploymentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new StagingSqlDeploymentException('Read-only staging schema inspection failed.', 0, $exception);
        }
    }

    private function assertApprovedConnection(Connection $connection): void
    {
        $this->connectionGuard->assertSafe($connection);

        if ($connection->getDriverName() !== 'pgsql'
            || ! in_array(strtolower((string) $connection->getConfig('host')), ['127.0.0.1', 'localhost'], true)
            || (int) $connection->getConfig('port') !== ApprovedStagingSqlArtifacts::APPROVED_PORT
            || $connection->getConfig('url') !== null
            || $connection->getConfig('database') !== ApprovedStagingSqlArtifacts::APPROVED_DATABASE) {
            throw new StagingSqlDeploymentException('Notification schema inspector requires the approved staging connection.');
        }
    }
}
