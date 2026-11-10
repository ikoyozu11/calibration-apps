<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use Illuminate\Database\Connection;

/**
 * Soft-removes the active vendor PDF while preserving its file and history.
 *
 * The parent calibration is locked before the document row. Actor resolution
 * happens inside the same transaction so the removal retains its app_user id.
 */
class RemoveExternalCalibrationDocument
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
        private ResolveDomainActor $actors,
    ) {}

    /**
     * @throws RemoveExternalCalibrationDocumentException
     * @throws ResolveDomainActorException
     * @throws CalibrationSessionConnectionException
     */
    public function remove(
        int $calibrationId,
        int $documentId,
        int $laravelUserId,
        Connection $connection,
    ): void {
        $connection = $this->sessionConnection->resolve($connection);

        if ($calibrationId < 1) {
            throw RemoveExternalCalibrationDocumentException::invalidCalibrationId();
        }

        if ($documentId < 1) {
            throw RemoveExternalCalibrationDocumentException::invalidDocumentId();
        }

        $connection->transaction(function () use ($connection, $calibrationId, $documentId, $laravelUserId): void {
            $calibration = $connection->table('calibration')
                ->from('calibration as c')
                ->where('c.calibration_id', $calibrationId)
                ->lockForUpdate()
                ->first();

            if ($calibration === null) {
                throw RemoveExternalCalibrationDocumentException::missingCalibration();
            }

            if ((string) $calibration->calibration_method !== Calibration::METHOD_EXTERNAL) {
                throw RemoveExternalCalibrationDocumentException::invalidMethod();
            }

            if ((string) $calibration->session_status !== Calibration::STATUS_IN_PROGRESS) {
                throw RemoveExternalCalibrationDocumentException::invalidSessionStatus();
            }

            $document = $connection->table('calibration_external_document')
                ->where('document_id', $documentId)
                ->where('calibration_id', $calibrationId)
                ->lockForUpdate()
                ->first();

            if ($document === null) {
                $documentExists = $connection->table('calibration_external_document')
                    ->where('document_id', $documentId)
                    ->exists();

                throw $documentExists
                    ? RemoveExternalCalibrationDocumentException::foreignSessionDocument()
                    : RemoveExternalCalibrationDocumentException::missingDocument();
            }

            if ($document->removed_at !== null) {
                throw RemoveExternalCalibrationDocumentException::alreadyRemoved();
            }

            if ($document->superseded_at !== null) {
                throw RemoveExternalCalibrationDocumentException::supersededDocument();
            }

            if (! $this->isAffirmative($document->is_active)) {
                throw RemoveExternalCalibrationDocumentException::invalidDocumentState();
            }

            $appUserId = $this->actors->resolve($laravelUserId, $connection);
            $updated = $connection->table('calibration_external_document')
                ->where('document_id', $documentId)
                ->where('calibration_id', $calibrationId)
                ->where('is_active', true)
                ->whereNull('superseded_at')
                ->whereNull('removed_at')
                ->update([
                    'is_active' => false,
                    'removed_at' => now(),
                    'removed_by_user_id' => $appUserId,
                ]);

            if ($updated !== 1) {
                throw RemoveExternalCalibrationDocumentException::concurrentRemoval();
            }
        });
    }

    private function isAffirmative(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
