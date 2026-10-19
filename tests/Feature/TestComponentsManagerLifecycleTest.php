<?php

use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\TestProfileVersion;
use App\TestColumns\TestTableSnapshotBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

function createManagerUsageTables(): void
{
    Schema::create('calibration_scope', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_scope_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('instrument_type_id');
        $table->unsignedBigInteger('test_profile_id')->nullable();
        $table->unsignedBigInteger('test_profile_version_id')->nullable();
        $table->json('test_table_snapshot')->nullable();
        $table->integer('scope_order');
        $table->text('remarks')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('calibration_test_result', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_test_result_id')->primary();
        $table->unsignedBigInteger('calibration_scope_id');
        $table->unsignedBigInteger('test_point_id');
        $table->string('result_status', 20)->nullable();
        $table->text('remarks')->nullable();
        $table->timestamp('created_at');
    });
}

function insertUsedCalibrationScope(int $profileId = 1, int $versionId = 11): void
{
    DB::table('calibration')->insert([
        'calibration_id' => 8,
        'instrument_id' => 3,
        'calibration_provider_id' => 1,
        'calibration_number' => 'CAL-USED-0001',
        'calibration_date' => '2026-06-12',
        'calibration_due' => '2027-06-12',
        'calibration_method' => 'INTERNAL',
        'calibration_result' => null,
        'certificate_number' => null,
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
        'updated_at' => '2026-06-12 09:00:00',
        'action_date' => null,
        'resume' => null,
        'customer_id' => null,
    ]);
    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => 1,
        'calibration_id' => 8,
        'instrument_type_id' => 31,
        'test_profile_id' => $profileId,
        'test_profile_version_id' => $versionId,
        'test_table_snapshot' => json_encode([
            'schema_version' => 1,
            'profile_name' => 'Main Test',
            'display_title' => 'Voltage table',
        ]),
        'scope_order' => 1,
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);
}

function managerComponent()
{
    return Livewire::test('pages::instruments.test-components', [
        'instrument' => Instrument::query()->findOrFail(3),
        'instrumentType' => InstrumentType::query()->findOrFail(31),
    ]);
}

it('updates a draft-only test table profile and draft version', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    managerComponent()
        ->call('startAddingTestTable')
        ->set('profileName', 'Weight Calibration')
        ->call('saveTestTable')
        ->call('startEditingTestTable', 1)
        ->set('profileName', ' Renamed Draft ')
        ->set('displayTitle', ' Draft title ')
        ->call('saveEditedTestTable', 1)
        ->assertHasNoErrors()
        ->assertSee('Test table updated.')
        ->assertSee('Renamed Draft')
        ->assertSee('Draft title')
        ->assertDontSee('Weight Calibration');

    $this->assertDatabaseHas('test_profile', [
        'test_profile_id' => 1,
        'profile_name' => 'Renamed Draft',
        'display_title' => 'Draft title',
    ]);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_id' => 1,
        'status' => 'draft',
        'profile_name' => 'Renamed Draft',
        'display_title' => 'Draft title',
    ]);
});

it('rejects editing an active test table that has no draft', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();

    managerComponent()
        ->assertDontSee('Edit Table')
        ->assertSee('Fork Draft')
        ->call('startEditingTestTable', 1)
        ->assertHasErrors(['testTable'])
        ->assertSee('Fork a draft before renaming this published test table.');

    $this->assertDatabaseHas('test_profile', [
        'test_profile_id' => 1,
        'profile_name' => 'Main Test',
        'display_title' => 'Voltage table',
    ]);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 11,
        'profile_name' => 'Main Test',
        'display_title' => 'Voltage table',
    ]);
});

