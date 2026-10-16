<?php

use App\TestColumns\TestTableVersionWriteException;
use App\TestColumns\TestTableVersionWriter;
use Illuminate\Support\Facades\DB;

it('forks the active version into a new draft without changing the source', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();

    $draftId = app(TestTableVersionWriter::class)->fork(11);

    expect($draftId)->not->toBe(11);

    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 11,
        'status' => 'active',
        'version_number' => 1,
        'profile_name' => 'Main Test',
        'description' => 'Source notes',
    ]);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => $draftId,
        'test_profile_id' => 1,
        'status' => 'draft',
        'version_number' => 2,
        'profile_name' => 'Main Test',
        'display_title' => 'Voltage table',
        'description' => 'Source notes',
        'published_at' => null,
    ]);
    $this->assertDatabaseHas('test_column', [
        'test_column_id' => 21,
        'test_profile_version_id' => 11,
        'column_key' => 'standard_value',
    ]);
    $this->assertDatabaseHas('test_point', [
        'test_point_id' => 31,
        'test_profile_version_id' => 11,
        'description' => 'First point',
    ]);

    $draftColumns = DB::table('test_column')
        ->where('test_profile_version_id', $draftId)
        ->orderBy('column_order')
        ->get();
    $draftPoints = DB::table('test_point')
        ->where('test_profile_version_id', $draftId)
        ->get();
    $draftValues = DB::table('test_point_value')
        ->where('test_profile_version_id', $draftId)
        ->get();

    expect($draftColumns)->toHaveCount(3)
        ->and($draftColumns->pluck('test_column_id')->all())->not->toContain(21, 22, 23)
        ->and($draftColumns->pluck('column_key')->all())->toBe(['standard_value', 'reading', 'correction'])
        ->and($draftColumns->firstWhere('column_key', 'correction')?->formula_code)->toBe('standard_minus_reading')
        ->and($draftColumns->firstWhere('column_key', 'correction')?->formula_inputs)->toBe('["standard_value","reading"]')
        ->and($draftPoints)->toHaveCount(1)
        ->and((int) $draftPoints->first()->test_point_id)->not->toBe(31)
        ->and($draftPoints->first()->description)->toBe('First point')
        ->and($draftValues)->toHaveCount(2);

    $standardId = (int) $draftColumns->firstWhere('column_key', 'standard_value')?->test_column_id;
    $readingId = (int) $draftColumns->firstWhere('column_key', 'reading')?->test_column_id;
    $draftPointId = (int) $draftPoints->first()->test_point_id;
    $emptyCell = $draftValues->firstWhere('test_column_id', $standardId);
    $readingCell = $draftValues->firstWhere('test_column_id', $readingId);

    expect($emptyCell)->not->toBeNull()
        ->and($emptyCell?->numeric_value)->toBeNull()
        ->and($emptyCell?->text_value)->toBeNull()
        ->and((int) $emptyCell?->test_point_id)->toBe($draftPointId)
        ->and((float) $readingCell?->numeric_value)->toBe(99.5);
});

it('rejects forking a draft, a retired version, or an active version that already has a draft', function () {
    createInstrumentDetailTestTables();
    insertTwoVersionTestTable();
    insertTestProfileVersion([
        'test_profile_version_id' => 13,
        'test_profile_id' => 1,
        'version_number' => 3,
        'status' => 'retired',
        'profile_name' => 'Main Test',
        'published_at' => '2026-10-13 09:00:00',
    ]);

    $writer = app(TestTableVersionWriter::class);

    expect(fn () => $writer->fork(12))
        ->toThrow(TestTableVersionWriteException::class, 'Fork the active version, not a draft.');
    expect(fn () => $writer->fork(13))
        ->toThrow(TestTableVersionWriteException::class, 'Fork the active version, not a retired version.');
    expect(fn () => $writer->fork(11))
        ->toThrow(TestTableVersionWriteException::class, 'A draft version already exists for this test table.');
    expect(fn () => $writer->fork(99))
        ->toThrow(TestTableVersionWriteException::class, 'This test table version could not be found.');
});

it('publishes a complete draft and retires the previous active version', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();

    $writer = app(TestTableVersionWriter::class);
    $draftId = $writer->fork(11);

    DB::table('test_profile_version')->where('test_profile_version_id', $draftId)->update([
        'profile_name' => 'Main Test v2',
        'display_title' => 'Updated voltage',
        'description' => 'Draft notes',
    ]);

    $publishedId = $writer->publish(1);

    expect($publishedId)->toBe($draftId);

    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => 11,
        'status' => 'retired',
        'published_at' => '2026-10-14 09:00:00',
    ]);
    $this->assertDatabaseHas('test_profile_version', [
        'test_profile_version_id' => $draftId,
        'status' => 'active',
    ]);
    $this->assertDatabaseHas('test_profile', [
        'test_profile_id' => 1,
        'profile_name' => 'Main Test v2',
        'display_title' => 'Updated voltage',
        'description' => 'Draft notes',
    ]);
    expect(DB::table('test_profile_version')->where('test_profile_id', 1)->where('status', 'active')->count())->toBe(1)
        ->and(DB::table('test_profile_version')->where('test_profile_id', 1)->where('status', 'draft')->count())->toBe(0);
});

it('rejects publishing when the test table has no draft', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();

    expect(fn () => app(TestTableVersionWriter::class)->publish(1))
        ->toThrow(TestTableVersionWriteException::class, 'This test table has no draft version to publish.');
});

it('rejects publishing a draft that has columns but no rows', function () {
    createInstrumentDetailTestTables();
    insertDynamicRowFixture();

    expect(fn () => app(TestTableVersionWriter::class)->publish(1))
        ->toThrow(TestTableVersionWriteException::class, 'Publish a draft that has at least one column and one row.');
});

it('rejects publishing a draft that has a row but no columns', function () {
    createInstrumentDetailTestTables();
    insertColumnManagerFixture();

    expect(fn () => app(TestTableVersionWriter::class)->publish(1))
        ->toThrow(TestTableVersionWriteException::class, 'Publish a draft that has at least one column and one row.');
});

it('rejects fork and publish when the versioning catalog is not installed', function () {
    createInstrumentDetailTestTables(withVersioning: false);
    insertInstrumentDetailSubject();

    $writer = app(TestTableVersionWriter::class);

    expect(fn () => $writer->fork(1))
        ->toThrow(TestTableVersionWriteException::class, 'Test table versioning is not available.');
    expect(fn () => $writer->publish(1))
        ->toThrow(TestTableVersionWriteException::class, 'Test table versioning is not available.');
});
