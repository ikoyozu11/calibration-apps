<?php

namespace App\CalibrationDueNotifications;

final readonly class CalibrationNotificationPairState
{
    public const STATUS_DUE_SOON = 'due_soon';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_ON_TRACK = 'on_track';

    public const STATUS_NOT_CALIBRATED = 'not_calibrated';

    public const STATUS_TARGET_MISSING = 'target_missing';

    public const STATUS_SOURCE_INVALID = 'source_invalid';

    private function __construct(
        public int $instrumentId,
        public int $instrumentTypeId,
        public string $status,
        public bool $authoritative,
        public ?CalibrationNotificationCandidate $candidate,
    ) {}

    public static function fromCandidate(CalibrationNotificationCandidate $candidate): self
    {
        return new self(
            instrumentId: $candidate->instrumentId,
            instrumentTypeId: $candidate->instrumentTypeId,
            status: $candidate->eventType,
            authoritative: true,
            candidate: $candidate,
        );
    }

    public static function onTrack(int $instrumentId, int $instrumentTypeId): self
    {
        return self::withoutCandidate($instrumentId, $instrumentTypeId, self::STATUS_ON_TRACK);
    }

    public static function notCalibrated(int $instrumentId, int $instrumentTypeId): self
    {
        return self::withoutCandidate($instrumentId, $instrumentTypeId, self::STATUS_NOT_CALIBRATED);
    }

    public static function targetMissing(int $instrumentId, int $instrumentTypeId): self
    {
        return self::withoutCandidate($instrumentId, $instrumentTypeId, self::STATUS_TARGET_MISSING);
    }

    public static function sourceInvalid(int $instrumentId, int $instrumentTypeId): self
    {
        return new self(
            instrumentId: $instrumentId,
            instrumentTypeId: $instrumentTypeId,
            status: self::STATUS_SOURCE_INVALID,
            authoritative: false,
            candidate: null,
        );
    }

    private static function withoutCandidate(int $instrumentId, int $instrumentTypeId, string $status): self
    {
        return new self(
            instrumentId: $instrumentId,
            instrumentTypeId: $instrumentTypeId,
            status: $status,
            authoritative: true,
            candidate: null,
        );
    }
}
