<?php

namespace App\Authorization;

use Illuminate\Database\Connection;

/**
 * Resolves the live database identity for a Connection.
 *
 * Production uses SELECT current_database() on PostgreSQL. Tests inject a
 * fixed identity so SQLite :memory: can exercise the staging allowlist without
 * opening PostgreSQL.
 */
interface ResolvesLiveDatabaseIdentity
{
    /**
     * Return the live database name, or an empty string when identity cannot
     * be proven (non-pgsql drivers are not valid bootstrap deployment targets).
     */
    public function identity(Connection $connection): string;
}
