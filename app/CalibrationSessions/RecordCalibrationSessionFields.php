<?php

namespace App\CalibrationSessions;

use App\Models\Calibration;
use DateTimeImmutable;
use Illuminate\Database\Connection;

final class RecordCalibrationSessionFields
{
    public function __construct(private CalibrationSessionConnection $sessionConnection) {}

    public function dates(int $calibrationId, string $date, string $due, Connection $connection): void
    {
        $date = $this->canonicalDate($date);
        $due = $this->canonicalDate($due);

        if ($due < $date) {
            throw RecordCalibrationSessionFieldsException::dueBeforeDate();
        }

        $this->update($calibrationId, ['calibration_date' => $date, 'calibration_due' => $due], $connection);
    }

    public function resume(int $calibrationId, ?string $resume, Connection $connection): void
    {
        $resume = $resume === null || trim($resume) === '' ? null : trim($resume);

        if ($resume !== null && mb_strlen($resume) > 5000) {
            throw new RecordCalibrationSessionFieldsException('Resume must be 5000 characters or fewer.');
        }

        $this->update($calibrationId, ['resume' => $resume], $connection);
    }

    private function update(int $calibrationId, array $payload, Connection $connection): void
    {
        $connection = $this->sessionConnection->resolve($connection);

        $connection->transaction(function () use ($connection, $calibrationId, $payload): void {
            $session = $connection->table('calibration')->where('calibration_id', $calibrationId)->lockForUpdate()->first();

            if ($session === null || (string) $session->session_status !== Calibration::STATUS_IN_PROGRESS) {
                throw RecordCalibrationSessionFieldsException::invalidSession();
            }

            $updated = $connection->table('calibration')
                ->where('calibration_id', $calibrationId)
                ->where('session_status', Calibration::STATUS_IN_PROGRESS)
                ->update($payload);

            if ($updated !== 1) {
                throw RecordCalibrationSessionFieldsException::invalidSession();
            }
        });
    }

    private function canonicalDate(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            || ! $date instanceof DateTimeImmutable
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $date->format('Y-m-d') !== $value) {
            throw RecordCalibrationSessionFieldsException::invalidDates();
        }

        return $value;
    }
}
