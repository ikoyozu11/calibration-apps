<?php

namespace App\StagingSqlDeployment;

use Illuminate\Database\Connection;

interface NotificationStagingSqlReadOnlyMetadataReader
{
    public function inspect(Connection $connection): NotificationStagingSqlSchemaInspection;
}
