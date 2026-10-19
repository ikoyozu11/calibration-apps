<?php

use App\Models\InstrumentType;
use App\Models\TestProfileVersion;
use App\TestColumns\ActiveTestTableScopePlan;
use App\TestColumns\ActiveTestTableScopeResolver;
use App\TestColumns\TestProfileLifecycle;
use App\TestColumns\TestTableSnapshotException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function insertResolverInstrument(): void
{
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();
}

function insertResolverTable(
    int $profileId,
    int $versionId,
    int $pointId,
    int $columnId,
    int $valueId,
    string $profileName,
    string $status,
    int $versionNumber,
    string $columnKey,
    string $template,
    ?int $typeId = 31,
    ?string $displayTitle = null,
): void {
    if (! DB::table('test_profile')->where('test_profile_id', $profileId)->exists()) {
        DB::table('test_profile')->insert([
            'test_profile_id' => $profileId,
            'instrument_type_id' => $typeId,
            'profile_name' => $profileName,
            'display_title' => $displayTitle,
            'description' => null,
            'created_at' => '2026-10-14 09:00:00',
        ]);
    }

    insertTestProfileVersion([
        'test_profile_version_id' => $versionId,
        'test_profile_id' => $profileId,
        'version_number' => $versionNumber,
        'status' => $status,
        'profile_name' => $profileName,
        'display_title' => $displayTitle,
        'published_at' => $status === 'draft' ? null : '2026-10-14 09:00:00',
    ]);

    DB::table('test_column')->insert([
        'test_column_id' => $columnId,
        'test_profile_id' => $profileId,
        'test_profile_version_id' => $versionId,
        'column_key' => $columnKey,
        'column_label' => $columnKey,
        'column_order' => 1,
        'data_type' => 'text',
        'value_source' => 'template',
        'unit' => null,
        'display_scale' => null,
        'formula_code' => null,
        'formula_inputs' => '[]',
        'created_at' => '2026-10-14 09:00:00',
    ]);
    DB::table('test_point')->insert([
        'test_point_id' => $pointId,
        'test_profile_id' => $profileId,
        'test_profile_version_id' => $versionId,
        'point_order' => 1,
        'nominal_value' => null,
        'unit' => null,
        'tolerance_plus' => null,
        'tolerance_minus' => null,
        'description' => $profileName.' '.$status,
        'created_at' => '2026-10-14 09:00:00',
    ]);
    DB::table('test_point_value')->insert([
        'test_point_value_id' => $valueId,
        'test_profile_id' => $profileId,
        'test_profile_version_id' => $versionId,
        'test_point_id' => $pointId,
        'test_column_id' => $columnId,
        'numeric_value' => null,
        'text_value' => $template,
        'unit' => null,
        'created_at' => '2026-10-14 09:00:00',
    ]);
}

function resolverType(int $typeId = 31): InstrumentType
{
    return InstrumentType::query()->findOrFail($typeId);
}

function versionStatuses(): array
{
    return TestProfileVersion::query()
        ->orderBy('test_profile_version_id')
        ->get(['test_profile_version_id', 'status'])
        ->map(fn (TestProfileVersion $version): array => [
            'id' => (int) $version->test_profile_version_id,
            'status' => $version->status,
        ])
        ->all();
}

it('returns one candidate for a type with one active test table', function () {
    insertResolverInstrument();
    insertResolverTable(1, 11, 31, 21, 41, 'Main Test', 'active', 1, 'active_voltage', 'active-cell-100');

    $plan = app(ActiveTestTableScopeResolver::class)->resolve(resolverType());

    expect($plan)->toBeInstanceOf(ActiveTestTableScopePlan::class)
        ->and($plan->hasReadyTemplates())->toBeTrue()
        ->and($plan->isEmpty())->toBeFalse()
        ->and($plan->candidates)->toHaveCount(1)
        ->and($plan->candidates[0]->scopeOrder)->toBe(1)
        ->and($plan->candidates[0]->instrumentTypeId)->toBe(31)
        ->and($plan->candidates[0]->testProfileId)->toBe(1)
        ->and($plan->candidates[0]->testProfileVersionId)->toBe(11)
        ->and($plan->candidates[0]->versionNumber)->toBe(1)
        ->and($plan->candidates[0]->profileName)->toBe('Main Test')
        ->and($plan->candidates[0]->snapshot['test_profile_version_id'])->toBe(11);
});

