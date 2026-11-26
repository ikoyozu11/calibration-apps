<?php

namespace App\StagingSqlDeployment;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Ed25519 authority that verifies a parsed manifest against an injected,
 * independently trusted public-key provider.
 */
final class NotificationStagingSqlEd25519ApprovalAuthority implements NotificationStagingSqlApprovalAuthority
{
    public function __construct(
        private readonly NotificationStagingSqlApprovalManifest $manifest,
        private readonly NotificationStagingSqlTrustedVerificationKeyProvider $keyProvider,
    ) {}

    public function assertAuthentic(NotificationStagingSqlApprovalClaims $claims): void
    {
        if ($this->manifest->algorithm !== NotificationStagingSqlApprovalManifest::ALGORITHM
            || ! hash_equals(
                $this->manifest->claims->approvalId,
                $claims->approvalId,
            )) {
            throw new StagingSqlDeploymentException('Notification approval algorithm or claim identity mismatch.');
        }

        $claims->assertShape(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->assertEquivalentClaims($this->manifest->claims, $claims);

        $publicKey = $this->keyProvider->publicKeyFor($this->manifest->keyId);
        if ($publicKey === null) {
            throw new StagingSqlDeploymentException('Notification approval verification key is not trusted.');
        }

        try {
            $decodedSignature = sodium_base642bin(
                $this->manifest->signature,
                SODIUM_BASE64_VARIANT_ORIGINAL,
            );
        } catch (Throwable $exception) {
            throw new StagingSqlDeploymentException('Notification approval signature encoding is invalid.', 0, $exception);
        }

        if (strlen($decodedSignature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! sodium_crypto_sign_verify_detached($decodedSignature, $this->manifest->signedBytes, $publicKey)) {
            throw new StagingSqlDeploymentException('Notification approval signature verification failed.');
        }
    }

    private function assertEquivalentClaims(
        NotificationStagingSqlApprovalClaims $manifestClaims,
        NotificationStagingSqlApprovalClaims $claims,
    ): void {
        if (NotificationStagingSqlApprovalManifest::canonicalSignedBytes(
            $manifestClaims,
            $this->manifest->algorithm,
            $this->manifest->keyId,
        ) !== NotificationStagingSqlApprovalManifest::canonicalSignedBytes(
            $claims,
            $this->manifest->algorithm,
            $this->manifest->keyId,
        )) {
            throw new StagingSqlDeploymentException('Notification approval claims do not match the signed manifest.');
        }
    }
}
