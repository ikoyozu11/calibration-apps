<?php

namespace App\Activities;

use App\Models\CalibrationActivity;
use App\Models\CalibrationActivityMaster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CalibrationActivityMasterWriter
{
    public const NAME_MAX = 255;

    public const CODE_MAX = 30;

    public function create(string $activityName, ?string $activityCode = null): CalibrationActivityMaster
    {
        try {
            return DB::transaction(function () use ($activityName, $activityCode): CalibrationActivityMaster {
                $activity = new CalibrationActivityMaster;
                $activity->activity_code = $this->uniqueCode($activityCode);
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
        try {
            return DB::transaction(function () use ($activityId, $activityName): CalibrationActivityMaster {
                $activity = $this->locked($activityId);
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
        try {
            DB::transaction(function () use ($activityId): void {
                $activity = $this->locked($activityId);

                if ($this->isUsedInCalibration($activity)) {
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
        try {
            return DB::transaction(function () use ($activityId, $isActive): CalibrationActivityMaster {
                $activity = $this->locked($activityId);
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

    private function locked(int $activityId): CalibrationActivityMaster
    {
        $activity = CalibrationActivityMaster::query()
            ->where('activity_id', $activityId)
            ->lockForUpdate()
            ->first();

        if ($activity === null) {
            throw new CalibrationActivityMasterException('This activity could not be found.');
        }

        return $activity;
    }

    private function isUsedInCalibration(CalibrationActivityMaster $activity): bool
    {
        if (! Schema::hasTable((new CalibrationActivity)->getTable())) {
            return false;
        }

        if (Schema::hasColumn('calibration_activity', 'activity_id')) {
            return CalibrationActivity::query()
                ->where('activity_id', $activity->activity_id)
                ->exists();
        }

        return CalibrationActivity::query()
            ->where(function (Builder $query) use ($activity): void {
                $query
                    ->where('activity_description', $activity->activity_name)
                    ->orWhere('activity_type', $activity->activity_name);
            })
            ->exists();
    }

    private function uniqueCode(?string $activityCode): string
    {
        $code = trim((string) $activityCode);

        if ($code === '') {
            return $this->nextCode();
        }

        if (mb_strlen($code) > self::CODE_MAX) {
            throw new CalibrationActivityMasterException('The activity code may not be greater than '.self::CODE_MAX.' characters.', 'activityCode');
        }

        if (CalibrationActivityMaster::query()->where('activity_code', $code)->exists()) {
            throw new CalibrationActivityMasterException('This activity code is already in use.', 'activityCode');
        }

        return $code;
    }

    private function nextCode(): string
    {
        $codes = CalibrationActivityMaster::query()->lockForUpdate()->pluck('activity_code');
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