it('edits only the draft when an active version already exists', function () {
    createInstrumentDetailTestTables();
    insertTwoVersionTestTable();

    $before = app(TestTableSnapshotBuilder::class)->build(
        TestProfileVersion::query()->findOrFail(11),
    );

    managerComponent()
        ->call('startEditingTestTable', 1)
        ->set('profileName', 'Draft Rename')
        ->set('displayTitle', 'Draft title')
        ->call('saveEditedTestTable', 1)
        ->assertHasNoErrors()
        ->assertSee('Draft Rename')
        ->assertSee('Draft title');

    $this->assertDatabaseHas('test_profile', [
        'test_profile_id' => 1,
        'profile_name' => 'Main Test',
        'display_title' => 'Voltage table',
    ]);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 11,
        'status' => 'active',
        'profile_name' => 'Main Test',
        'display_title' => 'Voltage table',
    ]);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 12,
        'status' => 'draft',
        'profile_name' => 'Draft Rename',
        'display_title' => 'Draft title',
    ]);

    $after = app(TestTableSnapshotBuilder::class)->build(
        TestProfileVersion::query()->findOrFail(11),
    );

    expect($after['profile_name'])->toBe('Main Test')
        ->and($after['display_title'])->toBe('Voltage table')
        ->and($after['test_profile_version_id'])->toBe($before['test_profile_version_id']);
});

it('rejects a duplicate profile name including archived catalog names', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    DB::table('test_profile')->insert([
        [
            'test_profile_id' => 1,
            'instrument_type_id' => 31,
            'profile_name' => 'Main Test',
            'display_title' => 'Live title',
            'description' => null,
            'created_at' => '2026-10-14 09:00:00',
            'archived_at' => null,
        ],
        [
            'test_profile_id' => 2,
            'instrument_type_id' => 31,
            'profile_name' => 'Archived Name',
            'display_title' => 'Archived title',
            'description' => null,
            'created_at' => '2026-10-14 09:00:00',
            'archived_at' => '2026-10-16 09:00:00',
        ],
    ]);
    insertTestProfileVersion([
        'test_profile_version_id' => 1,
        'test_profile_id' => 1,
        'profile_name' => 'Main Test',
        'display_title' => 'Live title',
    ]);

    managerComponent()
        ->call('startEditingTestTable', 1)
        ->set('profileName', 'Archived Name')
        ->set('displayTitle', 'Live title')
        ->call('saveEditedTestTable', 1)
        ->assertHasErrors(['profileName'])
        ->assertSee('A test table with this profile name already exists for this type.');
});

it('rejects a duplicate table title without regard to case', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    DB::table('test_profile')->insert([
        [
            'test_profile_id' => 1,
            'instrument_type_id' => 31,
            'profile_name' => 'Main Test',
            'display_title' => 'Weight table',
            'description' => null,
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_profile_id' => 2,
            'instrument_type_id' => 31,
            'profile_name' => 'Second',
            'display_title' => 'Diameter table',
            'description' => null,
            'created_at' => '2026-10-14 09:00:00',
        ],
    ]);
    insertTestProfileVersion([
        'test_profile_version_id' => 1,
        'test_profile_id' => 1,
        'profile_name' => 'Main Test',
        'display_title' => 'Weight table',
    ]);
    insertTestProfileVersion([
        'test_profile_version_id' => 2,
        'test_profile_id' => 2,
        'profile_name' => 'Second',
        'display_title' => 'Diameter table',
    ]);

    managerComponent()
        ->call('selectProfile', 2)
        ->call('startEditingTestTable', 2)
        ->set('profileName', 'Second')
        ->set('displayTitle', ' WEIGHT TABLE ')
        ->call('saveEditedTestTable', 2)
        ->assertHasErrors(['displayTitle'])
        ->assertSee('Table titles must be different.');
});

it('allows a blank title when only one active test table exists', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    managerComponent()
        ->call('startAddingTestTable')
        ->set('profileName', 'Weight Calibration')
        ->call('saveTestTable')
        ->call('startEditingTestTable', 1)
        ->set('profileName', 'Weight Calibration')
        ->set('displayTitle', '   ')
        ->call('saveEditedTestTable', 1)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('test_profile', [
        'test_profile_id' => 1,
        'display_title' => null,
    ]);
});

it('requires a title when more than one active test table exists', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    DB::table('test_profile')->insert([
        [
            'test_profile_id' => 1,
            'instrument_type_id' => 31,
            'profile_name' => 'Main Test',
            'display_title' => 'Weight table',
            'description' => null,
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_profile_id' => 2,
            'instrument_type_id' => 31,
            'profile_name' => 'Second',
            'display_title' => 'Diameter table',
            'description' => null,
            'created_at' => '2026-10-14 09:00:00',
        ],
    ]);
    insertTestProfileVersion([
        'test_profile_version_id' => 1,
        'test_profile_id' => 1,
        'profile_name' => 'Main Test',
        'display_title' => 'Weight table',
    ]);
    insertTestProfileVersion([
        'test_profile_version_id' => 2,
        'test_profile_id' => 2,
        'profile_name' => 'Second',
        'display_title' => 'Diameter table',
    ]);

    managerComponent()
        ->call('selectProfile', 2)
        ->call('startEditingTestTable', 2)
        ->set('profileName', 'Second')
        ->set('displayTitle', '   ')
        ->call('saveEditedTestTable', 2)
        ->assertHasErrors(['displayTitle'])
        ->assertSee('Enter a table title.');
});

