<?php

namespace App\StagingSqlDeployment;

/**
 * Atomic executor scoped to the single notification-history delta package.
 */
final class DeltaStagingSqlExecutionAdapter
{
    public const STATEMENT_TIMEOUT_MS = 30000;

    public const LOCK_TIMEOUT_MS = 5000;

    public const IDLE_IN_TRANSACTION_MS = 60000;

    public function __construct(
        private StagingSqlConnectionFactory $connectionFactory,
        private DeltaStagingSqlDeploymentGateValidator $gates = new DeltaStagingSqlDeploymentGateValidator,
        private DeltaStagingSqlBaselineVerifier $baselineVerifier = new DeltaStagingSqlBaselineVerifier,
        private DeltaStagingSqlPostDdlVerifier $postDdlVerifier = new DeltaStagingSqlPostDdlVerifier,
        private StagingSqlScriptParser $parser = new StagingSqlScriptParser,
    ) {}

    public function execute(
        DeltaAtomicStagingSqlPackage $package,
        DeltaStagingSqlReleaseApproval $approval,
        StagingSqlDeploymentTarget $target,
        StagingSqlDeploymentAuthorization $authorization,
    ): StagingSqlExecutionOutcome {
        $session = null;

        try {
            $this->gates->assertPackageReady($package, $approval);
            $this->gates->assertTargetAllowed($target);
            $this->gates->assertAuthorization($authorization, $approval);
            $this->gates->assertAuthorizationMatchesTarget($authorization, $target);
            $this->gates->assertNoDefaultConnectionFallback(usingDefaultConnection: false);

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
            $this->baselineVerifier->assertReadyForDdl($session, $target);

            foreach ($package->orderedBodySql() as $body) {
                foreach ($this->parser->splitStatements($body) as $statement) {
                    $session->executeSql($statement);
                }
            }

            $this->postDdlVerifier->assertReadyToCommit($session);
            $session->commit();
            $outcome = StagingSqlExecutionOutcome::committed(
                'Atomic notification-history delta committed after post-DDL verification.',
                $session->isRealPostgreSql(),
            );
            $session->close();

            return $outcome;
        } catch (StagingSqlDeploymentException $exception) {
            return $this->failClosed($session, $exception);
        } catch (\Throwable $exception) {
            return $this->failClosed(
                $session,
                new StagingSqlDeploymentException('Unexpected delta adapter failure.', 0, $exception),
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
                // Ignore close failures after the primary failure.
            }
        }

        if ($ambiguous) {
            return StagingSqlExecutionOutcome::ambiguousCommit(
                'AMBIGUOUS_COMMIT: '.$message.' No automatic retry; require fresh read-only inventory.',
            );
        }

        if (str_contains(strtolower($message), 'post-ddl verification')) {
            return StagingSqlExecutionOutcome::verificationFailed($message);
        }

        return StagingSqlExecutionOutcome::rolledBack($message);
    }
}
