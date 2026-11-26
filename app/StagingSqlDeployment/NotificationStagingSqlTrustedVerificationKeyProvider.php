<?php

namespace App\StagingSqlDeployment;

/**
 * Trusted-key lookup boundary. It must be provisioned independently of the
 * approval manifest and has no default operational implementation here.
 */
interface NotificationStagingSqlTrustedVerificationKeyProvider
{
    public function publicKeyFor(string $keyId): ?string;
}
