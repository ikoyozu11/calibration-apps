<?php

namespace App\TestColumns;

use Illuminate\Support\Facades\Schema;

class SqliteTestProfileLifecycle implements TestProfileLifecycle
{
    use WritesThroughHttpConnection;

    public function isUsedInCalibration(int $profileId): bool
    {
        $schema = Schema::connection($this->writeConnectionName());
        $connection = $this->writeConnection();

        if ($schema->hasTable('calibration_scope')) {
            if ($connection->table('calibration_scope')->where('test_profile_id', $profileId)->exists()) {
                return true;
            }

            if ($schema->hasTable('test_profile_version')) {
                $versionIds = $connection->table('test_profile_version')
                    ->where('test_profile_id', $profileId)
                    ->pluck('test_profile_version_id');

                if ($versionIds->isNotEmpty()
                    && $connection->table('calibration_scope')->whereIn('test_profile_version_id', $versionIds)->exists()) {
                    return true;
                }
            }
        }

        if (! $schema->hasTable('calibration_test_result') || ! $schema->hasTable('test_point')) {
            return false;
        }

        $pointIds = $connection->table('test_point')
            ->where('test_profile_id', $profileId)
            ->pluck('test_point_id');

        return $pointIds->isNotEmpty()
            && $connection->table('calibration_test_result')->whereIn('test_point_id', $pointIds)->exists();
    }

    public function deleteUnused(int $profileId): void
    {
        $connection = $this->writeConnection();
        $schema = Schema::connection($this->writeConnectionName());

        if ($this->isUsedInCalibration($profileId)) {
            throw new TestProfileLifecycleException('This test table is used by a calibration and cannot be deleted.');
        }

        if (! $connection->table('test_profile')->where('test_profile_id', $profileId)->exists()) {
            throw new TestProfileLifecycleException('This test table could not be found.');
        }

        $connection->table('test_point_value')->where('test_profile_id', $profileId)->delete();
        $connection->table('test_point')->where('test_profile_id', $profileId)->delete();
        $connection->table('test_column')->where('test_profile_id', $profileId)->delete();

        if ($schema->hasTable('test_profile_version')) {
            $connection->table('test_profile_version')->where('test_profile_id', $profileId)->delete();
        }

        $connection->table('test_profile')->where('test_profile_id', $profileId)->delete();
    }

    public function archive(int $profileId): void
    {
        $connection = $this->writeConnection();
        $schema = Schema::connection($this->writeConnectionName());

        if (! $schema->hasColumn('test_profile', 'archived_at')) {
            throw new TestProfileLifecycleException('This test table could not be archived.');
        }

        if (! $connection->table('test_profile')->where('test_profile_id', $profileId)->exists()) {
            throw new TestProfileLifecycleException('This test table could not be found.');
        }

        $connection->table('test_profile')
            ->where('test_profile_id', $profileId)
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);
    }

    public function restore(int $profileId): void
    {
        $connection = $this->writeConnection();
        $schema = Schema::connection($this->writeConnectionName());

        if (! $schema->hasColumn('test_profile', 'archived_at')) {
            throw new TestProfileLifecycleException('This test table could not be restored.');
        }

        if (! $connection->table('test_profile')->where('test_profile_id', $profileId)->exists()) {
            throw new TestProfileLifecycleException('This test table could not be found.');
        }

        $updated = $connection->table('test_profile')
            ->where('test_profile_id', $profileId)
            ->whereNotNull('archived_at')
            ->update(['archived_at' => null]);

        if ($updated === 0) {
            throw new TestProfileLifecycleException('This test table is not archived.');
        }
    }
}
