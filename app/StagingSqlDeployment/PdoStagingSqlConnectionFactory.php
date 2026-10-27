<?php

namespace App\StagingSqlDeployment;

use PDO;
use PDOException;

/**
 * Explicit PDO factory for 21Z-D. Remains sealed until unsealed by the
 * controlled operational entry point with the approved checkpoint token.
 */
final class PdoStagingSqlConnectionFactory implements StagingSqlConnectionFactory
{
    private bool $unsealed = false;

    private string $username = '';

    private string $password = '';

    public function unsealForAuthorizedCheckpoint(string $checkpoint, string $username, string $password): void
    {
        if ($checkpoint !== StagingSqlDeploymentAuthorization::APPROVED_CHECKPOINT) {
            throw new StagingSqlDeploymentException('Cannot unseal PDO factory for an unapproved checkpoint.');
        }

        if ($username === '') {
            throw new StagingSqlDeploymentException('Staging SQL username is required to unseal the PDO factory.');
        }

        $this->unsealed = true;
        $this->username = $username;
        $this->password = $password;
    }

    public function reseal(): void
    {
        $this->unsealed = false;
        $this->username = '';
        $this->password = '';
    }

    public function isUnsealed(): bool
    {
        return $this->unsealed;
    }

    public function connect(StagingSqlDeploymentTarget $target): StagingSqlSession
    {
        if (! $this->unsealed) {
            throw new StagingSqlDeploymentException(
                'PDO staging SQL factory is sealed. Connection acquisition requires the 21Z-D entry point.'
            );
        }

        $host = strtolower($target->host) === 'localhost' ? '127.0.0.1' : $target->host;
        if ($host !== '127.0.0.1'
            || $target->port !== ApprovedStagingSqlArtifacts::APPROVED_PORT
            || $target->database !== ApprovedStagingSqlArtifacts::APPROVED_DATABASE) {
            throw new StagingSqlDeploymentException('PDO factory refused non-approved staging target.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=10',
            $host,
            $target->port,
            $target->database
        );

        try {
            $pdo = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new StagingSqlDeploymentException('Staging PDO connect failed.', 0, $exception);
        }

        return new PdoStagingSqlSession($pdo);
    }
}
