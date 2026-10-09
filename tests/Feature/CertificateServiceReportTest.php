<?php

use App\Certificates\ServiceReport;
use App\Models\Certificate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createServiceReportTestTables(): void
{
    Schema::create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name', 150);
        $table->unsignedBigInteger('detail_location_id')->nullable();
        $table->text('description')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('calibration', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->unsignedBigInteger('calibration_provider_id');
        $table->string('calibration_number', 100);
        $table->date('calibration_date')->nullable();
        $table->date('calibration_due')->nullable();
        $table->string('calibration_method', 20);
        $table->string('calibration_result', 20)->nullable();
        $table->string('certificate_number', 100)->nullable();
        $table->text('remarks')->nullable();
        $table->timestamps();
        $table->date('action_date')->nullable();
        $table->text('resume')->nullable();
        $table->unsignedBigInteger('customer_id')->nullable();
    });

    Schema::create('certificate', function (Blueprint $table): void {
        $table->unsignedBigInteger('certificate_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->string('certificate_number', 100);
        $table->string('certificate_type', 20);
        $table->date('issued_date');
        $table->text('file_path')->nullable();
        $table->string('file_name', 255)->nullable();
        $table->text('remarks')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('customer', function (Blueprint $table): void {
        $table->unsignedBigInteger('customer_id')->primary();
        $table->string('customer_name');
        $table->text('address')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('location', function (Blueprint $table): void {
        $table->unsignedBigInteger('location_id')->primary();
        $table->string('location_code', 50);
        $table->string('location_name', 100);
        $table->text('description')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('detail_location', function (Blueprint $table): void {
        $table->unsignedBigInteger('detail_location_id')->primary();
        $table->unsignedBigInteger('location_id');
        $table->string('detail_location_code', 50);
        $table->string('detail_location_name', 100);
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
        $table->decimal('max_capacity')->nullable();
        $table->string('max_capacity_unit')->nullable();
        $table->decimal('resolution')->nullable();
        $table->string('resolution_unit')->nullable();
        $table->string('brand')->nullable();
        $table->string('model')->nullable();
    });

    Schema::create('calibration_scope', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_scope_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('instrument_type_id');
        $table->unsignedBigInteger('test_profile_id')->nullable();
        $table->integer('scope_order');
        $table->text('remarks')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('calibration_environment', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_environment_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->decimal('temperature_value', 10, 3)->nullable();
        $table->string('temperature_unit', 20)->nullable();
        $table->decimal('humidity_value', 10, 3)->nullable();
        $table->string('humidity_unit', 20)->nullable();
        $table->decimal('pressure_value', 12, 3)->nullable();
        $table->string('pressure_unit', 20)->nullable();
        $table->text('remarks')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('calibration_activity', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_activity_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->string('activity_type', 100);
        $table->text('activity_description')->nullable();
        $table->string('activity_result', 100)->nullable();
        $table->integer('activity_order');
        $table->timestamp('created_at');
    });

    Schema::create('calibration_standard_usage', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_standard_usage_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('standard_instrument_id');
        $table->integer('usage_order');
        $table->string('usage_purpose', 150)->nullable();
        $table->text('remarks')->nullable();
        $table->timestamp('created_at');
        $table->string('standard_range')->nullable();
        $table->string('standard_unit')->nullable();
    });

    Schema::create('test_profile', function (Blueprint $table): void {
        $table->unsignedBigInteger('test_profile_id')->primary();
        $table->unsignedBigInteger('instrument_type_id');
        $table->string('profile_name', 150);
        $table->text('description')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('test_point', function (Blueprint $table): void {
        $table->unsignedBigInteger('test_point_id')->primary();
        $table->unsignedBigInteger('test_profile_id');
        $table->integer('point_order');
        $table->decimal('nominal_value', 18, 6)->nullable();
        $table->string('unit', 50)->nullable();
        $table->decimal('tolerance_plus', 18, 6)->nullable();
        $table->decimal('tolerance_minus', 18, 6)->nullable();
        $table->text('description')->nullable();
        $table->timestamp('created_at');
    });

    Schema::create('calibration_test_result', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_test_result_id')->primary();
        $table->unsignedBigInteger('calibration_scope_id');
        $table->unsignedBigInteger('test_point_id');
        $table->string('result_status', 20)->nullable();
        $table->text('remarks')->nullable();
        $table->timestamp('created_at');
        $table->decimal('standard_value', 18, 6)->nullable();
        $table->decimal('average_value', 18, 6)->nullable();
        $table->decimal('correction_value', 18, 6)->nullable();
    });
}

it('renders a one-page service report pdf from stored certificate data', function () {
    createServiceReportTestTables();

    DB::table('customer')->insert([
        'customer_id' => 1,
        'customer_name' => 'PT. BENTOEL PRIMA',
        'address' => 'Jl. Raya Perusahaan, Karanglo, Singosari, Malang',
        'created_at' => '2026-06-12 09:00:00',
    ]);

    DB::table('location')->insert([
        'location_id' => 1,
        'location_code' => 'FMD',
        'location_name' => 'FMD Factory',
        'description' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);

    DB::table('detail_location')->insert([
        'detail_location_id' => 2,
        'location_id' => 1,
        'detail_location_code' => 'FM05-06',
        'detail_location_name' => 'Floor Mix',
        'description' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);

    DB::table('instrument')->insert([
        [
            'instrument_id' => 3,
            'instrument_name' => 'Sodiline',
            'detail_location_id' => 2,
            'description' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'instrument_id' => 6,
            'instrument_name' => 'Pressure Drop Standard',
            'detail_location_id' => null,
            'description' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'instrument_id' => 8,
            'instrument_name' => 'Ambiguous Standard',
            'detail_location_id' => null,
            'description' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
    ]);

    DB::table('instrument_type')->insert([
        [
            'instrument_type_id' => 5,
            'instrument_id' => 3,
            'type_name' => 'Weight',
            'created_at' => '2026-06-12 09:00:00',
            'serial_number' => 'Sr. 28 Nr. 752',
            'brand' => null,
            'model' => null,
            'max_capacity' => '1000',
            'max_capacity_unit' => 'g',
            'resolution' => '0.01',
            'resolution_unit' => 'g',
        ],
        [
            'instrument_type_id' => 7,
            'instrument_id' => 3,
            'type_name' => 'PD',
            'created_at' => '2026-06-12 09:00:00',
            'serial_number' => 'Sr. 24 Nr. 745',
            'brand' => 'Sodim',
            'model' => 'PDV - PD',
            'max_capacity' => null,
            'max_capacity_unit' => null,
            'resolution' => null,
            'resolution_unit' => null,
        ],
        [
            'instrument_type_id' => 99,
            'instrument_id' => 3,
            'type_name' => 'Unused Type',
            'created_at' => '2026-06-12 09:00:00',
            'serial_number' => 'UNUSED-SERIAL',
            'brand' => null,
            'model' => null,
            'max_capacity' => null,
            'max_capacity_unit' => null,
            'resolution' => null,
            'resolution_unit' => null,
        ],
        [
            'instrument_type_id' => 11,
            'instrument_id' => 6,
            'type_name' => 'Pressure Drop Standard',
            'created_at' => '2026-06-12 09:00:00',
            'serial_number' => 'ET4276',
            'brand' => null,
            'model' => null,
            'max_capacity' => null,
            'max_capacity_unit' => null,
            'resolution' => null,
            'resolution_unit' => null,
        ],
        [
            'instrument_type_id' => 21,
            'instrument_id' => 8,
            'type_name' => 'Ambiguous A',
            'created_at' => '2026-06-12 09:00:00',
            'serial_number' => 'AMBIG-1',
            'brand' => null,
            'model' => null,
            'max_capacity' => null,
            'max_capacity_unit' => null,
            'resolution' => null,
            'resolution_unit' => null,
        ],
        [
            'instrument_type_id' => 22,
            'instrument_id' => 8,
            'type_name' => 'Ambiguous B',
            'created_at' => '2026-06-12 09:00:00',
            'serial_number' => 'AMBIG-2',
            'brand' => null,
            'model' => null,
            'max_capacity' => null,
            'max_capacity_unit' => null,
            'resolution' => null,
            'resolution_unit' => null,
        ],
    ]);

    DB::table('test_profile')->insert([
        [
            'test_profile_id' => 2,
            'instrument_type_id' => 5,
            'profile_name' => 'Weight Calibration',
            'description' => 'Prototype profile note',
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'test_profile_id' => 4,
            'instrument_type_id' => 7,
            'profile_name' => 'Pressure Drop Calibration',
            'description' => 'Prototype profile note',
            'created_at' => '2026-06-12 09:00:00',
        ],
    ]);

    DB::table('test_point')->insert([
        [
            'test_point_id' => 302,
            'test_profile_id' => 2,
            'point_order' => 2,
            'nominal_value' => '500',
            'unit' => 'g',
            'tolerance_plus' => '0.25',
            'tolerance_minus' => '-0.25',
            'description' => 'Prototype test point 2',
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'test_point_id' => 301,
            'test_profile_id' => 2,
            'point_order' => 1,
            'nominal_value' => '100',
            'unit' => 'g',
            'tolerance_plus' => '0.25',
            'tolerance_minus' => '-0.25',
            'description' => 'Prototype test point 1',
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'test_point_id' => 401,
            'test_profile_id' => 4,
            'point_order' => 1,
            'nominal_value' => '99',
            'unit' => 'mmWG',
            'tolerance_plus' => null,
            'tolerance_minus' => null,
            'description' => 'Correction tolerance ±3% from standard value',
            'created_at' => '2026-06-12 09:00:00',
        ],
    ]);

    DB::table('calibration')->insert([
        'calibration_id' => 3,
        'instrument_id' => 3,
        'calibration_provider_id' => 1,
        'calibration_number' => 'CAL-PROT-0001',
        'calibration_date' => '2026-06-12',
        'calibration_due' => '2027-06-12',
        'calibration_method' => 'EXTERNAL',
        'calibration_result' => null,
        'certificate_number' => 'NOT-THE-DOCUMENT-NUMBER',
        'remarks' => 'Data sementara untuk prototype',
        'created_at' => '2026-06-12 09:00:00',
        'updated_at' => '2026-06-12 09:00:00',
        'action_date' => null,
        'resume' => 'Instrument working properly',
        'customer_id' => 1,
    ]);

    DB::table('calibration_scope')->insert([
        [
            'calibration_scope_id' => 202,
            'calibration_id' => 3,
            'instrument_type_id' => 5,
            'test_profile_id' => 2,
            'scope_order' => 2,
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'calibration_scope_id' => 201,
            'calibration_id' => 3,
            'instrument_type_id' => 7,
            'test_profile_id' => 4,
            'scope_order' => 1,
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
    ]);

    DB::table('calibration_test_result')->insert([
        [
            'calibration_test_result_id' => 902,
            'calibration_scope_id' => 202,
            'test_point_id' => 302,
            'result_status' => 'PASS',
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
            'standard_value' => '500.00',
            'average_value' => '500.03',
            'correction_value' => '0.03',
        ],
        [
            'calibration_test_result_id' => 901,
            'calibration_scope_id' => 202,
            'test_point_id' => 301,
            'result_status' => 'PASS',
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
            'standard_value' => '100.00',
            'average_value' => '100.02',
            'correction_value' => '0.02',
        ],
        [
            'calibration_test_result_id' => 903,
            'calibration_scope_id' => 201,
            'test_point_id' => 401,
            'result_status' => 'PASS',
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
            'standard_value' => '99.0',
            'average_value' => '99.8',
            'correction_value' => '-0.8',
        ],
    ]);

    DB::table('calibration_environment')->insert([
        'calibration_environment_id' => 1,
        'calibration_id' => 3,
        'temperature_value' => '25.000',
        'temperature_unit' => '°C',
        'humidity_value' => '65.000',
        'humidity_unit' => '%',
        'pressure_value' => null,
        'pressure_unit' => null,
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);

    DB::table('calibration_activity')->insert([
        [
            'calibration_activity_id' => 12,
            'calibration_id' => 3,
            'activity_type' => 'Testing',
            'activity_description' => 'Leakage test',
            'activity_result' => null,
            'activity_order' => 2,
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'calibration_activity_id' => 11,
            'calibration_id' => 3,
            'activity_type' => 'Cleaning',
            'activity_description' => 'Cleaning All Module',
            'activity_result' => null,
            'activity_order' => 1,
            'created_at' => '2026-06-12 09:00:00',
        ],
    ]);

    DB::table('calibration_standard_usage')->insert([
        [
            'calibration_standard_usage_id' => 22,
            'calibration_id' => 3,
            'standard_instrument_id' => 6,
            'usage_order' => 2,
            'usage_purpose' => 'Pressure Drop',
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
            'standard_range' => '99.0',
            'standard_unit' => 'mmWG',
        ],
        [
            'calibration_standard_usage_id' => 21,
            'calibration_id' => 3,
            'standard_instrument_id' => 8,
            'usage_order' => 1,
            'usage_purpose' => 'Pressure Drop',
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
            'standard_range' => '10',
            'standard_unit' => 'mmWG',
        ],
    ]);

    DB::table('certificate')->insert([
        'certificate_id' => 1,
        'calibration_id' => 3,
        'certificate_number' => 'CERT-PROT-0001',
        'certificate_type' => 'EXTERNAL',
        'issued_date' => '2026-06-12',
        'file_path' => null,
        'file_name' => null,
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);

    Model::preventLazyLoading();

    $html = view('certificates.service-report', [
        'report' => ServiceReport::from(Certificate::query()->findOrFail(1)),
    ])->render();

    expect($html)
        ->toContain('SERVICE REPORT')
        ->toContain('Document Number: CERT-PROT-0001')
        ->toContain('Page 1 of 1')
        ->toContain('GENERAL INFORMATION')
        ->toContain('PT. BENTOEL PRIMA')
        ->toContain('Jl. Raya Perusahaan, Karanglo, Singosari, Malang')
        ->toContain('12 June 2026')
        ->toContain('INSTRUMENT IDENTIFICATION')
        ->toContain('Sodiline')
        ->toContain('FMD / FM05-06')
        ->toContain('Sr. 28 Nr. 752')
        ->toContain('Sodim')
        ->toContain('PDV - PD')
        ->toContain('Sr. 24 Nr. 745')
        ->toContain('1000 g')
        ->toContain('0.01 g')
        ->toContain('ENVIRONMENT')
        ->toContain('25 °C')
        ->toContain('65 %')
        ->toContain('ACTIVITY')
        ->toContain('STANDARD(S) USED')
        ->toContain('ET4276')
        ->toContain('99.0 mmWG')
        ->toContain('TEST RESULT')
        ->toContain('Standard Reading Avg')
        ->toContain('Correction Tolerance: ±3% from standard value')
        ->toContain('Correction Tolerance: 0.25 / -0.25 g')
        ->toContain('RESUME')
        ->toContain('Instrument working properly')
        ->toContain('Authorized Signatory')
        ->not->toContain('NOT-THE-DOCUMENT-NUMBER')
        ->not->toContain('Data sementara untuk prototype')
        ->not->toContain('UNUSED-SERIAL')
        ->not->toContain('Unused Type')
        ->not->toContain('AMBIG-1')
        ->not->toContain('AMBIG-2')
        ->not->toContain('Reference Value')
        ->and($html)->toMatch('/PD[\\s\\S]+Weight/');

    expect($html)->toMatch('/Cleaning All Module[\\s\\S]+Leakage test/');
    expect($html)->toMatch('/Ambiguous Standard[\\s\\S]+Pressure Drop Standard/');
    expect($html)->toMatch('/99\\.8[\\s\\S]+100[\\s\\S]+500/');

    $response = $this->get(route('certificates.pdf', 1));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('inline')
        ->and(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

it('renders a dash when the certificate number and resume are empty', function () {
    createServiceReportTestTables();

    DB::table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => null,
        'description' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);

    DB::table('calibration')->insert([
        'calibration_id' => 3,
        'instrument_id' => 3,
        'calibration_provider_id' => 1,
        'calibration_number' => 'CAL-PROT-0001',
        'calibration_date' => '2026-06-12',
        'calibration_due' => null,
        'calibration_method' => 'EXTERNAL',
        'calibration_result' => null,
        'certificate_number' => 'NOT-THE-DOCUMENT-NUMBER',
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
        'updated_at' => '2026-06-12 09:00:00',
        'action_date' => null,
        'resume' => null,
        'customer_id' => null,
    ]);

    DB::table('certificate')->insert([
        'certificate_id' => 1,
        'calibration_id' => 3,
        'certificate_number' => '',
        'certificate_type' => 'EXTERNAL',
        'issued_date' => '2026-06-12',
        'file_path' => null,
        'file_name' => null,
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);

    Model::preventLazyLoading();

    $html = view('certificates.service-report', [
        'report' => ServiceReport::from(Certificate::query()->findOrFail(1)),
    ])->render();

    expect($html)
        ->toContain('Document Number: —')
        ->toContain('RESUME')
        ->not->toContain('NOT-THE-DOCUMENT-NUMBER');

    $response = $this->get(route('certificates.pdf', 1));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
});

it('returns not found for an unknown certificate pdf', function () {
    createServiceReportTestTables();

    $this->get(route('certificates.pdf', 999))->assertNotFound();
});
