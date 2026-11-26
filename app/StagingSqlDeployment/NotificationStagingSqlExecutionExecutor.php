<?php

namespace App\StagingSqlDeployment;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Offline-testable orchestration boundary for a future controlled deployment.
 * No command, route, scheduler, or service provider wires this executor.
 */
final class NotificationStagingSqlExecutionExecutor
{
    public const STATEMENT_TIMEOUT_MS = 30000;

    public const LOCK_TIMEOUT_MS = 5000;

    public const IDLE_IN_TRANSACTION_MS = 60000;

    public function __construct(
        private StagingSqlConnectionFactory $connectionFactory,
        private NotificationStagingSqlExecutionVerifier $verifier,
        private NotificationStagingSqlApprovalReplayProtection $replayProtection,
        private StagingSqlDeploymentGateValidator $gates = new StagingSqlDeploymentGateValidator,
        private NotificationStagingSqlBackupVerifier $backupVerifier = new NotificationStagingSqlBackupVerifier,
    ) {}

    public function execute(
        NotificationStagingSqlExecutionPlan $plan,
        NotificationStagingSqlDeploymentEvidence $evidence,
        string $backupPath,
        NotificationStagingSqlApprovalClaims $claims,
        ?NotificationStagingSqlApprovalAuthority $approvalAuthority,
    ): NotificationStagingSqlExecutionOutcome {
        $target = new StagingSqlDeploymentTarget(
            $plan->package->targetHost,
            $plan->package->targetPort,
            $plan->package->targetDatabase,
        );

        try {
            $this->gates->assertTargetAllowed($target);
            $evidence->assertBinding($plan->package);
            $claims->assertMatches($plan->package, $evidence, new DateTimeImmutable('now', new DateTimeZone('UTC')));

            if ($approvalAuthority === null) {
                return NotificationStagingSqlExecutionOutcome::blocked(
                    'Trusted notification approval authority is unavailable.'
                );
            }

            $approvalAuthority->assertAuthentic($claims);
            $this->backupVerifier->assertFileMatches($backupPath, $evidence->backupSha256);
        } catch (StagingSqlDeploymentException $exception) {
            return NotificationStagingSqlExecutionOutcome::blocked($exception->getMessage());
        }

        $session = null;
        $reservation = null;
        $lockAcquired = false;

        try {
            $reservation = $this->replayProtection->reserve($claims);
            $this->gates->assertNoDefaultConnectionFallback(usingDefaultConnection: false);
            $session = $this->connectionFactory->connect($target);
            $session->applySafetyTimeouts(
                self::STATEMENT_TIMEOUT_MS,
                self::LOCK_TIMEOUT_MS,
                self::IDLE_IN_TRANSACTION_MS,
            );
            $session->beginTransaction();
            $this->assertLiveIdentity($session, $target);
            $session->acquireDeploymentLock();
            $lockAcquired = true;
            $this->verifier->assertPreDeploymentReady($plan->package, $target, $session);

            foreach ($plan->statements as $statement) {
                $session->executeSql($statement);
            }

            $this->verifier->assertPostDeploymentReady($plan->package, $target, $session);
            $session->commit();
            $session->close();

            try {
                $reservation->markCommitted();
            } catch (Throwable) {
                $reservation->markAmbiguous();

                return NotificationStagingSqlExecutionOutcome::ambiguousCommit(
                    'Notification deployment committed but approval consumption is ambiguous. No automatic retry is permitted.'
                );
            }

            return NotificationStagingSqlExecutionOutcome::committed();
        } catch (StagingSqlDeploymentException $exception) {
            return $this->failClosed($session, $reservation, $exception, $lockAcquired);
        } catch (Throwable $exception) {
            return $this->failClosed(
                $session,
                $reservation,
                new StagingSqlDeploymentException('Unexpected notification deployment failure.', 0, $exception),
                $lockAcquired,
            );
        }
    }

    private function assertLiveIdentity(
        StagingSqlSession $session,
        StagingSqlDeploymentTarget $target,
    ): void {
        $identity = $session->identitySnapshot();
        $address = preg_replace('#/\d+$#', '', $identity->serverAddress) ?: $identity->serverAddress;

        if ($identity->currentDatabase !== $target->database
            || $address !== $target->host
            || $identity->serverPort !== $target->port
            || strtolower($identity->transactionReadOnly) === 'on') {
            throw new StagingSqlDeploymentException('Live notification deployment identity is not approved staging.');
        }
    }

    private function failClosed(
        ?StagingSqlSession $session,
        ?NotificationStagingSqlApprovalReservation $reservation,
        StagingSqlDeploymentException $exception,
        bool $lockAcquired,
    ): NotificationStagingSqlExecutionOutcome {
        $message = $exception->getMessage();
        $ambiguous = str_contains(strtolower($message), 'during commit');

        if ($session === null && $reservation === null) {
            return NotificationStagingSqlExecutionOutcome::blocked($message);
        }

        if ($session !== null && $session->isOpen()) {
            if (! $ambiguous) {
                try {
                    $session->rollBack();
                } catch (Throwable) {
                    // Closing the session remains the final safety action.
                }
            }

            try {
                $session->close();
            } catch (Throwable) {
                // Preserve the original deployment result.
            }
        }

        if ($reservation !== null && ! $ambiguous) {
            try {
                $reservation->releaseAfterRollback();
            } catch (Throwable) {
                return NotificationStagingSqlExecutionOutcome::blocked(
                    'Notification approval reservation could not be safely released. Retry requires independent review.'
                );
            }
        }

        if ($reservation !== null && $ambiguous) {
            try {
                $reservation->markAmbiguous();
            } catch (Throwable) {
                // The ambiguous result remains fail-closed.
            }
        }

        if ($ambiguous) {
            return NotificationStagingSqlExecutionOutcome::ambiguousCommit(
                'Ambiguous notification deployment commit. No automatic retry is permitted.'
            );
        }

        if (str_contains(strtolower($message), 'post-deployment')) {
            return NotificationStagingSqlExecutionOutcome::postDeploymentFailed($message);
        }

        if (str_contains(strtolower($message), 'pre-deployment')) {
            return NotificationStagingSqlExecutionOutcome::preDeploymentFailed($message);
        }

        return NotificationStagingSqlExecutionOutcome::rolledBack($message, $session !== null, $lockAcquired);
    }
}
