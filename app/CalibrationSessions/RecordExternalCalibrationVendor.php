<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * Records the optional EXTERNAL vendor name snapshot on one calibration session.
 *
 * Null, empty, and whitespace-only values persist as null. Nonempty values are
 * trimmed. The snapshot is session-owned and is not a provider FK, document
 * field, or Complete precondition. This service does not call auth(),
 * Model::on(), or the default connection. SQLite compiles lockForUpdate()
 * without a real row lock.
 */
class RecordExternalCalibrationVendor
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    /**
     * @throws RecordExternalCalibrationVendorException
     * @throws CalibrationSessionConnectionException
     * @throws QueryException
     */
    public function record(
        int $calibrationId,
        ?string $vendorName,
        Connection $connection,
    ): void {
        $connection = $this->sessionConnection->resolve($connection);

        if ($calibrationId < 1) {
            throw RecordExternalCalibrationVendorException::invalidCalibrationId();
        }

        $vendorName = $this->normalize($vendorName);

        $connection->transaction(function () use ($connection, $calibrationId, $vendorName): void {
            $calibration = $connection->table('calibration')
                ->where('calibration_id', $calibrationId)
                ->lockForUpdate()
                ->first();

            if ($calibration === null) {
                throw RecordExternalCalibrationVendorException::missingCalibration();
            }

            if ((string) $calibration->calibration_method !== Calibration::METHOD_EXTERNAL) {
                throw RecordExternalCalibrationVendorException::unsupportedCalibrationMethod();
            }

            if ((string) $calibration->session_status !== Calibration::STATUS_IN_PROGRESS) {
                throw RecordExternalCalibrationVendorException::invalidSessionStatus();
            }

            $updated = $connection->table('calibration')
                ->where('calibration_id', $calibrationId)
                ->where('calibration_method', Calibration::METHOD_EXTERNAL)
                ->where('session_status', Calibration::STATUS_IN_PROGRESS)
                ->update([
                    'external_vendor_name' => $vendorName,
                ]);

            if ($updated !== 1) {
                throw RecordExternalCalibrationVendorException::invalidSessionStatus();
            }
        });
    }

    private function normalize(?string $vendorName): ?string
    {
        if ($vendorName === null) {
            return null;
        }

        $vendorName = trim($vendorName);

        if ($vendorName === '') {
            return null;
        }

        if (mb_strlen($vendorName) > 255) {
            throw RecordExternalCalibrationVendorException::vendorNameTooLong();
        }

        return $vendorName;
    }
}
