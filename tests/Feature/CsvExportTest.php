<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createCsvExportTestTables(): void
{
    Schema::create('location', function (Blueprint $table): void {
        $table->unsignedBigInteger('location_id')->primary();
        $table->string('location_code', 50);
        $table->string('location_name', 150);
        $table->text('description')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('detail_location', function (Blueprint $table): void {
        $table->unsignedBigInteger('detail_location_id')->primary();
        $table->unsignedBigInteger('location_id');
        $table->string('detail_location_code', 50);
        $table->string('detail_location_name', 150);
        $table->text('description')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name', 150);
        $table->unsignedBigInteger('detail_location_id')->nullable();
        $table->text('description')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('instrument_type', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_type_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name', 150);
        $table->text('description')->nullable();
        $table->timestamp('created_at');
        $table->string('asset_number')->nullable();
        $table->string('serial_number')->nullable();
    });

    Schema::create('calibration', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->unsignedBigInteger('calibration_provider_id');
        $table->string('calibration_number');
        $table->date('calibration_date')->nullable();
        $table->date('calibration_due')->nullable();
        $table->string('calibration_method');
        $table->string('calibration_result')->nullable();
        $table->string('certificate_number')->nullable();
        $table->text('remarks')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->date('action_date')->nullable();
        $table->text('resume')->nullable();
        $table->unsignedBigInteger('customer_id')->nullable();
    });

    Schema::create('certificate', function (Blueprint $table): void {
        $table->unsignedBigInteger('certificate_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->string('certificate_number');
        $table->date('issued_date');
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function insertCsvExportCalibration(array $attributes): void
{
    DB::table('calibration')->insert(array_merge([
        'calibration_provider_id' => 1,
        'calibration_number' => 'CAL',
        'calibration_date' => '2026-01-01',
        'calibration_due' => '2026-06-01',
        'calibration_method' => 'External',
        'calibration_result' => null,
        'certificate_number' => null,
        'remarks' => null,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
        'action_date' => null,
        'resume' => null,
        'customer_id' => null,
    ], $attributes));
}

/**
 * @return list<list<string|null>>
 */
function csvExportRows(string $content): array
{
    expect($content)->toStartWith("\xEF\xBB\xBF");

    $handle = fopen('php://temp', 'r+b');

    expect($handle)->not->toBeFalse();

    fwrite($handle, substr($content, 3));
    rewind($handle);

    $rows = [];

    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }

    fclose($handle);

    return $rows;
}

beforeEach(function () {
    Model::preventLazyLoading();
});

it('exports one csv row per instrument type using the latest calibration', function () {
    createCsvExportTestTables();

    DB::table('location')->insert([
        'location_id' => 1,
        'location_code' => 'FMD',
        'location_name' => 'Factory Mix',
        'description' => null,
        'created_at' => '2026-01-01 00:00:00',
    ]);

    DB::table('detail_location')->insert([
        'detail_location_id' => 2,
        'location_id' => 1,
        'detail_location_code' => 'FM05-06',
        'detail_location_name' => 'Floor Mix',
        'description' => null,
        'created_at' => '2026-01-01 00:00:00',
    ]);

    DB::table('instrument')->insert([
        [
            'instrument_id' => 3,
            'instrument_name' => 'Sodiline',
            'detail_location_id' => 2,
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
        ],
        [
            'instrument_id' => 7,
            'instrument_name' => 'Uncalibrated Gauge',
            'detail_location_id' => null,
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
        ],
        [
            'instrument_id' => 8,
            'instrument_name' => 'No Types',
            'detail_location_id' => null,
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
        ],
        [
            'instrument_id' => 9,
            'instrument_name' => 'On Track Gauge',
            'detail_location_id' => 2,
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
        ],
        [
            'instrument_id' => 11,
            'instrument_name' => 'Null Due Gauge',
            'detail_location_id' => null,
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
        ],
    ]);

    DB::table('instrument_type')->insert([
        [
            'instrument_type_id' => 10,
            'instrument_id' => 3,
            'type_name' => 'Weight',
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
            'serial_number' => 'Sr. 28 Nr. 752',
            'asset_number' => 'ID12-1123100257-0',
        ],
        [
            'instrument_type_id' => 11,
            'instrument_id' => 3,
            'type_name' => 'Diameter',
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
            'serial_number' => 'Sr. 19 Nr. 528',
            'asset_number' => null,
        ],
        [
            'instrument_type_id' => 20,
            'instrument_id' => 7,
            'type_name' => 'Spare',
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
            'serial_number' => 'PLAIN-SERIAL',
            'asset_number' => 'PLAIN-ASSET',
        ],
        [
            'instrument_type_id' => 30,
            'instrument_id' => 9,
            'type_name' => 'Gauge',
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
            'serial_number' => 'G-1',
            'asset_number' => 'A-1',
        ],
        [
            'instrument_type_id' => 40,
            'instrument_id' => 11,
            'type_name' => 'Open',
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
            'serial_number' => 'NULL-DUE',
            'asset_number' => 'NULL-ASSET',
        ],
    ]);

    insertCsvExportCalibration([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'calibration_date' => '2026-01-01',
        'calibration_due' => today()->addYear()->toDateString(),
    ]);
    insertCsvExportCalibration([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'calibration_date' => '2026-08-01',
        'calibration_due' => today()->toDateString(),
    ]);
    insertCsvExportCalibration([
        'calibration_id' => 5,
        'instrument_id' => 3,
        'calibration_date' => '2026-08-01',
        'calibration_due' => today()->subDay()->toDateString(),
    ]);
    insertCsvExportCalibration([
        'calibration_id' => 9,
        'instrument_id' => 9,
        'calibration_date' => '2026-09-01',
        'calibration_due' => today()->toDateString(),
    ]);
    insertCsvExportCalibration([
        'calibration_id' => 11,
        'instrument_id' => 11,
        'calibration_date' => '2026-03-03',
        'calibration_due' => null,
    ]);

    $response = $this->get(route('instruments.export.csv'));

    $response->assertSuccessful();
    $response->assertDownload('instrument-types.csv');

    $rows = csvExportRows($response->streamedContent());
    $bySerial = [];

    foreach (array_slice($rows, 1) as $row) {
        $bySerial[$row[2]] = $row;
    }

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($rows[0])->toBe([
            'Instrument Name',
            'Type Name',
            'Serial Number',
            'Asset Number',
            'Location Code',
            'Location Name',
            'Detail Location Code',
            'Detail Location Name',
            'Calibration Date',
            'Calibration Due',
            'Status',
        ])
        ->and($rows)->toHaveCount(6)
        ->and(implode("\n", array_column($rows, 0)))->not->toContain('No Types')
        ->and($bySerial['Sr. 28 Nr. 752'])->toBe([
            'Sodiline',
            'Weight',
            'Sr. 28 Nr. 752',
            'ID12-1123100257-0',
            'FMD',
            'Factory Mix',
            'FM05-06',
            'Floor Mix',
            '2026-08-01',
            today()->subDay()->toDateString(),
            'Overdue',
        ])
        ->and($bySerial['Sr. 19 Nr. 528'])->toBe([
            'Sodiline',
            'Diameter',
            'Sr. 19 Nr. 528',
            '—',
            'FMD',
            'Factory Mix',
            'FM05-06',
            'Floor Mix',
            '2026-08-01',
            today()->subDay()->toDateString(),
            'Overdue',
        ])
        ->and($bySerial['G-1'][8])->toBe('2026-09-01')
        ->and($bySerial['G-1'][9])->toBe(today()->toDateString())
        ->and($bySerial['G-1'][10])->toBe('On Track')
        ->and($bySerial['PLAIN-SERIAL'])->toBe([
            'Uncalibrated Gauge',
            'Spare',
            'PLAIN-SERIAL',
            'PLAIN-ASSET',
            '—',
            '—',
            '—',
            '—',
            '—',
            '—',
            '—',
        ])
        ->and($bySerial['NULL-DUE'][8])->toBe('2026-03-03')
        ->and($bySerial['NULL-DUE'][9])->toBe('—')
        ->and($bySerial['NULL-DUE'][10])->toBe('—');
});

it('escapes formulas quotes commas and newlines in the instrument csv', function () {
    createCsvExportTestTables();

    DB::table('instrument')->insert([
        'instrument_id' => 1,
        'instrument_name' => '=1+1',
        'detail_location_id' => null,
        'description' => null,
        'created_at' => '2026-01-01 00:00:00',
    ]);

    DB::table('instrument_type')->insert([
        [
            'instrument_type_id' => 1,
            'instrument_id' => 1,
            'type_name' => "Say \"Hi\",\nnow",
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
            'serial_number' => 'Sr. 28 Nr. 752',
            'asset_number' => '=ID12',
        ],
        [
            'instrument_type_id' => 2,
            'instrument_id' => 1,
            'type_name' => '+plus',
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
            'serial_number' => '@AT',
            'asset_number' => '-minus',
        ],
        [
            'instrument_type_id' => 3,
            'instrument_id' => 1,
            'type_name' => 'Tabbed',
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
            'serial_number' => "\tsecret",
            'asset_number' => "\rstart",
        ],
    ]);

    $response = $this->get(route('instruments.export.csv'));

    $rows = csvExportRows($response->streamedContent());

    expect($rows)->toHaveCount(4)
        ->and($rows[1][0])->toBe("'=1+1")
        ->and($rows[1][1])->toBe("Say \"Hi\",\nnow")
        ->and($rows[1][2])->toBe('Sr. 28 Nr. 752')
        ->and($rows[1][3])->toBe("'=ID12")
        ->and($rows[2][1])->toBe("'+plus")
        ->and($rows[2][2])->toBe("'@AT")
        ->and($rows[2][3])->toBe("'-minus")
        ->and($rows[3][2])->toBe("'\tsecret")
        ->and($rows[3][3])->toBe("'\rstart")
        ->and($rows[1])->toHaveCount(11);
});

it('exports one csv row per calibration and keeps the calibration certificate number', function () {
    createCsvExportTestTables();

    DB::table('instrument')->insert([
        [
            'instrument_id' => 3,
            'instrument_name' => 'Sodiline',
            'detail_location_id' => null,
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
        ],
        [
            'instrument_id' => 4,
            'instrument_name' => 'Digital Caliper',
            'detail_location_id' => null,
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
        ],
    ]);

    DB::table('instrument_type')->insert([
        [
            'instrument_type_id' => 10,
            'instrument_id' => 3,
            'type_name' => 'Weight',
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
            'serial_number' => 'W-1',
            'asset_number' => 'A-1',
        ],
        [
            'instrument_type_id' => 11,
            'instrument_id' => 3,
            'type_name' => 'Diameter',
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
            'serial_number' => 'D-1',
            'asset_number' => 'A-2',
        ],
    ]);

    insertCsvExportCalibration([
        'calibration_id' => 1,
        'instrument_id' => 3,
        'calibration_number' => 'CAL-OLD',
        'calibration_date' => '2026-01-01',
        'calibration_due' => today()->subDay()->toDateString(),
        'calibration_method' => 'External',
        'calibration_result' => 'Pass',
        'certificate_number' => 'FROM-CALIBRATION',
    ]);
    insertCsvExportCalibration([
        'calibration_id' => 2,
        'instrument_id' => 3,
        'calibration_number' => 'CAL-SAME-DATE',
        'calibration_date' => '2026-08-01',
        'calibration_due' => null,
        'calibration_method' => 'Internal',
        'calibration_result' => null,
        'certificate_number' => null,
    ]);
    insertCsvExportCalibration([
        'calibration_id' => 3,
        'instrument_id' => 4,
        'calibration_number' => '=CAL-NEW',
        'calibration_date' => '2026-08-01',
        'calibration_due' => today()->toDateString(),
        'calibration_method' => '=CMD()',
        'calibration_result' => '+ok',
        'certificate_number' => 'CERT, "A"',
    ]);

    DB::table('certificate')->insert([
        'certificate_id' => 1,
        'calibration_id' => 1,
        'certificate_number' => 'FROM-CERTIFICATE-TABLE',
        'issued_date' => '2026-01-02',
    ]);

    $response = $this->get(route('calibrations.export.csv'));

    $response->assertSuccessful();
    $response->assertDownload('calibration-history.csv');

    $rows = csvExportRows($response->streamedContent());

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($rows[0])->toBe([
            'Calibration Number',
            'Instrument Name',
            'Calibration Date',
            'Calibration Due',
            'Status',
            'Calibration Method',
            'Calibration Result',
            'Certificate Number',
        ])
        ->and($rows)->toHaveCount(4)
        ->and($rows[1])->toBe([
            "'=CAL-NEW",
            'Digital Caliper',
            '2026-08-01',
            today()->toDateString(),
            'On Track',
            "'=CMD()",
            "'+ok",
            'CERT, "A"',
        ])
        ->and($rows[2])->toBe([
            'CAL-SAME-DATE',
            'Sodiline',
            '2026-08-01',
            '—',
            '—',
            'Internal',
            '—',
            '—',
        ])
        ->and($rows[3])->toBe([
            'CAL-OLD',
            'Sodiline',
            '2026-01-01',
            today()->subDay()->toDateString(),
            'Overdue',
            'External',
            'Pass',
            'FROM-CALIBRATION',
        ])
        ->and($response->streamedContent())->not->toContain('FROM-CERTIFICATE-TABLE');
});

it('neutralizes a leading line feed without changing stored identifiers', function () {
    createCsvExportTestTables();

    DB::table('instrument')->insert([
        'instrument_id' => 1,
        'instrument_name' => "\n=1+1",
        'detail_location_id' => null,
        'description' => null,
        'created_at' => '2026-01-01 00:00:00',
    ]);

    DB::table('instrument_type')->insert([
        [
            'instrument_type_id' => 1,
            'instrument_id' => 1,
            'type_name' => "Say \"Hi\",\r\nnow",
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
            'serial_number' => 'Sr. 28 Nr. 752',
            'asset_number' => 'ID12-1123100257-0',
        ],
        [
            'instrument_type_id' => 2,
            'instrument_id' => 1,
            'type_name' => 'Spare',
            'description' => null,
            'created_at' => '2026-01-01 00:00:00',
            'serial_number' => '-Sr. 28 Nr. 752',
            'asset_number' => null,
        ],
    ]);

    insertCsvExportCalibration([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'calibration_number' => 'CAL-KEEP',
        'calibration_date' => '2026-09-01',
        'calibration_due' => today()->toDateString(),
        'calibration_result' => null,
        'certificate_number' => null,
    ]);

    $instruments = $this->get(route('instruments.export.csv'));
    $history = $this->get(route('calibrations.export.csv'));

    $instrumentRows = csvExportRows($instruments->streamedContent());
    $historyRows = csvExportRows($history->streamedContent());

    expect($instrumentRows[1])->toBe([
        "'\n=1+1",
        "Say \"Hi\",\r\nnow",
        'Sr. 28 Nr. 752',
        'ID12-1123100257-0',
        '—',
        '—',
        '—',
        '—',
        '2026-09-01',
        today()->toDateString(),
        'On Track',
    ])
        ->and($instrumentRows[2][2])->toBe("'-Sr. 28 Nr. 752")
        ->and($instrumentRows[2][3])->toBe('—')
        ->and($instrumentRows[2][8])->toBe('2026-09-01')
        ->and($instrumentRows[2][10])->toBe('On Track')
        ->and($historyRows[1][0])->toBe('CAL-KEEP')
        ->and($historyRows[1][2])->toBe('2026-09-01')
        ->and($historyRows[1][4])->toBe('On Track')
        ->and($historyRows[1][6])->toBe('—')
        ->and($historyRows[1][7])->toBe('—');

    $this->assertDatabaseHas('instrument_type', [
        'instrument_type_id' => 1,
        'serial_number' => 'Sr. 28 Nr. 752',
        'asset_number' => 'ID12-1123100257-0',
    ]);
    $this->assertDatabaseHas('instrument_type', [
        'instrument_type_id' => 2,
        'serial_number' => '-Sr. 28 Nr. 752',
        'asset_number' => null,
    ]);
    $this->assertDatabaseHas('calibration', [
        'calibration_id' => 1,
        'calibration_number' => 'CAL-KEEP',
    ]);
});

it('keeps the instrument list available with export links', function () {
    createCsvExportTestTables();

    $response = $this->get(route('instruments.index'));

    $response->assertSuccessful();
    $response->assertSee('href="'.route('instruments.export.csv').'"', false);
    $response->assertSee('href="'.route('calibrations.export.csv').'"', false);
});
