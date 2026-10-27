<?php

namespace App\StagingSqlDeployment;

/**
 * In-memory session for offline adapter tests. Never creates PDO or Laravel DB.
 */
final class FakeStagingSqlSession implements StagingSqlSession
{
    /** @var list<string> */
    public array $executedStatements = [];

    /** @var list<string> */
    public array $timeoutSettings = [];

    public int $beginCount = 0;

    public int $commitCount = 0;

    public int $rollbackCount = 0;

    public int $closeCount = 0;

    public bool $open = true;

    public bool $inTransaction = false;

    public bool $failOnConnectIdentity = false;

    public bool $failBaselineFingerprint = false;

    public bool $failOnSqlContaining = false;

    public string $sqlFailureNeedle = '';

    public bool $failPostDdl = false;

    public bool $ambiguousCommit = false;

    public bool $timeoutBeforeCommit = false;

    public bool $connectionLost = false;

    /** @var array<string, bool> */
    private array $relations = [
        'public.users' => true,
        'public.app_user' => true,
        'public.user_app_user_map' => true,
        'public.calibration' => true,
        'public.calibration_session_setting' => true,
        'public.calibration_requirement_snapshot' => true,
        'public.calibration_environment_value' => true,
        'public.roles' => false,
        'public.user_roles' => false,
    ];

    private bool $ownerColumnPresent = false;

    private string $guardDefinition;

    public function __construct()
    {
        $this->guardDefinition = $this->baselineGuardDefinition();
    }

    public function applySafetyTimeouts(int $statementTimeoutMs, int $lockTimeoutMs, int $idleInTransactionMs): void
    {
        $this->assertOpen();
        $this->timeoutSettings[] = "statement_timeout={$statementTimeoutMs};lock_timeout={$lockTimeoutMs};idle_in_transaction_session_timeout={$idleInTransactionMs}";
    }

    public function beginTransaction(): void
    {
        $this->assertOpen();
        if ($this->connectionLost) {
            throw new StagingSqlDeploymentException('Connection lost before BEGIN.');
        }
        $this->beginCount++;
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        $this->assertOpen();
        if ($this->timeoutBeforeCommit) {
            throw new StagingSqlDeploymentException('statement timeout before COMMIT.');
        }
        if ($this->ambiguousCommit) {
            throw new StagingSqlDeploymentException('Connection lost during COMMIT.');
        }
        $this->commitCount++;
        $this->inTransaction = false;
    }

    public function rollBack(): void
    {
        if (! $this->open) {
            return;
        }
        $this->rollbackCount++;
        $this->inTransaction = false;
        // Restore pre-DDL baseline on rollback for deterministic tests.
        $this->relations['public.roles'] = false;
        $this->relations['public.user_roles'] = false;
        $this->ownerColumnPresent = false;
        $this->guardDefinition = $this->baselineGuardDefinition();
    }

