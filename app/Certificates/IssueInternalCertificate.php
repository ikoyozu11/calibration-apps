<?php

namespace App\Certificates;

use App\Models\Calibration;
use App\Models\Certificate;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class IssueInternalCertificate
{
    /** @var list<string> */
    private array $pendingPaths = [];

    public function __construct(
        private CertificateNumberAllocator $numbers,
        private ?ServiceReportPdfRenderer $renderer = null,
    ) {}

    public function issue(Connection $connection, int $calibrationId): Certificate
    {
        $calibration = Calibration::on($connection->getName())
            ->whereKey($calibrationId)
            ->first();

        if (! $calibration instanceof Calibration || $calibration->calibration_method !== Calibration::METHOD_INTERNAL) {
            throw CertificateIssuanceException::unsupportedCalibration();
        }

        $existing = $connection->table('certificate')
            ->where('calibration_id', $calibrationId)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if (! filled($existing->file_path)) {
                throw CertificateIssuanceException::existingWithoutFile();
            }

            return Certificate::on($connection->getName())->findOrFail($existing->certificate_id);
        }

        $issuedAt = Carbon::now('Asia/Jakarta');
        $certificateNumber = $this->numbers->allocate($connection, $issuedAt);
        $certificateId = $connection->table('certificate')->insertGetId([
            'calibration_id' => $calibrationId,
            'certificate_number' => $certificateNumber,
            'certificate_type' => Calibration::METHOD_INTERNAL,
            'issued_date' => $issuedAt->toDateString(),
            'file_path' => null,
            'file_name' => null,
            'remarks' => null,
            'created_at' => $issuedAt,
        ], 'certificate_id');

        $certificate = Certificate::on($connection->getName())->findOrFail($certificateId);
        $path = 'internal-certificates/'.Str::uuid().'.pdf';

        try {
            $this->storePdf($certificate, $path);

            $connection->table('certificate')
                ->where('certificate_id', $certificateId)
                ->update([
                    'file_path' => $path,
                    'file_name' => $certificateNumber.'.pdf',
                ]);
        } catch (Throwable $exception) {
            Storage::disk(config('calibration.documents.disk'))->delete($path);
            $this->pendingPaths = array_values(array_filter(
                $this->pendingPaths,
                static fn (string $pendingPath): bool => $pendingPath !== $path,
            ));

            if ($exception instanceof CertificateIssuanceException) {
                throw $exception;
            }

            throw new CertificateIssuanceException('The internal certificate PDF could not be generated.', 0, $exception);
        }

        return $certificate->fresh();
    }

    public function storePdf(Certificate $certificate, ?string $path = null): string
    {
        $path ??= 'internal-certificates/'.Str::uuid().'.pdf';
        $this->pendingPaths[] = $path;

        try {
            $pdf = ($this->renderer ?? new ServiceReportPdfRenderer)->render($certificate);

            if (! Storage::disk(config('calibration.documents.disk'))->put($path, $pdf->output())) {
                throw CertificateIssuanceException::storageFailed();
            }

            return $path;
        } catch (Throwable $exception) {
            Storage::disk(config('calibration.documents.disk'))->delete($path);
            $this->pendingPaths = array_values(array_filter(
                $this->pendingPaths,
                static fn (string $pendingPath): bool => $pendingPath !== $path,
            ));

            if ($exception instanceof CertificateIssuanceException) {
                throw $exception;
            }

            throw new CertificateIssuanceException('The internal certificate PDF could not be generated.', 0, $exception);
        }
    }

    public function cleanupLastArtifact(): void
    {
        foreach ($this->pendingPaths as $path) {
            Storage::disk(config('calibration.documents.disk'))->delete($path);
        }

        $this->pendingPaths = [];
    }

    public function forgetLastArtifact(): void
    {
        $this->pendingPaths = [];
    }
}
