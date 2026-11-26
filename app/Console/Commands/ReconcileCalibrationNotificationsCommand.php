<?php

namespace App\Console\Commands;

use App\CalibrationDueNotifications\CalibrationNotificationReconciliationOrchestrator;
use App\CalibrationSessions\CalibrationSessionConnection;
use App\StagingRuntime\StagingRuntimeIsolation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('calibration:reconcile-notifications {--dry-run : Inspect expected operations without persisting changes}')]
#[Description('Reconcile persistent calibration notification occurrences.')]
final class ReconcileCalibrationNotificationsCommand extends Command
{
    public function handle(
        CalibrationSessionConnection $sessionConnection,
        CalibrationNotificationReconciliationOrchestrator $orchestrator,
        StagingRuntimeIsolation $isolation,
    ): int {
        if (! $isolation->notificationReconciliationEnabled(
            config(),
            app()->configurationIsCached(),
            app()->getCachedConfigPath(),
        )) {
            $this->comment('Calibration notification reconciliation is disabled.');

            return self::SUCCESS;
        }

        try {
            $connection = $sessionConnection->resolveNamed(CalibrationSessionConnection::STAGING_CONNECTION);
            $timezone = (string) config('calibration_notification_reconciliation.timezone', 'Asia/Jakarta');
            $report = $orchestrator->reconcile(
                $connection,
                CarbonImmutable::now($timezone),
                (bool) $this->option('dry-run'),
            );
        } catch (Throwable $exception) {
            $this->error('Calibration notification reconciliation could not be processed safely.');
            report($exception);

            return self::FAILURE;
        }

        $mode = $report->dryRun ? 'Preview' : 'Reconciliation';
        $this->line(sprintf(
            '%s processed %d pair(s); created %d occurrence(s); resolved %d occurrence(s); potentially stale %d.',
            $mode,
            $report->processed,
            $report->created,
            $report->resolved,
            $report->potentiallyStale,
        ));

        if (! $report->succeeded()) {
            $this->error('Reconciliation was incomplete and must be retried.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
