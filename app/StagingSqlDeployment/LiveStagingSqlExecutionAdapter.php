<?php

namespace App\StagingSqlDeployment;

/**
 * Live-capable atomic adapter. Performs no constructor I/O.
 *
 * Connection acquisition occurs only through an injected factory during
 * {@see execute()}. In 21Z-C, tests inject {@see FakeStagingSqlConnectionFactory};
 * {@see SealedPdoStagingSqlConnectionFactory} refuses real PostgreSQL access.
 */
final class LiveStagingSqlExecutionAdapter implements StagingSqlExecutionAdapter
{
    public const STATEMENT_TIMEOUT_MS = 30000;

    public const LOCK_TIMEOUT_MS = 5000;

    public const IDLE_IN_TRANSACTION_MS = 60000;

    public function __construct(
        private StagingSqlConnectionFactory $connectionFactory,
        private StagingSqlDeploymentGateValidator $gates = new StagingSqlDeploymentGateValidator,
        private StagingSqlBaselineVerifier $baselineVerifier = new StagingSqlBaselineVerifier,
        private StagingSqlPostDdlVerifier $postDdlVerifier = new StagingSqlPostDdlVerifier,
        private StagingSqlScriptParser $parser = new StagingSqlScriptParser,
    ) {}

    public function execute(
        AtomicStagingSqlPackage $package,
        StagingSqlDeploymentTarget $target,
        StagingSqlDeploymentAuthorization $authorization,
    ): StagingSqlExecutionOutcome {
        $session = null;

        try {
            // Gates before any connection acquisition.
            $this->gates->assertPackageReady($package);
            $this->gates->assertTargetAllowed($target);
            $this->gates->assertAuthorization($authorization);
            $this->gates->assertAuthorizationMatchesTarget($authorization, $target);
            $this->gates->assertNoDefaultConnectionFallback(usingDefaultConnection: false);
            $this->gates->assertBodiesContainNoTopLevelCommit($package->orderedBodySql(), $this->parser);

            if (! StagingSqlAtomicExecutionSteps::commitIsLastMutableStep()) {
                throw new StagingSqlDeploymentException('Atomic execution step contract is invalid.');
            }

            $session = $this->connectionFactory->connect($target);
            $session->applySafetyTimeouts(
                self::STATEMENT_TIMEOUT_MS,
                self::LOCK_TIMEOUT_MS,
                self::IDLE_IN_TRANSACTION_MS,
            );
            $session->beginTransaction();
            $this->baselineVerifier->assertReadyForDdl($session, $target);
            $session->acquireDeploymentLock();
            // Re-verify identity inside the write transaction.
            $this->baselineVerifier->assertReadyForDdl($session, $target);

            foreach ($package->orderedBodySql() as $body) {
                foreach ($this->parser->splitStatements($body) as $statement) {
                    $session->executeSql($statement);
                }
            }

            $this->postDdlVerifier->assertReadyToCommit($session);
            $session->commit();

            $outcome = StagingSqlExecutionOutcome::committed(
                'Atomic staging SQL package committed after post-DDL verification.',
                $session->isRealPostgreSql(),
            );
            $session->close();

            return $outcome;
        } catch (StagingSqlDeploymentException $exception) {
            return $this->failClosed($session, $exception);
        } catch (\Throwable $exception) {
            return $this->failClosed(
                $session,
                new StagingSqlDeploymentException('Unexpected staging SQL adapter failure.', 0, $exception),
            );
        }
    }

    private function failClosed(?StagingSqlSession $session, StagingSqlDeploymentException $exception): StagingSqlExecutionOutcome
    {
        $message = $exception->getMessage();
        $ambiguous = str_contains(strtolower($message), 'during commit');

        if ($session !== null && $session->isOpen()) {
            if (! $ambiguous) {
                try {
                    $session->rollBack();
                } catch (\Throwable) {
                    // Still close below.
                }
            }
            try {
                $session->close();
            } catch (\Throwable) {
                // ignore
            }
        }

        if ($ambiguous) {
            return StagingSqlExecutionOutcome::ambiguousCommit(
                'AMBIGUOUS_COMMIT: '.$message.' No automatic retry. Require fresh read-only inventory under separate authorization.'
            );
        }

        if (str_contains(strtolower($message), 'post-ddl verification')) {
            return StagingSqlExecutionOutcome::verificationFailed($message);
        }

        return StagingSqlExecutionOutcome::rolledBack($message);
    }
}
