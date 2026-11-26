<?php

namespace App\CalibrationDueNotifications;

use App\Authorization\CalibrationNotificationRecipientResolver;
use App\Models\CalibrationNotification;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CalibrationNotificationPersistence
{
    public function __construct(
        private CalibrationNotificationRecipientResolver $recipients,
    ) {}

    /**
     * Persist currently eligible candidates for every eligible recipient.
     *
     * This intentionally does not resolve absent candidates. The discovery
     * adapter is not declared as an authoritative full-scope snapshot here.
     *
     * @param  iterable<CalibrationNotificationCandidate>  $candidates
     * @return array{inserted: int, existing: int, revised: int, historical_reentry: list<string>}
     */
    public function reconcile(iterable $candidates, ?Connection $connection = null): array
    {
        $candidates = $this->normalizeCandidates($candidates);
        $recipientIds = $this->recipients->eligibleUserIds($connection)->all();

        if ($candidates->isEmpty() || $recipientIds === []) {
            return ['inserted' => 0, 'existing' => 0, 'revised' => 0, 'historical_reentry' => []];
        }

        return $this->transaction($connection, function () use ($candidates, $recipientIds, $connection): array {
            $existing = 0;
            $inserted = 0;
            $revised = 0;

            foreach ($recipientIds as $recipientId) {
                foreach ($candidates as $candidate) {
                    [$wasInserted, $wasRevised] = $this->persistCandidateForRecipient(
                        $candidate,
                        $recipientId,
                        $connection,
                    );
                    $inserted += $wasInserted;
                    $revised += $wasRevised;
                    $existing += $wasInserted === 0 && $wasRevised === 0 ? 1 : 0;
                }
            }

            return [
                'inserted' => $inserted,
                'existing' => $existing,
                'revised' => $revised,
                'historical_reentry' => [],
            ];
        });
    }

    /**
     * Reconcile one pair only after its source state has been conclusively
     * resolved. Non-authoritative states perform no writes.
     *
     * @return array{status: string, inserted: int, existing: int, resolved: int, revised: int}
     */
    public function reconcileAuthoritativePair(
        CalibrationNotificationPairState $state,
        ?Connection $connection = null,
    ): array {
        if (! $state->authoritative) {
            return [
                'status' => 'not_authoritative',
                'inserted' => 0,
                'existing' => 0,
                'resolved' => 0,
                'revised' => 0,
            ];
        }

        $recipientIds = $this->recipients->eligibleUserIds($connection)->all();

        return $this->transaction($connection, function () use ($state, $recipientIds, $connection): array {
            $result = [
                'status' => 'reconciled',
                'inserted' => 0,
                'existing' => 0,
                'resolved' => $this->resolveObsoleteForPair(
                    $state->instrumentId,
                    $state->instrumentTypeId,
                    $state->candidate?->eventKey,
                    $state->candidate?->sourceCalibrationId,
                    connection: $connection,
                ),
                'revised' => 0,
            ];

            foreach ($recipientIds as $recipientId) {
                if ($state->candidate === null) {
                    continue;
                }

                [$inserted, $revised] = $this->persistCandidateForRecipient(
                    $state->candidate,
                    $recipientId,
                    $connection,
                );
                $result['inserted'] += $inserted;
                $result['revised'] += $revised;
                $result['existing'] += $inserted === 0 && $revised === 0 ? 1 : 0;
            }

            return $result;
        });
    }

    /**
     * Resolve active occurrences for one pair only after the caller has an
     * authoritative current state for that pair.
     */
    public function resolvePair(
        int $instrumentId,
        int $instrumentTypeId,
        ?string $currentEventKey,
        ?int $resolutionCalibrationId = null,
        ?Connection $connection = null,
    ): int {
        return $this->resolveObsoleteForPair(
            $instrumentId,
            $instrumentTypeId,
            $currentEventKey,
            $resolutionCalibrationId,
            connection: $connection,
        );
    }

    public function unreadCountFor(User $recipient, ?Connection $connection = null): int
    {
        return $this->notificationModel($connection)
            ->newQuery()
            ->where('recipient_user_id', $this->recipientId($recipient))
            ->active()
            ->unread()
            ->count();
    }

    /** @return Collection<int, CalibrationNotification> */
    public function latestActiveFor(
        User $recipient,
        int $limit = 5,
        ?Connection $connection = null,
    ): Collection {
        return $this->notificationModel($connection)
            ->newQuery()
            ->where('recipient_user_id', $this->recipientId($recipient))
            ->active()
            ->latest('created_at')
            ->latest('calibration_notification_id')
            ->limit(max(1, $limit))
            ->get();
    }

    public function markAsRead(
        User $recipient,
        int $notificationId,
        ?Connection $connection = null,
    ): bool {
        return $this->notificationModel($connection)
            ->newQuery()
            ->whereKey($notificationId)
            ->where('recipient_user_id', $this->recipientId($recipient))
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]) > 0;
    }

    public function markAllActiveAsRead(User $recipient, ?Connection $connection = null): int
    {
        return $this->notificationModel($connection)
            ->newQuery()
            ->where('recipient_user_id', $this->recipientId($recipient))
            ->active()
            ->unread()
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** @return list<string> */
    public function activeEventKeysForPair(
        int $instrumentId,
        int $instrumentTypeId,
        ?Connection $connection = null,
    ): array {
        $pairPrefix = sprintf('v1|instrument:%d|type:%d|', $instrumentId, $instrumentTypeId);

        return $this->notificationModel($connection)
            ->newQuery()
            ->where(function ($query) use ($instrumentId, $instrumentTypeId, $pairPrefix): void {
                $query
                    ->where(function ($query) use ($instrumentId, $instrumentTypeId): void {
                        $query->where('instrument_id', $instrumentId)
                            ->where('instrument_type_id', $instrumentTypeId);
                    })
                    ->orWhere('event_key', 'like', $pairPrefix.'%');
            })
            ->active()
            ->pluck('event_key')
            ->filter(fn (mixed $eventKey): bool => is_string($eventKey))
            ->values()
            ->all();
    }

    /** @param iterable<CalibrationNotificationCandidate> $candidates */
    private function normalizeCandidates(iterable $candidates): Collection
    {
        return collect($candidates)
            ->filter(fn (mixed $candidate): bool => $candidate instanceof CalibrationNotificationCandidate)
            ->unique('eventKey')
            ->values();
    }

    /** @return array{0: int, 1: int} */
    private function persistCandidateForRecipient(
        CalibrationNotificationCandidate $candidate,
        int $recipientId,
        ?Connection $connection = null,
    ): array {
        $model = $this->notificationModel($connection);
        $matching = $model->newQuery()
            ->where('recipient_user_id', $recipientId)
            ->where('event_key', $candidate->eventKey)
            ->get(['resolved_at', 'occurrence_revision']);

        if ($matching->contains(fn (CalibrationNotification $notification): bool => $notification->resolved_at === null)) {
            return [0, 0];
        }

        $revision = ((int) $matching->max('occurrence_revision')) + 1;
        $revised = $matching->isNotEmpty() ? 1 : 0;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $inserted = $model->newQuery()->insertOrIgnore([[
                'recipient_user_id' => $recipientId,
                'instrument_id' => $candidate->instrumentId,
                'instrument_type_id' => $candidate->instrumentTypeId,
                'source_calibration_id' => $candidate->sourceCalibrationId,
                'event_type' => $candidate->eventType,
                'event_key' => $candidate->eventKey,
                'occurrence_revision' => $revision,
                'instrument_name_snapshot' => $candidate->instrumentNameSnapshot,
                'instrument_type_snapshot' => $candidate->instrumentTypeSnapshot,
                'serial_number_snapshot' => $candidate->serialNumberSnapshot,
                'due_date_snapshot' => $candidate->dueDate->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]]);

            if ($inserted === 1) {
                return [1, $revised];
            }

            if ($model->newQuery()
                ->where('recipient_user_id', $recipientId)
                ->where('event_key', $candidate->eventKey)
                ->whereNull('resolved_at')
                ->exists()) {
                return [0, 0];
            }

            $revision = ((int) $model->newQuery()
                ->where('recipient_user_id', $recipientId)
                ->where('event_key', $candidate->eventKey)
                ->max('occurrence_revision')) + 1;
            $revised = 1;
        }

        throw new \RuntimeException('Calibration notification occurrence could not be persisted safely.');
    }

    private function resolveObsoleteForPair(
        int $instrumentId,
        int $instrumentTypeId,
        ?string $currentEventKey,
        ?int $resolutionCalibrationId,
        ?int $recipientId = null,
        ?Connection $connection = null,
    ): int {
        $pairPrefix = sprintf('v1|instrument:%d|type:%d|', $instrumentId, $instrumentTypeId);

        return $this->notificationModel($connection)
            ->newQuery()
            ->where(function ($query) use ($instrumentId, $instrumentTypeId, $pairPrefix): void {
                $query
                    ->where(function ($query) use ($instrumentId, $instrumentTypeId): void {
                        $query->where('instrument_id', $instrumentId)
                            ->where('instrument_type_id', $instrumentTypeId);
                    })
                    ->orWhere('event_key', 'like', $pairPrefix.'%');
            })
            ->active()
            ->when($recipientId !== null, fn ($query) => $query->where('recipient_user_id', $recipientId))
            ->when($currentEventKey !== null, fn ($query) => $query->where('event_key', '!=', $currentEventKey))
            ->update([
                'resolved_at' => now(),
                'resolution_calibration_id' => $resolutionCalibrationId,
                'updated_at' => now(),
            ]);
    }

    private function notificationModel(?Connection $connection = null): CalibrationNotification
    {
        if ($connection === null) {
            return new CalibrationNotification;
        }

        $name = $connection->getName();

        if (! is_string($name) || $name === '') {
            throw new \InvalidArgumentException('Explicit notification persistence requires a named connection.');
        }

        return (new CalibrationNotification)->setConnection($name);
    }

    private function transaction(?Connection $connection, \Closure $callback): mixed
    {
        return ($connection ?? DB::connection())->transaction($callback);
    }

    private function recipientId(User $recipient): int
    {
        $recipientId = (int) $recipient->getKey();

        if ($recipientId < 1) {
            throw new \InvalidArgumentException('A persisted recipient user is required.');
        }

        return $recipientId;
    }
}
