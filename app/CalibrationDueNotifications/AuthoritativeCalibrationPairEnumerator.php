<?php

namespace App\CalibrationDueNotifications;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AuthoritativeCalibrationPairEnumerator implements CalibrationPairEnumerator
{
    public const DEFAULT_CHUNK_SIZE = 250;

    public const MAX_CHUNK_SIZE = 1000;

    public function __construct(
        public int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {}

    public static function fromConfiguredValue(mixed $value): self
    {
        $chunkSize = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($chunkSize === false) {
            $chunkSize = self::DEFAULT_CHUNK_SIZE;
        }

        return new self(min($chunkSize, self::MAX_CHUNK_SIZE));
    }

    public function enumerate(Connection $connection): AuthoritativeCalibrationPairEnumeration
    {
        try {
            $pairs = $this->currentPairs($connection);
            $storedPairs = $this->activeStoredPairs($connection);

            return AuthoritativeCalibrationPairEnumeration::complete(
                $pairs->merge($storedPairs)
                    ->unique(fn (AuthoritativeCalibrationPair $pair): string => $pair->key())
                    ->sortBy(fn (AuthoritativeCalibrationPair $pair): string => sprintf(
                        '%020d:%020d',
                        $pair->instrumentId,
                        $pair->instrumentTypeId,
                    ))
                    ->values(),
            );
        } catch (Throwable) {
            return AuthoritativeCalibrationPairEnumeration::incomplete('enumeration_failed');
        }
    }

    /** @return Collection<int, AuthoritativeCalibrationPair> */
    private function currentPairs(Connection $connection): Collection
    {
        $pairs = collect();
        $lastInstrumentId = 0;
        $lastInstrumentTypeId = 0;
        $chunkSize = max(1, $this->chunkSize);

        do {
            $query = $connection->table('instrument_type as it')
                ->join('instrument as i', 'i.instrument_id', '=', 'it.instrument_id')
                ->select(['i.instrument_id', 'it.instrument_type_id'])
                ->where(function ($query) use ($lastInstrumentId, $lastInstrumentTypeId): void {
                    $query->where('i.instrument_id', '>', $lastInstrumentId)
                        ->orWhere(function ($query) use ($lastInstrumentId, $lastInstrumentTypeId): void {
                            $query->where('i.instrument_id', $lastInstrumentId)
                                ->where('it.instrument_type_id', '>', $lastInstrumentTypeId);
                        });
                })
                ->orderBy('i.instrument_id')
                ->orderBy('it.instrument_type_id')
                ->limit($chunkSize)
                ->get();

            foreach ($query as $row) {
                $instrumentId = (int) $row->instrument_id;
                $instrumentTypeId = (int) $row->instrument_type_id;

                if ($instrumentId < 1 || $instrumentTypeId < 1) {
                    throw new \UnexpectedValueException('Invalid calibration pair identifier.');
                }

                $pairs->push(new AuthoritativeCalibrationPair($instrumentId, $instrumentTypeId));
                $lastInstrumentId = $instrumentId;
                $lastInstrumentTypeId = $instrumentTypeId;
            }
        } while ($query->isNotEmpty());

        return $pairs;
    }

    /** @return Collection<int, AuthoritativeCalibrationPair> */
    private function activeStoredPairs(Connection $connection): Collection
    {
        return $connection->table('calibration_notifications')
            ->whereNull('resolved_at')
            ->pluck('event_key')
            ->map(function (mixed $eventKey): AuthoritativeCalibrationPair {
                if (! is_string($eventKey)
                    || preg_match(
                        '/\Av1\|instrument:([1-9][0-9]*)\|type:([1-9][0-9]*)\|cycle:([1-9][0-9]*)'
                        .'\|due:([0-9]{4}-[0-9]{2}-[0-9]{2})\|event:(due_soon|overdue)\z/',
                        $eventKey,
                        $matches,
                    ) !== 1) {
                    Log::warning('Calibration notification enumeration found a malformed active event key.', [
                        'category' => 'malformed_event_key',
                    ]);

                    throw new \UnexpectedValueException('Malformed active calibration notification event key.');
                }

                try {
                    $dueDate = CarbonImmutable::createFromFormat('!Y-m-d', $matches[4], 'Asia/Jakarta');
                } catch (Throwable) {
                    $dueDate = false;
                }

                if ($dueDate === false || $dueDate->format('Y-m-d') !== $matches[4]) {
                    Log::warning('Calibration notification enumeration found an invalid event-key date.', [
                        'category' => 'malformed_event_key',
                    ]);

                    throw new \UnexpectedValueException('Invalid calibration notification event-key date.');
                }

                return new AuthoritativeCalibrationPair((int) $matches[1], (int) $matches[2]);
            })
            ->values();
    }
}