it('deletes an unused draft test table', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    managerComponent()
        ->call('startAddingTestTable')
        ->set('profileName', 'Weight Calibration')
        ->call('saveTestTable')
        ->assertSee('Delete Table')
        ->call('askToDeleteTestTable', 1)
        ->assertSee('This cannot be undone.')
        ->call('deleteTestTable', 1)
        ->assertHasNoErrors()
        ->assertSee('Test table deleted.')
        ->assertDontSee('Weight Calibration');

    $this->assertDatabaseMissing('test_profile', ['profile_name' => 'Weight Calibration']);
    $this->assertDatabaseCount('test_profile_version', 0);
});

it('deletes an unused active test table that has no retired version', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();

    managerComponent()
        ->assertSee('Delete Table')
        ->assertDontSee('Archive Table')
        ->call('askToDeleteTestTable', 1)
        ->call('deleteTestTable', 1)
        ->assertHasNoErrors()
        ->assertSee('Test table deleted.');

    $this->assertDatabaseMissing('test_profile', ['test_profile_id' => 1]);
    $this->assertDatabaseCount('test_profile_version', 0);
});

it('rejects deleting a used test table and keeps calibration rows', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();
    createManagerUsageTables();
    insertUsedCalibrationScope();

    managerComponent()
        ->assertDontSee('Delete Table')
        ->assertSee('Archive Table')
        ->call('askToDeleteTestTable', 1)
        ->assertHasErrors(['testTable'])
        ->assertSee('This test table cannot be deleted. Archive it instead.');

    $this->assertDatabaseHas('test_profile', ['test_profile_id' => 1]);
    $this->assertDatabaseHas('calibration_scope', ['calibration_scope_id' => 1]);
});

it('archives a used test table instead of deleting it', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();
    createManagerUsageTables();
    insertUsedCalibrationScope();

    $this->travelTo('2026-10-16 09:00:00');

    managerComponent()
        ->call('askToArchiveTestTable', 1)
        ->assertSee('Historical calibrations stay unchanged.')
        ->call('archiveTestTable', 1)
        ->assertHasNoErrors()
        ->assertSee('Test table archived.')
        ->assertSee('Archived Test Tables')
        ->assertSee('This test table is archived and read-only.')
        ->assertSee('Restore Table')
        ->assertDontSee('Edit Table')
        ->assertDontSee('Fork Draft')
        ->assertDontSee('Publish Version')
        ->assertDontSee('Manage Columns')
        ->call('startEditingTestTable', 1)
        ->assertHasErrors(['testTable'])
        ->call('startManagingColumns', 1)
        ->assertHasErrors(['testTable'])
        ->call('forkDraft', 1)
        ->assertHasErrors(['testTable'])
        ->call('askToPublishVersion', 1)
        ->assertNotFound();

    $this->assertDatabaseHas('test_profile', [
        'test_profile_id' => 1,
        'archived_at' => '2026-10-16 09:00:00',
    ]);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 11,
        'status' => 'active',
    ]);
    $this->assertDatabaseHas('calibration_scope', [
        'calibration_scope_id' => 1,
        'test_table_snapshot' => json_encode([
            'schema_version' => 1,
            'profile_name' => 'Main Test',
            'display_title' => 'Voltage table',
        ]),
    ]);
});

