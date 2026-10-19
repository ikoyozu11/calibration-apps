<?php

namespace App\TestColumns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PostgresTestProfileLifecycle implements TestProfileLifecycle
{
    public function isUsedInCalibration(int $profileId): bool
    {
        try {
            $row = DB::selectOne(
                'select (
                    public.test_profile_is_used_in_calibration(?::bigint)
                    or exists (
                        select 1
                        from public.calibration_scope
                        where test_profile_version_id in (
                            select test_profile_version_id
                            from public.test_profile_version
                            where test_profile_id = ?::bigint
                        )
                    )
                ) as used',
                [$profileId, $profileId],
            );
        } catch (QueryException $exception) {
            throw new TestProfileLifecycleException(
                TestProfileLifecycleConstraintMessage::fromDatabaseMessage($exception->getMessage()),
            );
        }

        return (bool) ($row->used ?? false);
    }

    public function deleteUnused(int $profileId): void
    {
        $this->call('select public.test_profile_delete_unused(?::bigint)', [$profileId]);
    }

    public function archive(int $profileId): void
    {
        $this->call('select public.test_profile_archive(?::bigint)', [$profileId]);
    }

    public function restore(int $profileId): void
    {
        try {
            $updated = DB::update(
                'update public.test_profile set archived_at = null where test_profile_id = ? and archived_at is not null',
                [$profileId],
            );
        } catch (QueryException $exception) {
            throw new TestProfileLifecycleException(
                TestProfileLifecycleConstraintMessage::fromDatabaseMessage($exception->getMessage()),
            );
        }

        if ($updated > 0) {
            return;
        }

        $exists = DB::table('test_profile')->where('test_profile_id', $profileId)->exists();

        if (! $exists) {
            throw new TestProfileLifecycleException('This test table could not be found.');
        }

        throw new TestProfileLifecycleException('This test table is not archived.');
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function call(string $sql, array $bindings): void
    {
        try {
            DB::selectOne($sql, $bindings);
        } catch (QueryException $exception) {
            throw new TestProfileLifecycleException(
                TestProfileLifecycleConstraintMessage::fromDatabaseMessage($exception->getMessage()),
            );
        }
    }
}
