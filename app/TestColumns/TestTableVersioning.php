<?php

namespace App\TestColumns;

use App\Models\TestProfile;
use App\Models\TestProfileVersion;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class TestTableVersioning
{
    public const PURPOSE_MANAGE = 'manage';

    public const PURPOSE_DETAIL = 'detail';

    public function enabled(): bool
    {
        return Schema::hasTable((new TestProfileVersion)->getTable());
    }

    public function draft(TestProfile $profile): ?TestProfileVersion
    {
        return $this->versionWithStatus($profile, TestProfileVersion::STATUS_DRAFT);
    }

    public function active(TestProfile $profile): ?TestProfileVersion
    {
        return $this->versionWithStatus($profile, TestProfileVersion::STATUS_ACTIVE);
    }

    public function manageViewVersion(TestProfile $profile): ?TestProfileVersion
    {
        return $this->draft($profile)
            ?? $this->active($profile)
            ?? $this->latestVersion($profile);
    }

    public function detailViewVersion(TestProfile $profile): ?TestProfileVersion
    {
        return $this->active($profile)
            ?? $this->draft($profile)
            ?? $this->latestVersion($profile);
    }

    public function viewVersion(TestProfile $profile, string $purpose): ?TestProfileVersion
    {
        return $purpose === self::PURPOSE_MANAGE
            ? $this->manageViewVersion($profile)
            : $this->detailViewVersion($profile);
    }

    public function canMutate(TestProfile $profile): bool
    {
        if ($profile->isArchived()) {
            return false;
        }

        if (! $this->enabled()) {
            return true;
        }

        if ($this->draft($profile) !== null) {
            return true;
        }

        return ! $this->hasVersions($profile);
    }

    public function hasRetired(TestProfile $profile): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return $this->versions($profile)->contains(
            fn (TestProfileVersion $version): bool => $version->status === TestProfileVersion::STATUS_RETIRED,
        );
    }

    public function showsUnpublishedNotice(TestProfile $profile): bool
    {
        return $this->enabled()
            && $this->active($profile) === null
            && $this->draft($profile) !== null;
    }

    public function caption(TestProfile $profile, string $purpose): ?string
    {
        $version = $this->viewVersion($profile, $purpose);

        if ($version === null) {
            return null;
        }

        $label = ucfirst($version->status).' · v'.$version->version_number;

        if ($profile->isArchived()) {
            return $label.' · archived';
        }

        if ($purpose === self::PURPOSE_MANAGE && ! $this->canMutate($profile)) {
            return $label.' · read-only';
        }

        return $label;
    }

    public function draftId(int $profileId): ?int
    {
        if (! $this->enabled()) {
            return null;
        }

        $id = TestProfileVersion::query()
            ->where('test_profile_id', $profileId)
            ->where('status', TestProfileVersion::STATUS_DRAFT)
            ->value('test_profile_version_id');

        return $id === null ? null : (int) $id;
    }

    public function ensureInitialDraft(TestProfile $profile): ?TestProfileVersion
    {
        if (! $this->enabled()) {
            return null;
        }

        $existing = $this->draft($profile) ?? $this->latestVersion($profile);

        if ($existing !== null) {
            return $existing;
        }

        return TestProfileVersion::query()->create([
            'test_profile_id' => $profile->test_profile_id,
            'version_number' => 1,
            'status' => TestProfileVersion::STATUS_DRAFT,
            'profile_name' => $profile->profile_name,
            'display_title' => $profile->display_title,
            'description' => $profile->description,
            'published_at' => null,
        ]);
    }

    /**
     * @param  Collection<int, TestProfile>  $profiles
     */
    public function constrainChildren(HasMany $query, Collection $profiles, string $purpose): void
    {
        if (! $this->enabled()) {
            return;
        }

        $versionIds = [];
        $legacyProfileIds = [];

        foreach ($profiles as $profile) {
            $version = $this->viewVersion($profile, $purpose);

            if ($version !== null) {
                $versionIds[] = (int) $version->test_profile_version_id;

                continue;
            }

            $legacyProfileIds[] = (int) $profile->test_profile_id;
        }

        $query->where(function ($group) use ($versionIds, $legacyProfileIds): void {
            $hasCondition = false;

            if ($versionIds !== []) {
                $group->whereIn('test_profile_version_id', $versionIds);
                $hasCondition = true;
            }

            if ($legacyProfileIds !== []) {
                $method = $hasCondition ? 'orWhere' : 'where';
                $group->{$method}(function ($legacy) use ($legacyProfileIds): void {
                    $legacy
                        ->whereIn('test_profile_id', $legacyProfileIds)
                        ->whereNull('test_profile_version_id');
                });
                $hasCondition = true;
            }

            if (! $hasCondition) {
                $group->whereRaw('0 = 1');
            }
        });
    }

    private function versionWithStatus(TestProfile $profile, string $status): ?TestProfileVersion
    {
        if (! $this->enabled()) {
            return null;
        }

        $match = $this->versions($profile)->first(
            fn (TestProfileVersion $version): bool => $version->status === $status,
        );

        return $match instanceof TestProfileVersion ? $match : null;
    }

    private function latestVersion(TestProfile $profile): ?TestProfileVersion
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->versions($profile)
            ->sortByDesc(fn (TestProfileVersion $version): int => (int) $version->version_number)
            ->first();
    }

    private function hasVersions(TestProfile $profile): bool
    {
        return $this->versions($profile)->isNotEmpty();
    }

    /**
     * @return Collection<int, TestProfileVersion>
     */
    private function versions(TestProfile $profile): Collection
    {
        if ($profile->relationLoaded('versions')) {
            return $profile->versions;
        }

        return $profile->versions()->get();
    }
}
