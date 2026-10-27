<?php

namespace App\StagingSqlDeployment;

final class StagingSqlIdentitySnapshot
{
    public function __construct(
        public readonly string $currentDatabase,
        public readonly string $currentUser,
        public readonly string $serverAddress,
        public readonly int $serverPort,
        public readonly string $transactionReadOnly,
        public readonly string $serverVersion,
    ) {}
}