it('archives an unused test table that has a retired version', function () {
    createInstrumentDetailTestTables();
    insertTwoVersionTestTable();

    DB::table('test_profile_version')->where('test_profile_version_id', 12)->update([
        'status' => 'retired',
        'published_at' => '2026-10-14 10:00:00',
    ]);

    managerComponent()
        ->assertDontSee('Delete Table')
        ->assertSee('Archive Table')
        ->call('askToDeleteTestTable', 1)
        ->assertHasErrors(['testTable'])
        ->call('askToArchiveTestTable', 1)
        ->call('archiveTestTable', 1)
        ->assertHasNoErrors()
        ->assertSee('Archived Test Tables');

    $this->assertDatabaseHas('test_profile', ['test_profile_id' => 1]);
    expect(DB::table('test_profile')->where('test_profile_id', 1)->value('archived_at'))->not->toBeNull();
    $this->assertDatabaseCount('test_profile_version', 2);
});

it('restores an archived draft-only test table', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    managerComponent()
        ->call('startAddingTestTable')
        ->set('profileName', 'Weight Calibration')
        ->call('saveTestTable');

    DB::table('test_profile')->where('test_profile_id', 1)->update([
        'archived_at' => '2026-10-16 09:00:00',
    ]);

    $versionBefore = DB::table('test_profile_version')->where('test_profile_id', 1)->first();

    managerComponent()
        ->assertSee('Archived Test Tables')
        ->assertSee('Restore Table')
        ->assertDontSee('Edit Table')
        ->assertDontSee('wire:click="startManagingColumns', false)
        ->call('askToRestoreTestTable', 1)
        ->assertSee('Restore Test Table?')
        ->assertSee('Its existing versions, columns, rows, and formulas will be preserved.')
        ->call('restoreTestTable', 1)
        ->assertHasNoErrors()
        ->assertSee('Test table restored.')
        ->assertSee('Weight Calibration')
        ->assertDontSee('Archived Test Tables')
        ->assertSee('Edit Table')
        ->assertSee('Delete Table');

    $this->assertDatabaseHas('test_profile', [
        'test_profile_id' => 1,
        'archived_at' => null,
        'profile_name' => 'Weight Calibration',
    ]);
    $versionAfter = DB::table('test_profile_version')->where('test_profile_id', 1)->first();

    expect($versionAfter->status)->toBe($versionBefore->status)
        ->and($versionAfter->version_number)->toBe($versionBefore->version_number)
        ->and($versionAfter->profile_name)->toBe($versionBefore->profile_name)
        ->and($versionAfter->test_profile_version_id)->toBe($versionBefore->test_profile_version_id);
    $this->assertDatabaseCount('test_profile_version', 1);
});

it('restores an archived active test table without publishing or forking', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();

    DB::table('test_profile')->where('test_profile_id', 1)->update([
        'archived_at' => '2026-10-16 09:00:00',
    ]);
    managerComponent()
        ->call('askToRestoreTestTable', 1)
        ->call('restoreTestTable', 1)
        ->assertHasNoErrors()
        ->assertSee('Test table restored.')
        ->assertSee('Fork Draft')
        ->assertDontSee('Publish Version')
        ->assertDontSee('wire:click="startManagingColumns', false);

    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 11,
        'status' => 'active',
        'version_number' => 1,
    ]);
    $this->assertDatabaseCount('test_profile_version', 1);
    $this->assertDatabaseCount('test_column', 3);
    $this->assertDatabaseCount('test_point', 1);
    $this->assertDatabaseHas('test_column', [
        'test_column_id' => 23,
        'formula_code' => 'standard_minus_reading',
        'formula_inputs' => '["standard_value","reading"]',
    ]);
});

it('restores an archived active and draft pair without changing version status', function () {
    createInstrumentDetailTestTables();
    insertTwoVersionTestTable();

    DB::table('test_profile')->where('test_profile_id', 1)->update([
        'archived_at' => '2026-10-16 09:00:00',
    ]);

    managerComponent()
        ->call('restoreTestTable', 1)
        ->assertHasNoErrors()
        ->assertSee('Manage Columns')
        ->assertSee('Publish Version');

    $this->assertDatabaseHas('test_profile', ['test_profile_id' => 1, 'archived_at' => null]);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 11,
        'status' => 'active',
    ]);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 12,
        'status' => 'draft',
    ]);
    $this->assertDatabaseCount('test_profile_version', 2);
});

