<?php

namespace App\StagingSqlDeployment;

final class ProcessGitRevisionReader implements GitRevisionReader
{
    public function __construct(private string $repositoryRoot) {}

    public function head(): string
    {
        $command = 'git -C '.escapeshellarg($this->repositoryRoot).' rev-parse HEAD';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            throw new StagingSqlDeploymentException('Unable to resolve Git HEAD for staging SQL deployment gates.');
        }

        $head = trim((string) $output[0]);

        if ($head === '' || ! preg_match('/^[0-9a-f]{40}$/', $head)) {
            throw new StagingSqlDeploymentException('Git HEAD is not a full 40-character SHA-1.');
        }

        return $head;
    }
}
