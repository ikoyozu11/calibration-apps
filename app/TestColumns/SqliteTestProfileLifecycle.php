<?php

namespace App\TestColumns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SqliteTestProfileLifecycle implements TestProfileLifecycle
{
    public function isUsedInCalibration(int $profileId): bool
    {
        if (Schema::hasTable('calibration_scope')) {
            if (DB::table('calibration_scope')->where('test_profile_id', $profileId)->exists()) {
                return true;
            }

            if (Schema::hasTable('test_profile_version')) {
                $versionIds = DB::table('test_profile_version')
                    ->where('test_profile_id', $profileId)
                    ->pluck('test_profile_version_id');

                if ($versionIds->isNotEmpty()
                    && DB::table('calibration_scope')->whereIn('test_profile_version_id', $versionIds)->exists()) {
                    return true;
                }
            }
        }

        if (! Schema::hasTable('calibration_test_result') || ! Schema::hasTable('test_point')) {
            return false;
        }

        $pointIds = DB::table('test_point')
            ->where('test_profile_id', $profileId)
            ->pluck('test_point_id');

        return $pointIds->isNotEmpty()
            && DB::table('calibration_test_result')->whereIn('test_point_id', $pointIds)->exists();
    }

    public function deleteUnused(int $profileId): void
    {
        if ($this->isUsedInCalibration($profileId)) {
            throw new TestProfileLifecycleException('This test table is used by a calibration and cannot be deleted.');
        }

        if (! DB::table('test_profile')->where('test_profile_id', $profileId)->exists()) {
            throw new TestProfileLifecycleException('This test table could not be found.');
        }

        DB::table('test_point_value')->where('test_profile_id', $profileId)->delete();
        DB::table('test_point')->where('test_profile_id', $profileId)->delete();
        DB::table('test_column')->where('test_profile_id', $profileId)->delete();

        if (Schema::hasTable('test_profile_version')) {
            DB::table('test_profile_version')->where('test_profile_id', $profileId)->delete();
        }

        DB::table('test_profile')->where('test_profile_id', $profileId)->delete();
    }

    public function archive(int $profileId): void
    {
        if (! Schema::hasColumn('test_profile', 'archived_at')) {
            throw new TestProfileLifecycleException('This test table could not be archived.');
        }

        if (! DB::table('test_profile')->where('test_profile_id', $profileId)->exists()) {
            throw new TestProfileLifecycleException('This test table could not be found.');
        }

        DB::table('test_profile')
            ->where('test_profile_id', $profileId)
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);
    }

    public function restore(int $profileId): void
    {
        if (! Schema::hasColumn('test_profile', 'archived_at')) {
            throw new TestProfileLifecycleException('This test table could not be restored.');
        }

        if (! DB::table('test_profile')->where('test_profile_id', $profileId)->exists()) {
            throw new TestProfileLifecycleException('This test table could not be found.');
        }

        $updated = DB::table('test_profile')
            ->where('test_profile_id', $profileId)
            ->whereNotNull('archived_at')
            ->update(['archived_at' => null]);

        if ($updated === 0) {
            throw new TestProfileLifecycleException('This test table is not archived.');
        }
    }
}
