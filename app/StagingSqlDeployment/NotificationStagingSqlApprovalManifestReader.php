<?php

namespace App\StagingSqlDeployment;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/**
 * Strict, read-only reader for an explicitly selected approval manifest.
 */
final class NotificationStagingSqlApprovalManifestReader
{
    public const MAX_MANIFEST_BYTES = 16384;

    public function read(string $path): NotificationStagingSqlApprovalManifest
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new StagingSqlDeploymentException('Notification approval manifest is missing or unreadable.');
        }

        $size = filesize($path);
        if ($size === false || $size === 0 || $size > self::MAX_MANIFEST_BYTES) {
            throw new StagingSqlDeploymentException('Notification approval manifest size is invalid.');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new StagingSqlDeploymentException('Notification approval manifest could not be read.');
        }

        try {
            $document = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new StagingSqlDeploymentException('Notification approval manifest is not valid JSON.', 0, $exception);
        }

        if (! is_array($document)
            || array_keys($document) !== ['schema_version', 'claims', 'algorithm', 'key_id', 'signature']
            || $document['schema_version'] !== NotificationStagingSqlApprovalManifest::SCHEMA_VERSION
            || ! is_array($document['claims'])
            || ! is_string($document['algorithm'])
            || ! is_string($document['key_id'])
            || ! is_string($document['signature'])) {
            throw new StagingSqlDeploymentException('Notification approval manifest structure is invalid.');
        }

        if ($document['algorithm'] !== NotificationStagingSqlApprovalManifest::ALGORITHM
            || trim($document['key_id']) === ''
            || trim($document['signature']) === '') {
            throw new StagingSqlDeploymentException('Notification approval manifest algorithm or signature is invalid.');
        }

        $claims = $this->claimsFromArray($document['claims']);
        $canonicalDocument = NotificationStagingSqlApprovalManifest::canonicalDocument(
            $claims,
            $document['algorithm'],
            $document['key_id'],
            $document['signature'],
        );

        if (! hash_equals($canonicalDocument, $contents)) {
            throw new StagingSqlDeploymentException('Notification approval manifest is not canonical.');
        }

        return new NotificationStagingSqlApprovalManifest(
            claims: $claims,
            algorithm: $document['algorithm'],
            keyId: $document['key_id'],
            signature: $document['signature'],
            signedBytes: NotificationStagingSqlApprovalManifest::canonicalSignedBytes(
                $claims,
                $document['algorithm'],
                $document['key_id'],
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function claimsFromArray(array $values): NotificationStagingSqlApprovalClaims
    {
        $expected = [
            'approval_id',
            'operator_identity',
            'release_identity',
            'artifact_sha256',
            'database_identity',
            'host',
            'port',
            'backup_sha256',
            'operation',
            'issued_at',
            'expires_at',
        ];

        if (array_keys($values) !== $expected
            || ! is_string($values['approval_id'])
            || ! is_string($values['operator_identity'])
            || ! is_string($values['release_identity'])
            || ! is_string($values['artifact_sha256'])
            || ! is_string($values['database_identity'])
            || ! is_string($values['host'])
            || ! is_int($values['port'])
            || ! is_string($values['backup_sha256'])
            || ! is_string($values['operation'])
            || ! is_string($values['issued_at'])
            || ! is_string($values['expires_at'])) {
            throw new StagingSqlDeploymentException('Notification approval manifest claims are invalid.');
        }

        return new NotificationStagingSqlApprovalClaims(
            approvalId: $values['approval_id'],
            operatorIdentity: $values['operator_identity'],
            releaseIdentity: $values['release_identity'],
            artifactSha256: $values['artifact_sha256'],
            databaseIdentity: $values['database_identity'],
            host: $values['host'],
            port: $values['port'],
            backupSha256: $values['backup_sha256'],
            issuedAt: $this->parseTimestamp($values['issued_at']),
            expiresAt: $this->parseTimestamp($values['expires_at']),
            operation: $values['operation'],
        );
    }

    private function parseTimestamp(string $timestamp): DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $timestamp) !== 1) {
            throw new StagingSqlDeploymentException('Notification approval timestamp is not canonical UTC.');
        }

        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $timestamp,
            new DateTimeZone('UTC'),
        );

        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s\Z') !== $timestamp) {
            throw new StagingSqlDeploymentException('Notification approval timestamp is invalid.');
        }

        return $parsed;
    }
}
