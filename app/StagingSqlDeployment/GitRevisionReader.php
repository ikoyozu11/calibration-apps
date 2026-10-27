<?php

namespace App\StagingSqlDeployment;

interface GitRevisionReader
{
    public function head(): string;
}
