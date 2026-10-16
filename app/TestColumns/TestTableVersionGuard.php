<?php

namespace App\TestColumns;

use App\Models\TestProfile;
use App\Models\TestProfileVersion;
use Illuminate\Support\Facades\DB;

class TestTableVersionGuard
{
    public function __construct(private TestTableVersioning $versioning) {}

    public function lockForkSource(int $sourceVersionId): TestProfileVersion
    {
        $this->assertEnabled();

        $source = TestProfileVersion::query()
            ->where('test_profile_version_id', $sourceVersionId)
            ->lockForUpdate()
            ->first();

        if ($source === null) {
            throw new TestTableVersionWriteException('This test table version could not be found.');
        }

        $this->lockProfile((int) $source->test_profile_id);

        if ($source->status === TestProfileVersion::STATUS_DRAFT) {
            throw new TestTableVersionWriteException('Fork the active version, not a draft.');
        }

        if ($source->status !== TestProfileVersion::STATUS_ACTIVE) {
            throw new TestTableVersionWriteException('Fork the active version, not a retired version.');
        }

        $draftExists = TestProfileVersion::query()
            ->where('test_profile_id', $source->test_profile_id)
            ->where('status', TestProfileVersion::STATUS_DRAFT)
            ->lockForUpdate()
            ->exists();

        if ($draftExists) {
            throw new TestTableVersionWriteException('A draft version already exists for this test table.');
        }

        return $source;
    }

    /**
     * @return array{draft: TestProfileVersion, active: TestProfileVersion|null}
     */
    public function lockPublishableDraft(int $profileId): array
    {
        $this->assertEnabled();
        $this->lockProfile($profileId);

        $draft = TestProfileVersion::query()
            ->where('test_profile_id', $profileId)
            ->where('status', TestProfileVersion::STATUS_DRAFT)
            ->lockForUpdate()
            ->first();

        if ($draft === null) {
            throw new TestTableVersionWriteException('This test table has no draft version to publish.');
        }

        $columnCount = (int) DB::table('test_column')
            ->where('test_profile_version_id', $draft->test_profile_version_id)
            ->count();
        $pointCount = (int) DB::table('test_point')
            ->where('test_profile_version_id', $draft->test_profile_version_id)
            ->count();

        if ($columnCount < 1 || $pointCount < 1) {
            throw new TestTableVersionWriteException('Publish a draft that has at least one column and one row.');
        }

        $active = TestProfileVersion::query()
            ->where('test_profile_id', $profileId)
            ->where('status', TestProfileVersion::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();

        return [
            'draft' => $draft,
            'active' => $active instanceof TestProfileVersion ? $active : null,
        ];
    }

    private function assertEnabled(): void
    {
        if (! $this->versioning->enabled()) {
            throw new TestTableVersionWriteException('Test table versioning is not available.');
        }
    }

    private function lockProfile(int $profileId): TestProfile
    {
        $profile = TestProfile::query()
            ->where('test_profile_id', $profileId)
            ->lockForUpdate()
            ->first();

        if ($profile === null) {
            throw new TestTableVersionWriteException('This test table could not be found.');
        }

        return $profile;
    }
}