it('returns one candidate per active test table on the same instrument type', function () {
    insertResolverInstrument();
    insertResolverTable(8, 21, 81, 81, 81, 'Sodiline', 'active', 1, 'sodiline_cell', 'sodiline-200', displayTitle: 'Sodiline title');
    insertResolverTable(1, 11, 31, 21, 41, 'Main Test', 'active', 2, 'main_cell', 'main-100', displayTitle: 'Main title');

    $plan = app(ActiveTestTableScopeResolver::class)->resolve(resolverType());

    expect($plan->candidates)->toHaveCount(2)
        ->and(array_column($plan->candidates, 'testProfileId'))->toBe([1, 8])
        ->and(array_column($plan->candidates, 'scopeOrder'))->toBe([1, 2])
        ->and(array_column($plan->candidates, 'testProfileVersionId'))->toBe([11, 21])
        ->and($plan->candidates[0]->profileName)->toBe('Main Test')
        ->and($plan->candidates[1]->profileName)->toBe('Sodiline')
        ->and($plan->candidates[0]->snapshot['test_profile_version_id'])->toBe(11)
        ->and($plan->candidates[1]->snapshot['test_profile_version_id'])->toBe(21)
        ->and($plan->candidates[0]->snapshot['profile_name'])->toBe('Main Test')
        ->and($plan->candidates[1]->snapshot['profile_name'])->toBe('Sodiline');
});

it('omits a draft-only test table when another table on the type is active', function () {
    insertResolverInstrument();
    insertResolverTable(8, 21, 81, 81, 81, 'Sodiline', 'draft', 1, 'draft_secret', 'draft-secret');
    insertResolverTable(1, 11, 31, 21, 41, 'Main Test', 'active', 2, 'main_cell', 'main-100');

    $plan = app(ActiveTestTableScopeResolver::class)->resolve(resolverType());

    expect($plan->candidates)->toHaveCount(1)
        ->and($plan->candidates[0]->testProfileId)->toBe(1)
        ->and($plan->candidates[0]->testProfileVersionId)->toBe(11)
        ->and(json_encode($plan->candidates[0]->snapshot))->not->toContain('draft-secret');
});

it('uses only the active version when the same table also has a retired version', function () {
    insertResolverInstrument();
    insertResolverTable(1, 13, 51, 41, 61, 'Main Test', 'retired', 1, 'retired_value', 'retired-50');
    insertResolverTable(1, 11, 31, 21, 41, 'Main Test', 'active', 2, 'active_value', 'active-200');

    $plan = app(ActiveTestTableScopeResolver::class)->resolve(resolverType());
    $snapshot = $plan->candidates[0]->snapshot;
    $encoded = json_encode($snapshot);

    expect($plan->candidates)->toHaveCount(1)
        ->and($plan->candidates[0]->testProfileVersionId)->toBe(11)
        ->and($plan->candidates[0]->versionNumber)->toBe(2)
        ->and($snapshot['test_profile_version_id'])->toBe(11)
        ->and(array_column($snapshot['columns'], 'column_key'))->toBe(['active_value'])
        ->and(array_column($snapshot['rows'], 'test_point_id'))->toBe([31])
        ->and($snapshot['rows'][0]['template_values']['active_value']['text_value'])->toBe('active-200')
        ->and($encoded)->not->toContain('retired_value')
        ->and($encoded)->not->toContain('retired-50')
        ->and($encoded)->not->toContain('"test_point_id":51');
});

it('returns an empty plan when the type has only a draft test table', function () {
    insertResolverInstrument();
    insertResolverTable(8, 21, 81, 81, 81, 'Sodiline', 'draft', 1, 'draft_secret', 'draft-secret');

    $plan = app(ActiveTestTableScopeResolver::class)->resolve(resolverType());

    expect($plan->isEmpty())->toBeTrue()
        ->and($plan->hasReadyTemplates())->toBeFalse()
        ->and($plan->candidates)->toBe([]);
});