it('restores an archived test table that has retired versions', function () {
    createInstrumentDetailTestTables();
    insertTwoVersionTestTable();

    DB::table('test_profile_version')->where('test_profile_version_id', 12)->update([
        'status' => 'retired',
        'published_at' => '2026-10-14 10:00:00',
    ]);
    DB::table('test_profile')->where('test_profile_id', 1)->update([
        'archived_at' => '2026-10-16 09:00:00',
    ]);

    managerComponent()
        ->call('restoreTestTable', 1)
        ->assertHasNoErrors()
        ->assertSee('Archive Table')
        ->assertDontSee('Delete Table');

    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 11,
        'status' => 'active',
    ]);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 12,
        'status' => 'retired',
    ]);
});

it('rejects restoring a test table that is not archived', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();

    managerComponent()
        ->assertDontSee('Restore Table')
        ->call('askToRestoreTestTable', 1)
        ->assertHasErrors(['testTable'])
        ->assertSee('This test table is not archived.');

    $this->assertDatabaseHas('test_profile', [
        'test_profile_id' => 1,
        'archived_at' => null,
    ]);
});

it('rejects restoring a test table that belongs to another instrument type', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();

    DB::table('test_profile')->insert([
        'test_profile_id' => 2,
        'instrument_type_id' => 32,
        'profile_name' => 'Foreign Table',
        'display_title' => null,
        'description' => null,
        'created_at' => '2026-10-14 09:00:00',
        'archived_at' => '2026-10-16 09:00:00',
    ]);

    managerComponent()
        ->call('restoreTestTable', 2)
        ->assertNotFound();

    $this->assertDatabaseHas('test_profile', [
        'test_profile_id' => 2,
        'archived_at' => '2026-10-16 09:00:00',
    ]);
});

it('rejects restoring a test table that no longer exists', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();

    managerComponent()
        ->call('restoreTestTable', 99)
        ->assertNotFound();
});

it('rejects restoring when a live table already uses the same title', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    DB::table('test_profile')->insert([
        [
            'test_profile_id' => 1,
            'instrument_type_id' => 31,
            'profile_name' => 'Main Test',
            'display_title' => 'Weight table',
            'description' => null,
            'created_at' => '2026-10-14 09:00:00',
            'archived_at' => null,
        ],
        [
            'test_profile_id' => 2,
            'instrument_type_id' => 31,
            'profile_name' => 'Second',
            'display_title' => 'weight table',
            'description' => null,
            'created_at' => '2026-10-14 09:00:00',
            'archived_at' => '2026-10-16 09:00:00',
        ],
    ]);
    insertTestProfileVersion([
        'test_profile_version_id' => 1,
        'test_profile_id' => 1,
        'profile_name' => 'Main Test',
        'display_title' => 'Weight table',
    ]);
    insertTestProfileVersion([
        'test_profile_version_id' => 2,
        'test_profile_id' => 2,
        'profile_name' => 'Second',
        'display_title' => 'weight table',
    ]);

    managerComponent()
        ->call('selectProfile', 2)
        ->call('restoreTestTable', 2)
        ->assertHasErrors(['testTable'])
        ->assertSee('Table titles must be different.');

    $this->assertDatabaseHas('test_profile', [
        'test_profile_id' => 2,
        'archived_at' => '2026-10-16 09:00:00',
    ]);
});

it('keeps calibration scope and snapshot unchanged after restore', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();
    createManagerUsageTables();
    insertUsedCalibrationScope();

    DB::table('test_profile')->where('test_profile_id', 1)->update([
        'archived_at' => '2026-10-16 09:00:00',
    ]);
    $snapshot = DB::table('calibration_scope')->where('calibration_scope_id', 1)->value('test_table_snapshot');

    managerComponent()
        ->call('restoreTestTable', 1)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('calibration_scope', [
        'calibration_scope_id' => 1,
        'test_profile_id' => 1,
        'test_profile_version_id' => 11,
        'test_table_snapshot' => $snapshot,
    ]);
    $this->assertDatabaseCount('calibration_test_result', 0);
});

it('keeps add, columns, fork, and publish available on a live table', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();

    managerComponent()
        ->assertSee('Sodiline')
        ->assertSee('Add Test Table')
        ->assertSee('Fork Draft')
        ->assertSee('Manage Test Components')
        ->assertDontSee('Restore Table')
        ->call('forkDraft', 1)
        ->assertHasNoErrors()
        ->assertSee('Manage Columns')
        ->assertSee('Edit Table')
        ->assertSee('Publish Version');
});
