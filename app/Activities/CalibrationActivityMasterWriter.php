<?php

namespace App\Activities;

use App\CalibrationSessions\CalibrationHttpConnection;
use App\Models\CalibrationActivity;
use App\Models\CalibrationActivityMaster;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

class CalibrationActivityMasterWriter
{
    public const NAME_MAX = 255;

    public const CODE_MAX = 30;

    public function __construct(
        private ?CalibrationHttpConnection $httpConnection = null,
    ) {}

    public function create(string $activityName, ?string $activityCode = null): CalibrationActivityMaster
    {
        $connection = $this->connection();

        try {
            return $connection->transaction(function () use ($connection, $activityName, $activityCode): CalibrationActivityMaster {
                $activity = new CalibrationActivityMaster;
                $activity->setConnection($connection->getName());
                $activity->activity_code = $this->uniqueCode($connection, $activityCode);
                $activity->activity_name = $this->requiredName($activityName);
                $activity->is_active = true;
                $activity->save();

                return $activity;
            });
        } catch (CalibrationActivityMasterException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            throw new CalibrationActivityMasterException($this->constraintMessage($exception->getMessage()));
        }
    }

    public function update(int $activityId, string $activityName): CalibrationActivityMaster
    {
        $connection = $this->connection();

        try {
            return $connection->transaction(function () use ($connection, $activityId, $activityName): CalibrationActivityMaster {
                $activity = $this->locked($connection, $activityId);
                $activity->activity_name = $this->requiredName($activityName);
                $activity->save();

                return $activity;
            });
        } catch (CalibrationActivityMasterException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            throw new CalibrationActivityMasterException($this->constraintMessage($exception->getMessage()));
        }
    }

    public function activate(int $activityId): CalibrationActivityMaster
    {
        return $this->setActive($activityId, true);
    }

    public function deactivate(int $activityId): CalibrationActivityMaster
    {
        return $this->setActive($activityId, false);
    }

    public function delete(int $activityId): void
    {
        $connection = $this->connection();

        try {
            $connection->transaction(function () use ($connection, $activityId): void {
                $activity = $this->locked($connection, $activityId);

                if ($this->isUsedInCalibration($connection, $activity)) {
                    throw new CalibrationActivityMasterException('This activity is used in a calibration session and cannot be deleted.');
                }

                $activity->delete();
            });
        } catch (CalibrationActivityMasterException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            throw new CalibrationActivityMasterException($this->constraintMessage($exception->getMessage()));
        }
    }

    private function setActive(int $activityId, bool $isActive): CalibrationActivityMaster
    {
        $connection = $this->connection();

        try {
            return $connection->transaction(function () use ($connection, $activityId, $isActive): CalibrationActivityMaster {
                $activity = $this->locked($connection, $activityId);
                $activity->is_active = $isActive;
                $activity->save();

                return $activity;
            });
        } catch (CalibrationActivityMasterException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            throw new CalibrationActivityMasterException($this->constraintMessage($exception->getMessage()));
        }
    }

    private function connection(): Connection
    {
        return ($this->httpConnection ?? app(CalibrationHttpConnection::class))->resolve();
    }

    /**
     * @return Builder<CalibrationActivityMaster>
     */
    private function activities(Connection $connection): Builder
    {
        return CalibrationActivityMaster::on($connection->getName());
    }

    private function locked(Connection $connection, int $activityId): CalibrationActivityMaster
    {
        $activity = $this->activities($connection)
            ->where('activity_id', $activityId)
            ->lockForUpdate()
            ->first();

        if ($activity === null) {
            throw new CalibrationActivityMasterException('This activity could not be found.');
        }

        return $activity;
    }

    private function isUsedInCalibration(Connection $connection, CalibrationActivityMaster $activity): bool
    {
        $schema = Schema::connection($connection->getName());

        if (! $schema->hasTable((new CalibrationActivity)->getTable())) {
            return false;
        }

        $query = CalibrationActivity::on($connection->getName());

        if ($schema->hasColumn('calibration_activity', 'activity_id')) {
            return $query
                ->where('activity_id', $activity->activity_id)
                ->exists();
        }

        return $query
            ->where(function (Builder $inner) use ($activity): void {
                $inner
                    ->where('activity_description', $activity->activity_name)
                    ->orWhere('activity_type', $activity->activity_name);
            })
            ->exists();
    }

    private function uniqueCode(Connection $connection, ?string $activityCode): string
    {
        $code = trim((string) $activityCode);

        if ($code === '') {
            return $this->nextCode($connection);
        }

        if (mb_strlen($code) > self::CODE_MAX) {
            throw new CalibrationActivityMasterException('The activity code may not be greater than '.self::CODE_MAX.' characters.', 'activityCode');
        }

        if ($this->activities($connection)->where('activity_code', $code)->exists()) {
            throw new CalibrationActivityMasterException('This activity code is already in use.', 'activityCode');
        }

        return $code;
    }

    private function nextCode(Connection $connection): string
    {
        $codes = $this->activities($connection)->lockForUpdate()->pluck('activity_code');
        $max = 0;

        foreach ($codes as $code) {
            if (preg_match('/^ACT-(\d+)$/', (string) $code, $matches) !== 1) {
                continue;
            }

            $max = max($max, (int) $matches[1]);
        }

        return 'ACT-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    private function requiredName(string $value): string
    {
        $text = trim($value);

        if ($text === '') {
            throw new CalibrationActivityMasterException('Activity Name is required.', 'activityName');
        }

        if (mb_strlen($text) > self::NAME_MAX) {
            throw new CalibrationActivityMasterException(
                'The activity name may not be greater than '.self::NAME_MAX.' characters.',
                'activityName',
            );
        }

        return $text;
    }

    private function constraintMessage(string $message): string
    {
        $lower = mb_strtolower($message);

        if (str_contains($lower, 'uq_calibration_activity_master_code') || str_contains($lower, 'calibration_activity_master.activity_code')) {
            return 'This activity code is already in use.';
        }

        return 'The activity could not be saved. Refresh the page and try again.';
    }
}
