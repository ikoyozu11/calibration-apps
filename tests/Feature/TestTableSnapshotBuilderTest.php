<?php

use App\Models\TestProfileVersion;
use App\TestColumns\TestTableSnapshotBuilder;
use App\TestColumns\TestTableSnapshotException;
use App\TestColumns\TestTableVersionWriter;
use Illuminate\Support\Facades\DB;

function insertSnapshotBuilderFixture(): void
{
    insertInstrumentDetailSubject();

    DB::table('test_profile')->insert([
        'test_profile_id' => 1,
        'instrument_type_id' => 31,
        'profile_name' => 'Main Test',
        'display_title' => 'Live title',
        'description' => 'Live description',
        'created_at' => '2026-10-14 09:00:00',
    ]);

    insertTestProfileVersion([
        'test_profile_version_id' => 11,
        'test_profile_id' => 1,
        'version_number' => 2,
        'status' => 'active',
        'profile_name' => 'Main Test',
        'display_title' => 'Voltage table',
        'description' => 'Correction tolerance ±0.1 g',
        'published_at' => '2026-10-14 09:00:00',
    ]);
    insertTestProfileVersion([
        'test_profile_version_id' => 12,
        'test_profile_id' => 1,
        'version_number' => 3,
        'status' => 'draft',
        'profile_name' => 'Main Test',
        'display_title' => 'Draft table',
        'description' => 'Draft notes',
    ]);
    insertTestProfileVersion([
        'test_profile_version_id' => 13,
        'test_profile_id' => 1,
        'version_number' => 1,
        'status' => 'retired',
        'profile_name' => 'Main Test',
        'display_title' => 'Retired table',
        'description' => 'Retired notes',
        'published_at' => '2026-10-13 09:00:00',
    ]);

    DB::table('test_column')->insert([
        [
            'test_column_id' => 23,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'column_key' => 'soak_time',
            'column_label' => 'Soak Time',
            'column_order' => 3,
            'data_type' => 'duration',
            'value_source' => 'template',
            'unit' => 's',
            'display_scale' => null,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_column_id' => 21,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'column_key' => 'standard_value',
            'column_label' => 'Standard Value',
            'column_order' => 1,
            'data_type' => 'numeric',
            'value_source' => 'template',
            'unit' => 'g',
            'display_scale' => 2,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_column_id' => 22,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'column_key' => 'note',
            'column_label' => 'Note',
            'column_order' => 2,
            'data_type' => 'text',
            'value_source' => 'template',
            'unit' => null,
            'display_scale' => null,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_column_id' => 24,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'column_key' => 'reading',
            'column_label' => 'Reading',
            'column_order' => 4,
            'data_type' => 'numeric',
            'value_source' => 'engineer',
            'unit' => 'g',
            'display_scale' => 3,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_column_id' => 25,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'column_key' => 'correction',
            'column_label' => 'Correction',
            'column_order' => 5,
            'data_type' => 'numeric',
            'value_source' => 'calculated',
            'unit' => 'g',
            'display_scale' => 3,
            'formula_code' => 'standard_minus_reading',
            'formula_inputs' => '["standard_value","reading"]',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_column_id' => 31,
            'test_profile_id' => 1,
            'test_profile_version_id' => 12,
            'column_key' => 'draft_secret',
            'column_label' => 'Draft Secret',
            'column_order' => 1,
            'data_type' => 'text',
            'value_source' => 'template',
            'unit' => null,
            'display_scale' => null,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_column_id' => 41,
            'test_profile_id' => 1,
            'test_profile_version_id' => 13,
            'column_key' => 'retired_value',
            'column_label' => 'Retired Value',
            'column_order' => 1,
            'data_type' => 'numeric',
            'value_source' => 'template',
            'unit' => 'g',
            'display_scale' => null,
            'formula_code' => null,
            'formula_inputs' => '[]',
            'created_at' => '2026-10-13 09:00:00',
        ],
    ]);

    DB::table('test_point')->insert([
        [
            'test_point_id' => 32,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'point_order' => 2,
            'nominal_value' => 200,
            'unit' => 'g',
            'tolerance_plus' => 0.2,
            'tolerance_minus' => -0.2,
            'description' => 'Second point',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_point_id' => 31,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'point_order' => 1,
            'nominal_value' => 100,
            'unit' => 'g',
            'tolerance_plus' => 0.1,
            'tolerance_minus' => -0.1,
            'description' => 'First point',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_point_id' => 42,
            'test_profile_id' => 1,
            'test_profile_version_id' => 12,
            'point_order' => 1,
            'nominal_value' => null,
            'unit' => null,
            'tolerance_plus' => null,
            'tolerance_minus' => null,
            'description' => 'Draft row',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_point_id' => 51,
            'test_profile_id' => 1,
            'test_profile_version_id' => 13,
            'point_order' => 1,
            'nominal_value' => 50,
            'unit' => 'g',
            'tolerance_plus' => null,
            'tolerance_minus' => null,
            'description' => 'Retired row',
            'created_at' => '2026-10-13 09:00:00',
        ],
    ]);

    DB::table('test_point_value')->insert([
        [
            'test_point_value_id' => 61,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'test_point_id' => 31,
            'test_column_id' => 21,
            'numeric_value' => '100.50',
            'text_value' => null,
            'unit' => 'g',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_point_value_id' => 62,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'test_point_id' => 31,
            'test_column_id' => 22,
            'numeric_value' => null,
            'text_value' => 'lab-note',
            'unit' => null,
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_point_value_id' => 63,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'test_point_id' => 31,
            'test_column_id' => 23,
            'numeric_value' => 90,
            'text_value' => null,
            'unit' => 's',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_point_value_id' => 64,
            'test_profile_id' => 1,
            'test_profile_version_id' => 11,
            'test_point_id' => 31,
            'test_column_id' => 24,
            'numeric_value' => '99.5',
            'text_value' => null,
            'unit' => 'g',
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_point_value_id' => 65,
            'test_profile_id' => 1,
            'test_profile_version_id' => 12,
            'test_point_id' => 42,
            'test_column_id' => 31,
            'numeric_value' => null,
            'text_value' => 'draft-secret',
            'unit' => null,
            'created_at' => '2026-10-14 09:00:00',
        ],
        [
            'test_point_value_id' => 66,
            'test_profile_id' => 1,
            'test_profile_version_id' => 13,
            'test_point_id' => 51,
            'test_column_id' => 41,
            'numeric_value' => '50',
            'text_value' => null,
            'unit' => 'g',
            'created_at' => '2026-10-13 09:00:00',
        ],
    ]);
}