    public function close(): void
    {
        $this->closeCount++;
        $this->open = false;
        $this->inTransaction = false;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function identitySnapshot(): StagingSqlIdentitySnapshot
    {
        $this->assertOpen();
        if ($this->failOnConnectIdentity) {
            return new StagingSqlIdentitySnapshot(
                currentDatabase: 'calibration_db',
                currentUser: 'postgres',
                serverAddress: '127.0.0.1/32',
                serverPort: 5432,
                transactionReadOnly: 'off',
                serverVersion: 'PostgreSQL fake',
            );
        }

        return new StagingSqlIdentitySnapshot(
            currentDatabase: ApprovedStagingSqlArtifacts::APPROVED_DATABASE,
            currentUser: 'fake_operator',
            serverAddress: '127.0.0.1/32',
            serverPort: ApprovedStagingSqlArtifacts::APPROVED_PORT,
            transactionReadOnly: 'off',
            serverVersion: 'PostgreSQL fake-offline',
        );
    }

    public function relationExists(string $schemaQualifiedName): bool
    {
        $this->assertOpen();

        return $this->relations[$schemaQualifiedName] ?? false;
    }

    public function columnMetadata(string $schema, string $table, string $column): ?array
    {
        $this->assertOpen();
        if ($schema === 'public' && $table === 'calibration' && $column === 'session_status') {
            return ['data_type' => 'character varying', 'is_nullable' => 'NO'];
        }
        if ($schema === 'public' && $table === 'app_user' && $column === 'app_user_id') {
            return ['data_type' => 'bigint', 'is_nullable' => 'NO'];
        }
        if ($schema === 'public' && $table === 'calibration' && $column === 'started_by_app_user_id') {
            if (! $this->ownerColumnPresent) {
                return null;
            }

            return ['data_type' => 'bigint', 'is_nullable' => 'YES'];
        }

        return null;
    }

    public function functionDefinition(string $schema, string $functionName): ?string
    {
        return $this->functionFingerprint($schema, $functionName)['definition'] ?? null;
    }

    public function functionFingerprint(string $schema, string $functionName): ?array
    {
        $this->assertOpen();
        if ($schema !== 'public' || $functionName !== 'trg_calibration_session_guard') {
            return null;
        }

        if ($this->failBaselineFingerprint) {
            $drifted = 'CREATE FUNCTION drifted() RETURNS trigger AS $$ BEGIN RETURN NEW; END; $$ LANGUAGE plpgsql;';

            return [
                'md5' => md5($drifted),
                'length' => strlen($drifted),
                'definition' => $drifted,
            ];
        }

        $definition = $this->guardDefinition;
        $immutable = str_contains($definition, 'calibration session owner is immutable after insert');

        return [
            'md5' => $immutable ? md5($definition) : ApprovedStagingSqlArtifacts::APPROVED_BASELINE_TRIGGER_MD5,
            'length' => $immutable ? strlen($definition) : ApprovedStagingSqlArtifacts::APPROVED_BASELINE_TRIGGER_LENGTH,
            'definition' => $definition,
        ];
    }

    public function triggersOnTable(string $schema, string $table, string $triggerName): array
    {
        $this->assertOpen();
        if ($schema !== 'public' || $table !== 'calibration' || $triggerName !== 'trg_calibration_session_guard') {
            return [];
        }

        return [[
            'name' => 'trg_calibration_session_guard',
            'enabled' => 'O',
            'definition' => 'CREATE TRIGGER trg_calibration_session_guard BEFORE INSERT OR DELETE OR UPDATE ON public.calibration FOR EACH ROW EXECUTE FUNCTION trg_calibration_session_guard()',
        ]];
    }

    public function roleCatalogNames(): array
    {
        $this->assertOpen();
        if (! ($this->relations['public.roles'] ?? false)) {
            return [];
        }

        return ['Administrator', 'Engineer', 'Viewer'];
    }

    public function foreignKeyDeleteAction(string $schema, string $table, string $column): ?string
    {
        $this->assertOpen();
        if ($schema === 'public' && $table === 'calibration' && $column === 'started_by_app_user_id' && $this->ownerColumnPresent) {
            return 'RESTRICT';
        }

        return null;
    }

    public function foreignKeyTarget(string $schema, string $table, string $column): ?string
    {
        $this->assertOpen();
        if ($schema === 'public' && $table === 'calibration' && $column === 'started_by_app_user_id' && $this->ownerColumnPresent) {
            return 'public.app_user(app_user_id)';
        }

        return null;
    }

    public function executeSql(string $sql): void
    {
        $this->assertOpen();
        if (! $this->inTransaction) {
            throw new StagingSqlDeploymentException('SQL execution requires an open transaction.');
        }
        if ($this->connectionLost) {
            throw new StagingSqlDeploymentException('Connection lost during SQL execution.');
        }
        if ($this->failOnSqlContaining && $this->sqlFailureNeedle !== '' && str_contains($sql, $this->sqlFailureNeedle)) {
            throw new StagingSqlDeploymentException('Simulated SQL execution failure.');
        }

        $this->executedStatements[] = $sql;
        $this->applySimulatedSchemaEffects($sql);
    }

    public function acquireDeploymentLock(): void
    {
        $this->assertOpen();
        $this->executedStatements[] = '-- advisory lock acquired (fake)';
    }

    public function isRealPostgreSql(): bool
    {
        return false;
    }

    public function markPostDdlBroken(): void
    {
        $this->failPostDdl = true;
    }

    public function shouldFailPostDdl(): bool
    {
        return $this->failPostDdl;
    }

    private function applySimulatedSchemaEffects(string $sql): void
    {
        if (str_contains($sql, 'CREATE TABLE public.roles')) {
            $this->relations['public.roles'] = true;
        }
        if (str_contains($sql, 'CREATE TABLE public.user_roles')) {
            $this->relations['public.user_roles'] = true;
        }
        if (str_contains($sql, 'ADD COLUMN started_by_app_user_id')) {
            $this->ownerColumnPresent = true;
        }
        if (str_contains($sql, 'calibration session owner is immutable after insert')) {
            $this->guardDefinition = $this->immutableGuardDefinition();
        }
    }

    private function assertOpen(): void
    {
        if (! $this->open) {
            throw new StagingSqlDeploymentException('Staging SQL session is closed.');
        }
    }

    private function baselineGuardDefinition(): string
    {
        // Length/markers aligned with approved baseline contract used by verifier.
        $body = <<<'SQL'
CREATE OR REPLACE FUNCTION public.trg_calibration_session_guard()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'calibration sessions cannot be deleted' USING ERRCODE = '25006';
    END IF;
    IF TG_OP = 'INSERT' THEN
        IF NEW.calibration_method IS NULL THEN
            RAISE EXCEPTION 'calibration method is required at start' USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.session_status IN ('completed', 'cancelled') THEN
        RAISE EXCEPTION 'calibration session % is terminal (%) and cannot be changed', OLD.calibration_id, OLD.session_status USING ERRCODE = '25006';
    END IF;
    IF NEW.calibration_method IS DISTINCT FROM OLD.calibration_method THEN
        RAISE EXCEPTION 'calibration method is immutable after start' USING ERRCODE = '25006';
    END IF;
    IF NEW.instrument_id IS DISTINCT FROM OLD.instrument_id OR NEW.instrument_type_id IS DISTINCT FROM OLD.instrument_type_id THEN
        RAISE EXCEPTION 'calibration session instrument type is immutable after start' USING ERRCODE = '25006';
    END IF;
    IF NEW.calibration_number IS DISTINCT FROM OLD.calibration_number THEN
        RAISE EXCEPTION 'calibration number cannot be changed' USING ERRCODE = '25006';
    END IF;
    IF OLD.session_status = 'in_progress' AND NEW.session_status = 'completed' THEN
        PERFORM public.calibration_session_assert_complete(NEW);
    ELSIF OLD.session_status = 'in_progress' AND NEW.session_status = 'cancelled' THEN
        IF NEW.cancelled_at IS NULL OR NEW.cancelled_reason IS NULL OR length(btrim(NEW.cancelled_reason)) = 0 THEN
            RAISE EXCEPTION 'cancellation requires a reason and timestamp' USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.session_status IS DISTINCT FROM OLD.session_status THEN
        RAISE EXCEPTION 'unsupported session status transition % -> %', OLD.session_status, NEW.session_status USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
SQL;

        return $this->padToLength($body, ApprovedStagingSqlArtifacts::APPROVED_BASELINE_TRIGGER_LENGTH);
    }

    private function immutableGuardDefinition(): string
    {
        $body = <<<'SQL'
CREATE OR REPLACE FUNCTION public.trg_calibration_session_guard()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'calibration sessions cannot be deleted' USING ERRCODE = '25006';
    END IF;
    IF TG_OP = 'INSERT' THEN
        IF NEW.calibration_method IS NULL THEN
            RAISE EXCEPTION 'calibration method is required at start' USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.session_status IN ('completed', 'cancelled') THEN
        RAISE EXCEPTION 'calibration session % is terminal (%) and cannot be changed', OLD.calibration_id, OLD.session_status USING ERRCODE = '25006';
    END IF;
    IF NEW.calibration_method IS DISTINCT FROM OLD.calibration_method THEN
        RAISE EXCEPTION 'calibration method is immutable after start' USING ERRCODE = '25006';
    END IF;
    IF NEW.instrument_id IS DISTINCT FROM OLD.instrument_id OR NEW.instrument_type_id IS DISTINCT FROM OLD.instrument_type_id THEN
        RAISE EXCEPTION 'calibration session instrument type is immutable after start' USING ERRCODE = '25006';
    END IF;
    IF NEW.calibration_number IS DISTINCT FROM OLD.calibration_number THEN
        RAISE EXCEPTION 'calibration number cannot be changed' USING ERRCODE = '25006';
    END IF;
    IF NEW.started_by_app_user_id IS DISTINCT FROM OLD.started_by_app_user_id THEN
        RAISE EXCEPTION 'calibration session owner is immutable after insert' USING ERRCODE = '25006';
    END IF;
    IF OLD.session_status = 'in_progress' AND NEW.session_status = 'completed' THEN
        PERFORM public.calibration_session_assert_complete(NEW);
    ELSIF OLD.session_status = 'in_progress' AND NEW.session_status = 'cancelled' THEN
        IF NEW.cancelled_at IS NULL OR NEW.cancelled_reason IS NULL OR length(btrim(NEW.cancelled_reason)) = 0 THEN
            RAISE EXCEPTION 'cancellation requires a reason and timestamp' USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.session_status IS DISTINCT FROM OLD.session_status THEN
        RAISE EXCEPTION 'unsupported session status transition % -> %', OLD.session_status, NEW.session_status USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
SQL;

        return $body;
    }

    private function padToLength(string $body, int $length): string
    {
        if (strlen($body) >= $length) {
            return $body;
        }

        return $body.str_repeat(' ', $length - strlen($body));
    }
}
