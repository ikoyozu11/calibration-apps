<?php

namespace App\StagingSqlDeployment;

use DateTimeImmutable;
use JsonException;

/**
 * Parsed, canonical approval manifest envelope.
 */
final class NotificationStagingSqlApprovalManifest
{
    public const SCHEMA_VERSION = 1;

    public const ALGORITHM = 'Ed25519';

    public function __construct(
        public readonly NotificationStagingSqlApprovalClaims $claims,
        public readonly string $algorithm,
        public readonly string $keyId,
        public readonly string $signature,
        public readonly string $signedBytes,
    ) {}

    public static function canonicalSignedBytes(
        NotificationStagingSqlApprovalClaims $claims,
        string $algorithm,
        string $keyId,
    ): string {
        return self::encode(self::signedDocument($claims, $algorithm, $keyId));
    }

    public static function canonicalDocument(
        NotificationStagingSqlApprovalClaims $claims,
        string $algorithm,
        string $keyId,
        string $signature,
    ): string {
        return self::encode(array_merge(
            self::signedDocument($claims, $algorithm, $keyId),
            ['signature' => $signature],
        ));
    }

    /**
     * @return array{schema_version: int, claims: array<string, mixed>, algorithm: string, key_id: string}
     */
    private static function signedDocument(
        NotificationStagingSqlApprovalClaims $claims,
        string $algorithm,
        string $keyId,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'claims' => [
                'approval_id' => $claims->approvalId,
                'operator_identity' => $claims->operatorIdentity,
                'release_identity' => $claims->releaseIdentity,
                'artifact_sha256' => $claims->artifactSha256,
                'database_identity' => $claims->databaseIdentity,
                'host' => $claims->host,
                'port' => $claims->port,
                'backup_sha256' => $claims->backupSha256,
                'operation' => $claims->operation,
                'issued_at' => self::timestamp($claims->issuedAt),
                'expires_at' => self::timestamp($claims->expiresAt),
            ],
            'algorithm' => $algorithm,
            'key_id' => $keyId,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function encode(array $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new StagingSqlDeploymentException('Notification approval manifest cannot be serialized.', 0, $exception);
        }
    }

    private static function timestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
