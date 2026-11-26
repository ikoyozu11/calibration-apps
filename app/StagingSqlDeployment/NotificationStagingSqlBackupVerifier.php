<?php

namespace App\StagingSqlDeployment;

/**
 * Verifies backup bytes locally without opening a database connection.
 */
final class NotificationStagingSqlBackupVerifier
{
    public function assertFileMatches(string $path, string $expectedSha256): void
    {
        if (! is_file($path) || ! is_readable($path) || (filesize($path) ?: 0) === 0) {
            throw new StagingSqlDeploymentException('Fresh staging backup is missing, unreadable, or empty.');
        }

        if (! preg_match('/^[a-f0-9]{64}$/i', $expectedSha256)) {
            throw new StagingSqlDeploymentException('Trusted backup checksum is missing or malformed.');
        }

        $actualSha256 = hash_file('sha256', $path);
        if ($actualSha256 === false || ! hash_equals(strtolower($expectedSha256), $actualSha256)) {
            throw new StagingSqlDeploymentException('Fresh staging backup checksum mismatch.');
        }
    }
}
