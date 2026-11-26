<?php

namespace App\CalibrationDueNotifications;

use App\Dashboard\CalibrationStanding;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CalibrationNotificationCandidateEngine
{
    public const EVENT_DUE_SOON = 'due_soon';

    public const EVENT_OVERDUE = 'overdue';

    public const EVENT_KEY_VERSION = 'v1';

    /** @param object|array<string, mixed> $source */
    public function fromSource(object|array $source, CarbonInterface $evaluationDate): ?CalibrationNotificationCandidate
    {
        $value = static fn (string $key): mixed => is_array($source)
            ? ($source[$key] ?? null)
            : ($source->{$key} ?? null);

        $instrumentId = $this->positiveInteger($value('instrument_id'));
        $instrumentTypeId = $this->positiveInteger($value('instrument_type_id'));
        $sourceCalibrationId = $this->positiveInteger($value('latest_calibration_id'));
        $instrumentName = $this->nonEmptyString($value('instrument_name'));
        $instrumentType = $this->nonEmptyString($value('instrument_type'));
        $dueDate = $this->parseDueDate($value('calibration_due'));

        if ($instrumentId === null
            || $instrumentTypeId === null
            || $sourceCalibrationId === null
            || $instrumentName === null
            || $instrumentType === null
            || $dueDate === null) {
            return null;
        }

        $standing = CalibrationStanding::fromDueDate($dueDate, $evaluationDate);
        $eventType = match ($standing) {
            CalibrationStanding::DueSoon => self::EVENT_DUE_SOON,
            CalibrationStanding::Overdue => self::EVENT_OVERDUE,
            default => null,
        };

        if ($eventType === null) {
            return null;
        }

        return new CalibrationNotificationCandidate(
            instrumentId: $instrumentId,
            instrumentTypeId: $instrumentTypeId,
            sourceCalibrationId: $sourceCalibrationId,
            eventType: $eventType,
            eventKey: self::eventKey(
                instrumentId: $instrumentId,
                instrumentTypeId: $instrumentTypeId,
                sourceCalibrationId: $sourceCalibrationId,
                dueDate: $dueDate,
                eventType: $eventType,
            ),
            dueDate: $dueDate,
            instrumentNameSnapshot: $instrumentName,
            instrumentTypeSnapshot: $instrumentType,
            serialNumberSnapshot: $this->nullableString($value('serial_number')),
        );
    }

    /** @param iterable<object|array<string, mixed>> $sources */
    public function fromSources(iterable $sources, CarbonInterface $evaluationDate): Collection
    {
        return collect($sources)
            ->map(fn (object|array $source): ?CalibrationNotificationCandidate => $this->fromSource($source, $evaluationDate))
            ->filter()
            ->sortBy(fn (CalibrationNotificationCandidate $candidate): string => sprintf(
                '%020d:%020d',
                $candidate->instrumentId,
                $candidate->instrumentTypeId,
            ))
            ->values();
    }

    public static function eventKey(
        int $instrumentId,
        int $instrumentTypeId,
        int $sourceCalibrationId,
        CarbonInterface $dueDate,
        string $eventType,
    ): string {
        if ($instrumentId < 1 || $instrumentTypeId < 1 || $sourceCalibrationId < 1) {
            throw new \InvalidArgumentException('Calibration notification event identifiers must be positive.');
        }

        if (! in_array($eventType, [self::EVENT_DUE_SOON, self::EVENT_OVERDUE], true)) {
            throw new \InvalidArgumentException('Unsupported calibration notification event type.');
        }

        return sprintf(
            '%s|instrument:%d|type:%d|cycle:%d|due:%s|event:%s',
            self::EVENT_KEY_VERSION,
            $instrumentId,
            $instrumentTypeId,
            $sourceCalibrationId,
            CarbonImmutable::instance($dueDate)->setTimezone('Asia/Jakarta')->toDateString(),
            $eventType,
        );
    }

    private function parseDueDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) && ! $value instanceof CarbonInterface) {
            return null;
        }

        try {
            return $value instanceof CarbonInterface
                ? CarbonImmutable::instance($value)->setTimezone('Asia/Jakarta')->startOfDay()
                : CarbonImmutable::parse($value, 'Asia/Jakarta')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
