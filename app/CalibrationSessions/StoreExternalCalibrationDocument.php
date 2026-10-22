<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores one vendor PDF for an in-progress external calibration session.
 *
 * The caller passes a Laravel users.id. This service stores the app_user_id
 * returned by ResolveDomainActor and does not accept an actor id of its own.
 * Each success inserts a new active document and marks the previous active
 * row superseded. The file name on disk is {uuid}.pdf on calibration_documents.
 *
 * PostgreSQL trg_calibration_external_document_supersede also deactivates
 * other active rows in a BEFORE INSERT trigger. This service deactivates
 * them before the insert, so that trigger finds no remaining active row.
 * Superseded files stay on disk. A new file is removed only when this attempt
 * fails after the write. Staging acquisition stays outside this service and
 * outside Pest. SQLite compiles lockForUpdate() without that trigger or a
 * real row lock.
 */
class StoreExternalCalibrationDocument
{
    public function __construct(
        private CalibrationSessionConnection $sessionConnection,
        private ResolveDomainActor $actors,
    ) {}

    public function store(
        int $calibrationId,
        string $originalFilename,
        string $contents,
        int $laravelUserId,
        Connection $connection,
    ): void {
        $connection = $this->sessionConnection->resolve($connection);

        if ($calibrationId < 1) {
            throw StoreExternalCalibrationDocumentException::invalidCalibrationId();
        }

        $originalFilename = $this->filename($originalFilename);
        $contents = $this->contents($contents);
        $diskName = $this->documentDisk();
        $storedPath = null;

        try {
            $connection->transaction(function () use (
                $connection,
                $calibrationId,
                $originalFilename,
                $contents,
                $laravelUserId,
                $diskName,
                &$storedPath,
            ): void {
                $calibration = $connection->table('calibration')
                    ->where('calibration_id', $calibrationId)
                    ->lockForUpdate()
                    ->first();

                if ($calibration === null) {
                    throw StoreExternalCalibrationDocumentException::missingCalibration();
                }

                if ($calibration->calibration_method !== Calibration::METHOD_EXTERNAL) {
                    throw StoreExternalCalibrationDocumentException::invalidMethod();
                }

                if ($calibration->session_status !== Calibration::STATUS_IN_PROGRESS) {
                    throw StoreExternalCalibrationDocumentException::invalidSessionStatus();
                }

                $active = $connection->table('calibration_external_document')
                    ->where('calibration_id', $calibrationId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->get();

                if ($active->count() > 1 || $active->contains(fn (object $document): bool => $document->superseded_at !== null)) {
                    throw StoreExternalCalibrationDocumentException::invalidActiveDocumentState();
                }

                $appUserId = $this->actors->resolve($laravelUserId, $connection);

                $uuid = (string) Str::uuid();
                $path = $uuid.'.pdf';
                $filesystem = Storage::disk($diskName);

                if ($filesystem->exists($path)) {
                    throw StoreExternalCalibrationDocumentException::storageWriteFailure();
                }

                $storedPath = $path;

                if ($filesystem->put($path, $contents) !== true) {
                    throw StoreExternalCalibrationDocumentException::storageWriteFailure();
                }

                if ($active->isNotEmpty()) {
                    $updated = $connection->table('calibration_external_document')
                        ->where('document_id', $active->first()->document_id)
                        ->where('is_active', true)
                        ->update([
                            'is_active' => false,
                            'superseded_at' => now(),
                        ]);

                    if ($updated !== 1) {
                        throw StoreExternalCalibrationDocumentException::invalidActiveDocumentState();
                    }
                }

                $connection->table('calibration_external_document')->insert([
                    'calibration_id' => $calibrationId,
                    'storage_uuid' => $uuid,
                    'storage_path' => $path,
                    'original_filename' => $originalFilename,
                    'mime_type' => 'application/pdf',
                    'byte_size' => strlen($contents),
                    'sha256' => hash('sha256', $contents),
                    'is_active' => true,
                    'uploaded_at' => now(),
                    'superseded_at' => null,
                    'uploaded_by_user_id' => $appUserId,
                ]);
            });
        } catch (Throwable $exception) {
            $this->removeNewFile($diskName, $storedPath);

            throw $exception;
        }
    }

    private function filename(string $originalFilename): string
    {
        if (str_contains($originalFilename, "\0")) {
            throw StoreExternalCalibrationDocumentException::invalidFilename();
        }

        $originalFilename = trim($originalFilename);

        if ($originalFilename === ''
            || str_contains($originalFilename, '/')
            || str_contains($originalFilename, '\\')
            || str_contains($originalFilename, '..')
            || ! str_ends_with($originalFilename, '.pdf')
            || $originalFilename === '.pdf') {
            throw StoreExternalCalibrationDocumentException::invalidFilename();
        }

        return $originalFilename;
    }

    private function contents(string $contents): string
    {
        if ($contents === '') {
            throw StoreExternalCalibrationDocumentException::emptyPdf();
        }

        if (! str_starts_with($contents, '%PDF-')) {
            throw StoreExternalCalibrationDocumentException::invalidPdfSignature();
        }

        if (strlen($contents) > (int) config('calibration.documents.max_bytes')) {
            throw StoreExternalCalibrationDocumentException::fileTooLarge();
        }

        return $contents;
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
            throw StoreExternalCalibrationDocumentException::invalidStorageDisk();
        }

        return 'calibration_documents';
    }

    private function removeNewFile(string $diskName, ?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            $filesystem = Storage::disk($diskName);

            if (! $filesystem->exists($path)) {
                return;
            }

            if ($filesystem->delete($path) !== true) {
                report(StoreExternalCalibrationDocumentException::storageWriteFailure());
            }
        } catch (Throwable $cleanup) {
            report($cleanup);
        }
    }
}
