<?php

namespace App\CalibrationDueNotifications;

use App\Authorization\CalibrationNotificationRecipientResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CalibrationNotificationReconciliationOrchestrator
{
    public function __construct(
        private CalibrationPairEnumerator $enumerator,
        private CalibrationPairStateDiscovery $discovery,
        private CalibrationNotificationPersistence $persistence,
        private CalibrationNotificationRecipientResolver $recipients,
    ) {}

    public function reconcile(
        Connection $connection,
        CarbonInterface $evaluationDate,
        bool $dryRun = false,
    ): CalibrationNotificationReconciliationReport {
        $enumeration = $this->enumerator->enumerate($connection);

        if (! $enumeration->complete) {
            return new CalibrationNotificationReconciliationReport(
                complete: false,
                dryRun: $dryRun,
                processed: 0,
                created: 0,
                alreadyCurrent: 0,
                resolved: 0,
                skippedInvalid: 0,
                potentiallyStale: 0,
                outcomes: [],
                failures: [[
                    'category' => $enumeration->failureCategory ?? 'enumeration_incomplete',
                ]],
                failureCategory: $enumeration->failureCategory ?? 'enumeration_incomplete',
            );
        }

        $processed = 0;
        $created = 0;
        $alreadyCurrent = 0;
        $resolved = 0;
        $skippedInvalid = 0;
        $potentiallyStale = 0;
        $outcomes = [];
        $failures = [];

        foreach ($enumeration->pairs as $pair) {
            try {
                $state = $this->discovery->discoverPair(
                    $connection,
                    $pair->instrumentId,
                    $pair->instrumentTypeId,
                    $evaluationDate,
                );

                if (! $state->authoritative) {
                    $skippedInvalid++;
                    $outcomes[] = $this->outcome($pair, 'skipped_invalid');

                    continue;
                }

                $activeKeys = $this->persistence->activeEventKeysForPair(
                    $pair->instrumentId,
                    $pair->instrumentTypeId,
                    $connection,
                );
                $currentKey = $state->candidate?->eventKey;
                $staleCount = count(array_filter(
                    $activeKeys,
                    static fn (string $eventKey): bool => $eventKey !== $currentKey,
                ));
                $potentiallyStale += $staleCount;

                if ($dryRun) {
                    $outcomes[] = $this->outcome(
                        $pair,
                        $staleCount > 0 ? 'potentially_stale' : 'current',
                        $state,
                        $staleCount,
                        $this->recipients->eligibleUserIds($connection)->count(),
                    );
                    $processed++;

                    continue;
                }

                $result = $this->persistence->reconcileAuthoritativePair($state, $connection);
                $processed++;
                $created += $result['inserted'];
                $created += $result['revised'];
                $alreadyCurrent += $result['existing'];
                $resolved += $result['resolved'];
                $outcomes[] = $this->outcome(
                    $pair,
                    $result['inserted'] > 0 || $result['revised'] > 0
                        ? 'processed'
                        : ($result['resolved'] > 0 ? 'resolved' : 'already_current'),
                    $state,
                    $result['resolved'],
                );
            } catch (Throwable $exception) {
                $failure = [
                    'instrument_id' => $pair->instrumentId,
                    'instrument_type_id' => $pair->instrumentTypeId,
                    'category' => 'pair_failed',
                    'message' => $exception->getMessage(),
                ];
                $failures[] = $failure;
                Log::warning('Calibration notification pair reconciliation failed.', [
                    'instrument_id' => $pair->instrumentId,
                    'instrument_type_id' => $pair->instrumentTypeId,
                    'category' => 'pair_failed',
                ]);
            }
        }

        return new CalibrationNotificationReconciliationReport(
            complete: $failures === [],
            dryRun: $dryRun,
            processed: $processed,
            created: $created,
            alreadyCurrent: $alreadyCurrent,
            resolved: $resolved,
            skippedInvalid: $skippedInvalid,
            potentiallyStale: $potentiallyStale,
            outcomes: $outcomes,
            failures: $failures,
        );
    }

    /** @return array<string, mixed> */
    private function outcome(
        AuthoritativeCalibrationPair $pair,
        string $status,
        ?CalibrationNotificationPairState $state = null,
        int $staleCount = 0,
        int $recipientCount = 0,
    ): array {
        return [
            'instrument_id' => $pair->instrumentId,
            'instrument_type_id' => $pair->instrumentTypeId,
            'status' => $status,
            'pair_status' => $state?->status,
            'event_type' => $state?->candidate?->eventType,
            'event_key' => $state?->candidate?->eventKey,
            'potentially_stale' => $staleCount,
            'eligible_recipient_count' => $recipientCount,
        ];
    }
}
