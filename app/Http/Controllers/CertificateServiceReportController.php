<?php

namespace App\Http\Controllers;

use App\Authorization\ApplicationCapability;
use App\CalibrationSessions\CalibrationSessionHttpRead;
use App\Certificates\RefreshInternalCertificate;
use App\Certificates\ServiceReportPdfRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificateServiceReportController extends Controller
{
    public function __invoke(int $certificate, CalibrationSessionHttpRead $reads, ServiceReportPdfRenderer $renderer): Response|StreamedResponse
    {
        Gate::authorize(ApplicationCapability::CertificatesView->value);

        $model = $reads->certificate($certificate);

        if (filled($model->file_path)) {
            return $this->storedPdf($model->file_path, $model->file_name, false);
        }

        $filename = $this->responseFilename(
            filled($model->certificate_number) ? $model->certificate_number.'.pdf' : null,
            'service-report.pdf',
        );

        return $renderer->render($model)->stream($filename);
    }

    public function download(int $certificate, CalibrationSessionHttpRead $reads, ServiceReportPdfRenderer $renderer): Response|StreamedResponse
    {
        Gate::authorize(ApplicationCapability::CertificatesView->value);

        $model = $reads->certificate($certificate);

        if (filled($model->file_path)) {
            return $this->storedPdf($model->file_path, $model->file_name, true);
        }

        $filename = $this->responseFilename(
            filled($model->certificate_number) ? $model->certificate_number.'.pdf' : null,
            'service-report.pdf',
        );

        return $renderer->render($model)->download($filename);
    }

    public function refresh(int $calibration, CalibrationSessionHttpRead $reads, RefreshInternalCertificate $refresh): RedirectResponse
    {
        Gate::authorize(ApplicationCapability::SessionComplete->value);

        $refresh->refresh($reads->connection(), $calibration);

        return back()->with('status', 'Certificate updated successfully.');
    }

    private function storedPdf(string $path, ?string $filename, bool $download): StreamedResponse
    {
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            abort(404);
        }

        $disk = Storage::disk(config('calibration.documents.disk'));

        if (! $disk->exists($path)) {
            abort(404);
        }

        $safeFilename = $this->responseFilename($filename, 'calibration-certificate.pdf');

        return $download
            ? $disk->download($path, $safeFilename, ['Content-Type' => 'application/pdf'])
            : $disk->response($path, $safeFilename, ['Content-Type' => 'application/pdf'], 'inline');
    }

    private function responseFilename(?string $filename, string $fallback): string
    {
        $filename = trim((string) $filename);

        if ($filename === '') {
            return $fallback;
        }

        $filename = preg_replace('/[\\\\\/]+/', '-', $filename) ?? '';
        $filename = preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $filename) ?? '';
        $filename = trim($filename, ' .');

        return $filename !== '' ? $filename : $fallback;
    }
}
