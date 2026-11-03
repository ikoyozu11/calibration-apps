<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads one EXTERNAL vendor PDF from private storage.
 *
 * The caller supplies an explicit Connection. This service does not write
 * calibration or document rows, does not call Storage::url(), and does not
 * treat original_filename as a storage path. SQLite compiles without a
 * PostgreSQL row lock. Active and superseded documents are both eligible.
 */
class DownloadExternalCalibrationDocument
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
    ) {}

    /**
     * @return array{contents: string, filename: string, mime_type: string}
     *
     * @throws DownloadExternalCalibrationDocumentException
     * @throws CalibrationSessionConnectionException
     */
    public function download(int $calibrationId, int $documentId, Connection $connection): array
    {
        $connection = $this->sessionConnection->resolve($connection);

        if ($calibrationId < 1) {
            throw DownloadExternalCalibrationDocumentException::invalidCalibrationId();
        }

        if ($documentId < 1) {
            throw DownloadExternalCalibrationDocumentException::invalidDocumentId();
        }

        $calibration = $connection->table('calibration')
            ->where('calibration_id', $calibrationId)
            ->first();

        if ($calibration === null) {
            throw DownloadExternalCalibrationDocumentException::missingCalibration();
        }

        if ((string) $calibration->calibration_method !== Calibration::METHOD_EXTERNAL) {
            throw DownloadExternalCalibrationDocumentException::unsupportedCalibrationMethod();
        }

        $document = $connection->table('calibration_external_document')
            ->where('document_id', $documentId)
            ->where('calibration_id', $calibrationId)
            ->first();

        if ($document === null) {
            throw DownloadExternalCalibrationDocumentException::missingDocument();
        }

        $path = $this->storedPath($document);
        $diskName = $this->documentDisk();

        try {
            $filesystem = Storage::disk($diskName);

            if (! $filesystem->exists($path)) {
                throw DownloadExternalCalibrationDocumentException::missingStoredFile();
            }

            $contents = $filesystem->get($path);
        } catch (DownloadExternalCalibrationDocumentException $exception) {
            throw $exception;
        } catch (FileNotFoundException) {
            throw DownloadExternalCalibrationDocumentException::missingStoredFile();
        } catch (Throwable) {
            throw DownloadExternalCalibrationDocumentException::unreadableStoredFile();
        }

        if (! is_string($contents) || $contents === '') {
            throw DownloadExternalCalibrationDocumentException::unreadableStoredFile();
        }

        return [
            'contents' => $contents,
            'filename' => $this->downloadName((string) $document->original_filename),
            'mime_type' => 'application/pdf',
        ];
    }

    public function downloadName(string $originalFilename): string
    {
        $originalFilename = str_replace(["\0", "\r", "\n"], '', $originalFilename);
        $originalFilename = str_replace(['\\', '/'], '-', $originalFilename);
        $originalFilename = trim($originalFilename, " .\t");

        if ($originalFilename === '' || $originalFilename === '-') {
            return 'vendor-certificate.pdf';
        }

        if (! str_ends_with(strtolower($originalFilename), '.pdf')) {
            $originalFilename .= '.pdf';
        }

        return $originalFilename;
    }

    private function storedPath(object $document): string
    {
        $path = (string) ($document->storage_path ?? '');
        $uuid = (string) ($document->storage_uuid ?? '');

        if ($path !== $uuid.'.pdf'
            || preg_match('/^[0-9a-f-]{36}\.pdf$/i', $path) !== 1
            || str_contains($path, '/')
            || str_contains($path, '\\')
            || str_contains($path, '..')) {
            throw DownloadExternalCalibrationDocumentException::invalidStoredPath();
        }

        return $path;
    }

    private function documentDisk(): string
    {
        $configured = config('filesystems.disks.calibration_documents');
        $root = is_array($configured) ? (string) ($configured['root'] ?? '') : '';

        if (config('calibration.documents.disk') !== 'calibration_documents'
            || ! is_array($configured)
            || ($configured['serve'] ?? null) !== false
            || ($configured['visibility'] ?? null) !== 'private'
            || ($configured['throw'] ?? null) !== true
            || basename($root) !== 'calibration-documents') {
            throw DownloadExternalCalibrationDocumentException::missingStoredFile();
        }

        return 'calibration_documents';
    }
}