it('builds a schema version 1 snapshot from an active version', function () {
    createInstrumentDetailTestTables();
    insertSnapshotBuilderFixture();
    $this->travelTo('2026-10-15 04:00:00');

    $snapshot = app(TestTableSnapshotBuilder::class)->build(
        TestProfileVersion::query()->findOrFail(11),
    );

    expect(TestTableSnapshotBuilder::isWellformed($snapshot))->toBeTrue()
        ->and($snapshot['schema_version'])->toBe(1)
        ->and($snapshot['captured_at'])->toBe('2026-10-15T04:00:00Z')
        ->and($snapshot['test_profile_id'])->toBe(1)
        ->and($snapshot['test_profile_version_id'])->toBe(11)
        ->and($snapshot['version_number'])->toBe(2)
        ->and($snapshot['profile_name'])->toBe('Main Test')
        ->and($snapshot['display_title'])->toBe('Voltage table')
        ->and($snapshot['description'])->toBe('Correction tolerance ±0.1 g')
        ->and($snapshot['duration_format'])->toBe('hh:mm:ss');
});

it('rejects a draft version as the source of a new snapshot', function () {
    createInstrumentDetailTestTables();
    insertSnapshotBuilderFixture();

    expect(fn () => app(TestTableSnapshotBuilder::class)->build(
        TestProfileVersion::query()->findOrFail(12),
    ))->toThrow(TestTableSnapshotException::class, 'A calibration snapshot cannot be taken from a draft Test Table version.');
});

it('rejects a retired version as the source of a new snapshot', function () {
    createInstrumentDetailTestTables();
    insertSnapshotBuilderFixture();

    expect(fn () => app(TestTableSnapshotBuilder::class)->build(
        TestProfileVersion::query()->findOrFail(13),
    ))->toThrow(TestTableSnapshotException::class, 'A calibration snapshot cannot be taken from a retired Test Table version.');
});

it('orders columns and rows by their configured order', function () {
    createInstrumentDetailTestTables();
    insertSnapshotBuilderFixture();

    $snapshot = app(TestTableSnapshotBuilder::class)->build(
        TestProfileVersion::query()->findOrFail(11),
    );

    expect(array_column($snapshot['columns'], 'column_key'))->toBe([
        'standard_value',
        'note',
        'soak_time',
        'reading',
        'correction',
    ])
        ->and(array_column($snapshot['columns'], 'column_order'))->toBe([1, 2, 3, 4, 5])
        ->and(array_column($snapshot['rows'], 'test_point_id'))->toBe([31, 32])
        ->and(array_column($snapshot['rows'], 'point_order'))->toBe([1, 2]);
});

