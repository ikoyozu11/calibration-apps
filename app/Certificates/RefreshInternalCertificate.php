<?php

namespace App\Certificates;

use App\Models\Calibration;
use App\Models\Certificate;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Throwable;

final class RefreshInternalCertificate
{
    public function __construct(
        private IssueInternalCertificate $issuer,
    ) {}

    public function refresh(Connection $connection, int $calibrationId, string $reason = 'Updated to the latest certificate template'): Certificate
    {
        try {
            $certificate = $connection->transaction(function () use ($connection, $calibrationId, $reason): Certificate {
                $calibration = Calibration::on($connection->getName())
                    ->whereKey($calibrationId)
                    ->lockForUpdate()
                    ->first();

                if (! $calibration instanceof Calibration
                    || $calibration->session_status !== Calibration::STATUS_COMPLETED
                    || $calibration->calibration_method !== Calibration::METHOD_INTERNAL) {
                    throw CertificateIssuanceException::unsupportedCalibration();
                }

                $existing = $connection->table('certificate')
                    ->where('calibration_id', $calibrationId)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    return $this->issuer->issue($connection, $calibrationId);
                }

                $certificate = Certificate::on($connection->getName())->findOrFail($existing->certificate_id);
                $path = $this->issuer->storePdf($certificate);
                $issuedAt = Carbon::now('Asia/Jakarta');
                $revisionNumber = ((int) $connection->table('certificate_revision')
                    ->where('certificate_id', $certificate->certificate_id)
                    ->max('revision_number')) + 1;

                $connection->table('certificate_revision')->insert([
                    'certificate_id' => $certificate->certificate_id,
                    'revision_number' => $revisionNumber,
                    'revision_date' => $issuedAt,
                    'revision_reason' => $reason,
                    'file_path' => $certificate->file_path,
                    'file_name' => $certificate->file_name,
                    'created_at' => $issuedAt,
                ]);

                $connection->table('certificate')
                    ->where('certificate_id', $certificate->certificate_id)
                    ->update([
                        'file_path' => $path,
                        'file_name' => $certificate->certificate_number.'.pdf',
                    ]);

                return $certificate->fresh();
            });

            $this->issuer->forgetLastArtifact();

            return $certificate;
        } catch (Throwable $exception) {
            $this->issuer->cleanupLastArtifact();

            throw $exception;
        }
    }
}