it('includes a restored active test table in the plan', function () {
    insertResolverInstrument();
    insertResolverTable(1, 11, 31, 21, 41, 'Main Test', 'active', 1, 'main_cell', 'main-100');

    DB::table('test_profile')->where('test_profile_id', 1)->update([
        'archived_at' => '2026-10-16 09:00:00',
    ]);

    expect(app(ActiveTestTableScopeResolver::class)->resolve(resolverType())->candidates)->toBe([]);

    app(TestProfileLifecycle::class)->restore(1);

    $plan = app(ActiveTestTableScopeResolver::class)->resolve(resolverType());

    expect($plan->candidates)->toHaveCount(1)
        ->and($plan->candidates[0]->testProfileId)->toBe(1)
        ->and($plan->candidates[0]->testProfileVersionId)->toBe(11);
});

it('still omits a restored draft-only test table', function () {
    insertResolverInstrument();
    insertResolverTable(8, 21, 81, 81, 81, 'Sodiline', 'draft', 1, 'draft_secret', 'draft-secret');

    DB::table('test_profile')->where('test_profile_id', 8)->update([
        'archived_at' => '2026-10-16 09:00:00',
    ]);
    app(TestProfileLifecycle::class)->restore(8);

    $plan = app(ActiveTestTableScopeResolver::class)->resolve(resolverType());

    expect($plan->isEmpty())->toBeTrue()
        ->and($plan->candidates)->toBe([]);
});

it('omits an archived active test table from the plan', function () {
    insertResolverInstrument();
    insertResolverTable(8, 21, 81, 81, 81, 'Sodiline', 'active', 1, 'sodiline_cell', 'sodiline-200');
    insertResolverTable(1, 11, 31, 21, 41, 'Main Test', 'active', 2, 'main_cell', 'main-100');

    DB::table('test_profile')->where('test_profile_id', 1)->update([
        'archived_at' => '2026-10-16 09:00:00',
    ]);

    $plan = app(ActiveTestTableScopeResolver::class)->resolve(resolverType());

    expect($plan->candidates)->toHaveCount(1)
        ->and($plan->candidates[0]->testProfileId)->toBe(8)
        ->and($plan->candidates[0]->profileName)->toBe('Sodiline');
});

it('returns an empty plan when the type has no test profiles', function () {
    insertResolverInstrument();

    $plan = app(ActiveTestTableScopeResolver::class)->resolve(resolverType(32));

    expect($plan->instrumentTypeId)->toBe(32)
        ->and($plan->isEmpty())->toBeTrue()
        ->and($plan->hasReadyTemplates())->toBeFalse()
        ->and($plan->candidates)->toBe([]);
});

it('does not write calibrations, scopes, or version statuses', function () {
    insertResolverInstrument();
    insertResolverTable(1, 11, 31, 21, 41, 'Main Test', 'active', 1, 'main_cell', 'main-100');
    $before = versionStatuses();

    app(ActiveTestTableScopeResolver::class)->resolve(resolverType());

    expect(DB::table('calibration')->count())->toBe(0)
        ->and(Schema::hasTable('calibration_scope'))->toBeFalse()
        ->and(versionStatuses())->toBe($before);
});

it('does not return a partial plan when snapshot building fails', function () {
    insertResolverInstrument();
    insertResolverTable(1, 11, 31, 21, 41, 'Main Test', 'active', 1, 'main_cell', 'main-100');
    insertResolverTable(8, 21, 81, 81, 81, 'Sodiline', 'active', 1, 'soak_time', 'not-used');

    DB::table('test_column')->where('test_column_id', 81)->update([
        'data_type' => 'duration',
        'column_label' => 'Soak Time',
    ]);
    DB::table('test_point_value')->where('test_point_value_id', 81)->update([
        'text_value' => null,
        'numeric_value' => '90.5',
        'unit' => 's',
    ]);

    $before = versionStatuses();

    expect(fn () => app(ActiveTestTableScopeResolver::class)->resolve(resolverType()))
        ->toThrow(TestTableSnapshotException::class, 'Duration template values must be whole seconds.');

    expect(DB::table('calibration')->count())->toBe(0)
        ->and(Schema::hasTable('calibration_scope'))->toBeFalse()
        ->and(versionStatuses())->toBe($before);
});
