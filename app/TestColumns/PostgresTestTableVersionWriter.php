<?php

namespace App\TestColumns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PostgresTestTableVersionWriter implements TestTableVersionWriter
{
    public function __construct(private TestTableVersionGuard $guard) {}

    public function fork(int $sourceVersionId): int
    {
        return DB::transaction(function () use ($sourceVersionId): int {
            $this->guard->lockForkSource($sourceVersionId);

            return $this->call(
                'select public.test_profile_version_fork(?::bigint) as test_profile_version_id',
                [$sourceVersionId],
            );
        });
    }

    public function publish(int $profileId): int
    {
        return DB::transaction(function () use ($profileId): int {
            $this->guard->lockPublishableDraft($profileId);

            return $this->call(
                'select public.test_profile_version_publish(?::bigint) as test_profile_version_id',
                [$profileId],
            );
        });
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function call(string $sql, array $bindings): int
    {
        try {
            $row = DB::selectOne($sql, $bindings);
        } catch (QueryException $exception) {
            throw new TestTableVersionWriteException(
                TestTableVersionConstraintMessage::fromDatabaseMessage($exception->getMessage()),
            );
        }

        return (int) ($row->test_profile_version_id ?? 0);
    }
}