it('stores a numeric template value as a string quantity', function () {
    createInstrumentDetailTestTables();
    insertSnapshotBuilderFixture();

    $snapshot = app(TestTableSnapshotBuilder::class)->build(
        TestProfileVersion::query()->findOrFail(11),
    );

    expect($snapshot['rows'][0]['template_values']['standard_value'])->toBe([
        'numeric_value' => '100.5',
        'text_value' => null,
        'unit' => 'g',
    ])
        ->and($snapshot['rows'][0]['nominal_value'])->toBe('100')
        ->and($snapshot['rows'][0]['tolerance_plus'])->toBe('0.1')
        ->and($snapshot['rows'][0]['tolerance_minus'])->toBe('-0.1');
});

it('stores a text template value without a numeric quantity', function () {
    createInstrumentDetailTestTables();
    insertSnapshotBuilderFixture();

    $snapshot = app(TestTableSnapshotBuilder::class)->build(
        TestProfileVersion::query()->findOrFail(11),
    );

    expect($snapshot['rows'][0]['template_values']['note'])->toBe([
        'numeric_value' => null,
        'text_value' => 'lab-note',
        'unit' => null,
    ]);
});

it('stores a duration template value as whole seconds', function () {
    createInstrumentDetailTestTables();
    insertSnapshotBuilderFixture();

    $snapshot = app(TestTableSnapshotBuilder::class)->build(
        TestProfileVersion::query()->findOrFail(11),
    );

    expect($snapshot['rows'][0]['template_values']['soak_time'])->toBe([
        'numeric_value' => '90',
        'text_value' => null,
        'unit' => 's',
    ]);
});

it('keeps formula code and formula inputs on calculated columns', function () {
    createInstrumentDetailTestTables();
    insertSnapshotBuilderFixture();

    $snapshot = app(TestTableSnapshotBuilder::class)->build(
        TestProfileVersion::query()->findOrFail(11),
    );

    $correction = collect($snapshot['columns'])->firstWhere('column_key', 'correction');

    expect($correction)->not->toBeNull()
        ->and($correction['value_source'])->toBe('calculated')
        ->and($correction['formula_code'])->toBe('standard_minus_reading')
        ->and($correction['formula_inputs'])->toBe(['standard_value', 'reading']);
});

it('does not mix columns, rows, or cells from another version', function () {
    createInstrumentDetailTestTables();
    insertSnapshotBuilderFixture();

    $snapshot = app(TestTableSnapshotBuilder::class)->build(
        TestProfileVersion::query()->findOrFail(11),
    );
    $encoded = json_encode($snapshot);

    expect(array_column($snapshot['columns'], 'column_key'))->not->toContain('draft_secret', 'retired_value')
        ->and(array_column($snapshot['rows'], 'test_point_id'))->not->toContain(42, 51)
        ->and($snapshot['rows'][0]['template_values'])->not->toHaveKey('reading')
        ->and($snapshot['rows'][0]['template_values'])->not->toHaveKey('correction')
        ->and($encoded)->not->toContain('draft-secret')
        ->and($encoded)->not->toContain('Retired row')
        ->and(array_keys($snapshot['rows'][0]['template_values']))->toBe([
            'standard_value',
            'note',
            'soak_time',
        ]);
});

it('keeps a captured snapshot unchanged after fork and publish', function () {
    createInstrumentDetailTestTables();
    insertActiveVersionTestTable();
    $this->travelTo('2026-10-15 04:00:00');

    $builder = app(TestTableSnapshotBuilder::class);
    $captured = $builder->build(TestProfileVersion::query()->findOrFail(11));
    $frozen = json_encode($captured);

    $writer = app(TestTableVersionWriter::class);
    $draftId = $writer->fork(11);
    $draftStandardId = (int) DB::table('test_column')
        ->where('test_profile_version_id', $draftId)
        ->where('column_key', 'standard_value')
        ->value('test_column_id');

    DB::table('test_point_value')
        ->where('test_profile_version_id', $draftId)
        ->where('test_column_id', $draftStandardId)
        ->update(['numeric_value' => '200']);

    $publishedId = $writer->publish(1);
    $published = $builder->build(TestProfileVersion::query()->findOrFail($publishedId));

    expect(json_encode($captured))->toBe($frozen)
        ->and($captured['test_profile_version_id'])->toBe(11)
        ->and($captured['version_number'])->toBe(1)
        ->and($captured['rows'][0]['test_point_id'])->toBe(31)
        ->and($published['test_profile_version_id'])->toBe($publishedId)
        ->and($published['test_profile_version_id'])->not->toBe(11)
        ->and($published['rows'][0]['test_point_id'])->not->toBe(31);

    expect(fn () => $builder->build(TestProfileVersion::query()->findOrFail(11)))
        ->toThrow(TestTableSnapshotException::class, 'A calibration snapshot cannot be taken from a retired Test Table version.');
});
