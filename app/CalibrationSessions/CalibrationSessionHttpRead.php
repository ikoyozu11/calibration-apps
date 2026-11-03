<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use App\Models\Certificate;
use App\Models\Instrument;
use App\Models\InstrumentType;
use Illuminate\Database\Connection;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Loads core HTTP page models through the fail-closed read resolver.
 *
 * Uses the shared HTTP connection selector. Layer 2 staging remains disabled.
 */
final class CalibrationSessionHttpRead
{
    public function __construct(
        private ReadCalibrationSessionHttpConnection $reads,
    ) {}

    public function connection(): Connection
    {
        try {
            return $this->reads->resolve();
        } catch (ReadCalibrationSessionHttpException $exception) {
            throw new HttpException(503, $exception->getMessage(), $exception);
        } catch (CalibrationSessionConnectionException $exception) {
            throw new HttpException(503, ReadCalibrationSessionHttpException::sessionReadsUnavailable()->getMessage(), $exception);
        } catch (Throwable $exception) {
            throw new HttpException(503, ReadCalibrationSessionHttpException::sessionReadsUnavailable()->getMessage(), $exception);
        }
    }

    public function connectionName(): string
    {
        $name = $this->connection()->getName();

        if (! is_string($name) || $name === '') {
            throw new HttpException(503, ReadCalibrationSessionHttpException::sessionReadsUnavailable()->getMessage());
        }

        return $name;
    }

    public function calibration(int $calibrationId): Calibration
    {
        if ($calibrationId < 1) {
            abort(404);
        }

        $calibration = Calibration::on($this->connectionName())
            ->whereKey($calibrationId)
            ->first();

        if (! $calibration instanceof Calibration) {
            abort(404);
        }

        return $calibration;
    }

    public function instrument(int $instrumentId): Instrument
    {
        if ($instrumentId < 1) {
            abort(404);
        }

        $instrument = Instrument::on($this->connectionName())
            ->whereKey($instrumentId)
            ->first();

        if (! $instrument instanceof Instrument) {
            abort(404);
        }

        return $instrument;
    }

    public function instrumentType(int $instrumentTypeId, int $instrumentId): InstrumentType
    {
        if ($instrumentTypeId < 1 || $instrumentId < 1) {
            abort(404);
        }

        $instrumentType = InstrumentType::on($this->connectionName())
            ->whereKey($instrumentTypeId)
            ->first();

        if (! $instrumentType instanceof InstrumentType
            || (int) $instrumentType->instrument_id !== $instrumentId) {
            abort(404);
        }

        return $instrumentType;
    }

    public function firstInstrumentType(int $instrumentId): ?InstrumentType
    {
        if ($instrumentId < 1) {
            abort(404);
        }

        $instrumentType = InstrumentType::on($this->connectionName())
            ->where('instrument_id', $instrumentId)
            ->orderBy('instrument_type_id')
            ->first();

        return $instrumentType instanceof InstrumentType ? $instrumentType : null;
    }

    public function certificate(int $certificateId): Certificate
    {
        if ($certificateId < 1) {
            abort(404);
        }

        $certificate = Certificate::on($this->connectionName())
            ->whereKey($certificateId)
            ->first();

        if (! $certificate instanceof Certificate) {
            abort(404);
        }

        return $certificate;
    }
}
