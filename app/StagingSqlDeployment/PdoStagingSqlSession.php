<?php

namespace App\StagingSqlDeployment;

use PDO;
use PDOException;

/**
 * Explicit PDO session for calibration_staging_db only.
 * Does not use Laravel DB facades or the default connection.
 */
final class PdoStagingSqlSession implements StagingSqlSession
{
    private bool $open = true;

    public function __construct(private ?PDO $pdo) {}

    public function applySafetyTimeouts(int $statementTimeoutMs, int $lockTimeoutMs, int $idleInTransactionMs): void
    {
        $this->assertOpen();
        $this->pdo->exec('SET statement_timeout = '.$this->pdo->quote((string) $statementTimeoutMs));
        $this->pdo->exec('SET lock_timeout = '.$this->pdo->quote((string) $lockTimeoutMs));
        $this->pdo->exec('SET idle_in_transaction_session_timeout = '.$this->pdo->quote((string) $idleInTransactionMs));
    }

    public function beginTransaction(): void
    {
        $this->assertOpen();
        if (! $this->pdo->beginTransaction()) {
            throw new StagingSqlDeploymentException('Failed to BEGIN staging SQL transaction.');
        }
    }

    public function commit(): void
    {
        $this->assertOpen();
        try {
            if (! $this->pdo->commit()) {
                throw new StagingSqlDeploymentException('Connection lost during COMMIT.');
            }
        } catch (PDOException $exception) {
            throw new StagingSqlDeploymentException('Connection lost during COMMIT.', 0, $exception);
        }
    }

    public function rollBack(): void
    {
        if (! $this->open) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function close(): void
    {
        $this->open = false;
        $this->pdo = null;
    }

    public function isOpen(): bool
    {
        return $this->open && $this->pdo instanceof PDO;
    }

    public function identitySnapshot(): StagingSqlIdentitySnapshot
    {
        $this->assertOpen();
        $row = $this->pdo->query(
            "SELECT current_database() AS d,
                    current_user AS u,
                    inet_server_addr()::text AS a,
                    inet_server_port() AS p,
                    current_setting('transaction_read_only') AS ro,
                    version() AS v"
        )->fetch(PDO::FETCH_ASSOC);

        return new StagingSqlIdentitySnapshot(
            currentDatabase: (string) ($row['d'] ?? ''),
            currentUser: (string) ($row['u'] ?? ''),
            serverAddress: (string) ($row['a'] ?? ''),
            serverPort: (int) ($row['p'] ?? 0),
            transactionReadOnly: (string) ($row['ro'] ?? ''),
            serverVersion: (string) ($row['v'] ?? ''),
        );
    }

    public function relationExists(string $schemaQualifiedName): bool
    {
        $this->assertOpen();
        $stmt = $this->pdo->query('SELECT to_regclass('.$this->pdo->quote($schemaQualifiedName).') IS NOT NULL AS e');
        $value = $stmt->fetch(PDO::FETCH_ASSOC)['e'] ?? false;

        return $value === true || $value === 't' || $value === 1 || $value === '1';
    }

    public function columnMetadata(string $schema, string $table, string $column): ?array
    {
        $this->assertOpen();
        $stmt = $this->pdo->prepare(
            'SELECT data_type, is_nullable
             FROM information_schema.columns
             WHERE table_schema = :schema AND table_name = :table AND column_name = :column'
        );
        $stmt->execute(['schema' => $schema, 'table' => $table, 'column' => $column]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'data_type' => (string) $row['data_type'],
            'is_nullable' => (string) $row['is_nullable'],
        ];
    }

    public function functionDefinition(string $schema, string $functionName): ?string
    {
        return $this->functionFingerprint($schema, $functionName)['definition'] ?? null;
    }

    public function functionFingerprint(string $schema, string $functionName): ?array
    {
        $this->assertOpen();
        $stmt = $this->pdo->prepare(
            'SELECT pg_get_functiondef(p.oid) AS definition
             FROM pg_proc p
             JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = :schema AND p.proname = :name
             LIMIT 1'
        );
        $stmt->execute(['schema' => $schema, 'name' => $functionName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $definition = (string) $row['definition'];

        return [
            'md5' => md5($definition),
            'length' => strlen($definition),
            'definition' => $definition,
        ];
    }

    public function triggersOnTable(string $schema, string $table, string $triggerName): array
    {
        $this->assertOpen();
        $stmt = $this->pdo->prepare(
            'SELECT t.tgname AS name, t.tgenabled AS enabled, pg_get_triggerdef(t.oid) AS definition
             FROM pg_trigger t
             JOIN pg_class c ON c.oid = t.tgrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = :schema
               AND c.relname = :table
               AND t.tgname = :trigger
               AND NOT t.tgisinternal
             ORDER BY t.tgname'
        );
        $stmt->execute(['schema' => $schema, 'table' => $table, 'trigger' => $triggerName]);

        return array_map(
            static fn (array $row): array => [
                'name' => (string) $row['name'],
                'enabled' => (string) $row['enabled'],
                'definition' => (string) $row['definition'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function roleCatalogNames(): array
    {
        $this->assertOpen();
        if (! $this->relationExists('public.roles')) {
            return [];
        }

        return $this->pdo->query('SELECT name FROM public.roles ORDER BY name')
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    public function foreignKeyDeleteAction(string $schema, string $table, string $column): ?string
    {
        $this->assertOpen();
        $stmt = $this->pdo->prepare(
            "SELECT rc.delete_rule
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
             JOIN information_schema.referential_constraints rc
               ON rc.constraint_name = tc.constraint_name AND rc.constraint_schema = tc.table_schema
             WHERE tc.constraint_type = 'FOREIGN KEY'
               AND tc.table_schema = :schema
               AND tc.table_name = :table
               AND kcu.column_name = :column
             LIMIT 1"
        );
        $stmt->execute(['schema' => $schema, 'table' => $table, 'column' => $column]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function foreignKeyTarget(string $schema, string $table, string $column): ?string
    {
        $this->assertOpen();
        $stmt = $this->pdo->prepare(
            "SELECT ccu.table_schema, ccu.table_name, ccu.column_name
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
             JOIN information_schema.constraint_column_usage ccu
               ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
             WHERE tc.constraint_type = 'FOREIGN KEY'
               AND tc.table_schema = :schema
               AND tc.table_name = :table
               AND kcu.column_name = :column
             LIMIT 1"
        );
        $stmt->execute(['schema' => $schema, 'table' => $table, 'column' => $column]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $row['table_schema'].'.'.$row['table_name'].'('.$row['column_name'].')';
    }

    public function executeSql(string $sql): void
    {
        $this->assertOpen();
        try {
            $this->pdo->exec($sql);
        } catch (PDOException $exception) {
            throw new StagingSqlDeploymentException('SQL execution failure: '.$exception->getMessage(), 0, $exception);
        }
    }

    public function isRealPostgreSql(): bool
    {
        return true;
    }

    public function acquireDeploymentLock(): void
    {
        $this->assertOpen();
        $this->pdo->exec("SELECT pg_advisory_xact_lock(hashtext('instruments_desk_staging_atomic_sql_21z_d'))");
    }

    private function assertOpen(): void
    {
        if (! $this->isOpen()) {
            throw new StagingSqlDeploymentException('Staging SQL PDO session is closed.');
        }
    }
}
