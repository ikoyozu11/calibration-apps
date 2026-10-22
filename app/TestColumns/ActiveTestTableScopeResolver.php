<?php

namespace App\TestColumns;

use App\Models\InstrumentType;
use App\Models\TestProfile;
use App\Models\TestProfileVersion;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Read-only Active Test Table resolution for one instrument type.
 *
 * Returns planned calibration scopes only. It does not insert Calibration or
 * CalibrationScope rows, and it does not pin a version. A Publish that lands
 * after this plan is built can change which version is Active. Stage 30I-D.3B
 * must resolve again and persist snapshots in the same transaction.
 */
class ActiveTestTableScopeResolver
{
    public function __construct(
        private TestTableSnapshotBuilder $snapshots,
    ) {}

    public function resolve(InstrumentType $instrumentType, ?Connection $connection = null): ActiveTestTableScopePlan
    {
        $connectionName = $this->connectionName($connection, $instrumentType);

        $instrumentType = InstrumentType::on($connectionName)->whereKey($instrumentType->getKey())->first();

        if ($instrumentType === null) {
            throw new TestTableSnapshotException('The instrument type does not exist.');
        }

        $typeId = (int) $instrumentType->instrument_type_id;

        $profiles = TestProfile::on($connectionName)
            ->where('instrument_type_id', $typeId)
            ->notArchived()
            ->orderBy('test_profile_id')
            ->with([
                'versions' => function (HasMany $query): void {
                    $query
                        ->where('status', TestProfileVersion::STATUS_ACTIVE)
                        ->orderBy('version_number')
                        ->orderBy('test_profile_version_id');
                },
            ])
            ->get();

        $candidates = [];
        $scopeOrder = 1;

        foreach ($profiles as $profile) {
            $active = $profile->versions->first();

            if (! $active instanceof TestProfileVersion || ! $active->isActive()) {
                continue;
            }

            $snapshot = $this->snapshots->build($active, $connection);

            $candidates[] = new ActiveTestTableScopeCandidate(
                scopeOrder: $scopeOrder,
                instrumentTypeId: $typeId,
                testProfileId: (int) $profile->test_profile_id,
                testProfileVersionId: (int) $active->test_profile_version_id,
                versionNumber: (int) $active->version_number,
                profileName: $active->profile_name,
                displayTitle: $active->display_title,
                description: $active->description,
                snapshot: $snapshot,
            );

            $scopeOrder++;
        }

        return new ActiveTestTableScopePlan(
            instrumentTypeId: $typeId,
            candidates: $candidates,
        );
    }

    private function connectionName(?Connection $connection, Model $model): string
    {
        $name = $connection?->getName();

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $name = $model->getConnectionName();

        return is_string($name) && $name !== '' ? $name : (string) config('database.default');
    }
}
