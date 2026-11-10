<?php

namespace App\Http\Controllers;

use App\Authorization\ApplicationCapability;
use App\CalibrationSessions\CalibrationSessionConnectionException;
use App\CalibrationSessions\DownloadExternalCalibrationDocument;
use App\CalibrationSessions\DownloadExternalCalibrationDocumentException;
use App\CalibrationSessions\ReadCalibrationSessionHttpConnection;
use App\CalibrationSessions\ReadCalibrationSessionHttpException;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExternalCalibrationDocumentDownloadController extends Controller
{
    public function __invoke(
        int $calibration,
        int $document,
        DownloadExternalCalibrationDocument $download,
        ReadCalibrationSessionHttpConnection $reads,
    ): StreamedResponse {
        return $this->respond(
            $calibration,
            $document,
            $download,
            $reads,
            HeaderUtils::DISPOSITION_ATTACHMENT,
        );
    }

    public function view(
        int $calibration,
        int $document,
        DownloadExternalCalibrationDocument $download,
        ReadCalibrationSessionHttpConnection $reads,
    ): StreamedResponse {
        return $this->respond(
            $calibration,
            $document,
            $download,
            $reads,
            HeaderUtils::DISPOSITION_INLINE,
        );
    }

    private function respond(
        int $calibration,
        int $document,
        DownloadExternalCalibrationDocument $download,
        ReadCalibrationSessionHttpConnection $reads,
        string $dispositionType,
    ): StreamedResponse {
        Gate::authorize(ApplicationCapability::HistoryView->value);

        try {
            $file = $download->download($calibration, $document, $reads->resolve());
        } catch (ReadCalibrationSessionHttpException|CalibrationSessionConnectionException $exception) {
            abort(503, $exception->getMessage());
        } catch (DownloadExternalCalibrationDocumentException) {
            abort(404);
        }

        $filename = $file['filename'];
        $fallback = $this->asciiFilename($filename);
        $disposition = HeaderUtils::makeDisposition(
            $dispositionType,
            $filename,
            $fallback,
        );

        $stream = function () use ($file): void {
            echo $file['contents'];
        };
        $headers = [
            'Content-Type' => $file['mime_type'],
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($dispositionType === HeaderUtils::DISPOSITION_ATTACHMENT) {
            return response()->streamDownload($stream, $filename, $headers);
        }

        return response()->stream($stream, 200, $headers);
    }

    private function asciiFilename(string $filename): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'vendor-certificate.pdf';
        $fallback = trim($fallback, '._-');

        if ($fallback === '') {
            return 'vendor-certificate.pdf';
        }

        if (! str_ends_with(strtolower($fallback), '.pdf')) {
            $fallback .= '.pdf';
        }

        return $fallback;
    }
}
