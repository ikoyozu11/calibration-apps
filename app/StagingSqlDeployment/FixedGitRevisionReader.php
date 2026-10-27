<?php

namespace App\StagingSqlDeployment;

final class FixedGitRevisionReader implements GitRevisionReader
{
    public function __construct(private string $head) {}

    public function head(): string
    {
        return $this->head;
    }
}
