<?php

use App\Models\TestProfileVersion;
use App\TestColumns\SqliteTestProfileLifecycle;
use App\TestColumns\TestProfileLifecycleException;
use App\TestColumns\TestTableSnapshotBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createLifecycleUsageTables(): void
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

it('deletes an unused test table and its versions', function () {
    createInstrumentDetailTestTables();
    insertDynamicRowFixture();

    app(SqliteTestProfileLifecycle::class)->deleteUnused(1);

    $this->assertDatabaseMissing('test_profile', ['test_profile_id' => 1]);
    $this->assertDatabaseCount('test_profile_version', 0);
    $this->assertDatabaseCount('test_column', 0);
    $this->assertDatabaseCount('test_point', 0);
    $this->assertDatabaseCount('test_point_value', 0);
});

it('archives a test table without deleting versions or columns', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();

    $this->travelTo('2026-10-16 09:00:00');
    app(SqliteTestProfileLifecycle::class)->archive(1);
    app(SqliteTestProfileLifecycle::class)->archive(1);

    $this->assertDatabaseHas('test_profile', [
        'test_profile_id' => 1,
        'archived_at' => '2026-10-16 09:00:00',
        'profile_name' => 'Main Test',
    ]);
    $this->assertDatabaseCount('test_profile_version', 1);
    $this->assertDatabaseCount('test_column', 3);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 11,
        'status' => 'active',
    ]);
});

it('rejects deleting a test table that is used by a calibration scope', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();
    createLifecycleUsageTables();

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
        'test_profile_id' => 1,
        'test_profile_version_id' => 11,
        'test_table_snapshot' => json_encode(['profile_name' => 'Main Test']),
        'scope_order' => 1,
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);

    expect(fn () => app(SqliteTestProfileLifecycle::class)->deleteUnused(1))
        ->toThrow(TestProfileLifecycleException::class, 'This test table is used by a calibration and cannot be deleted.');

    $this->assertDatabaseHas('test_profile', ['test_profile_id' => 1]);
    $this->assertDatabaseHas('calibration_scope', ['calibration_scope_id' => 1]);
    $this->assertDatabaseCount('test_profile_version', 1);
});

it('restores an archived test table without changing versions', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();

    DB::table('test_profile')->where('test_profile_id', 1)->update([
        'archived_at' => '2026-10-16 09:00:00',
    ]);

    app(SqliteTestProfileLifecycle::class)->restore(1);

    $this->assertDatabaseHas('test_profile', [
        'test_profile_id' => 1,
        'archived_at' => null,
        'profile_name' => 'Main Test',
    ]);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 11,
        'status' => 'active',
        'profile_name' => 'Main Test',
    ]);
    $this->assertDatabaseCount('test_column', 3);
});

it('rejects restoring a test table that is not archived', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();

    expect(fn () => app(SqliteTestProfileLifecycle::class)->restore(1))
        ->toThrow(TestProfileLifecycleException::class, 'This test table is not archived.');

    $this->assertDatabaseHas('test_profile', [
        'test_profile_id' => 1,
        'archived_at' => null,
    ]);
});

it('rejects restoring a test table that does not exist', function () {
    createInstrumentDetailTestTables();
    insertInstrumentDetailSubject();

    expect(fn () => app(SqliteTestProfileLifecycle::class)->restore(99))
        ->toThrow(TestProfileLifecycleException::class, 'This test table could not be found.');
});

it('does not change an active snapshot after a draft identity edit', function () {
    createInstrumentDetailTestTables();
    insertTwoVersionTestTable();

    $before = app(TestTableSnapshotBuilder::class)->build(
        TestProfileVersion::query()->findOrFail(11),
    );

    DB::table('test_profile_version')->where('test_profile_version_id', 12)->update([
        'profile_name' => 'Draft Rename',
        'display_title' => 'Draft title',
    ]);

    $after = app(TestTableSnapshotBuilder::class)->build(
        TestProfileVersion::query()->findOrFail(11),
    );

    expect($after['profile_name'])->toBe('Main Test')
        ->and($after['display_title'])->toBe('Voltage table')
        ->and($after['test_profile_version_id'])->toBe($before['test_profile_version_id'])
        ->and($after['columns'])->toBe($before['columns']);
});
