<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createCertificatePreviewTestTables(): void
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

    Schema::create('calibration_reading', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_reading_id')->primary();
        $table->unsignedBigInteger('calibration_test_result_id');
        $table->integer('reading_order');
        $table->decimal('reference_value', 18, 6)->nullable();
        $table->decimal('instrument_value', 18, 6)->nullable();
        $table->decimal('error_value', 18, 6)->nullable();
        $table->decimal('uncertainty_value', 18, 6)->nullable();
        $table->string('unit', 50)->nullable();
        $table->timestamp('created_at');
    });
}

it('renders basic certificate and related calibration information', function () {
    createCertificatePreviewTestTables();

    DB::table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => 2,
        'description' => 'Prototype instrument',
        'created_at' => '2026-10-07 09:17:33',
    ]);

    DB::table('calibration')->insert([
        'calibration_id' => 3,
        'instrument_id' => 3,
        'calibration_provider_id' => 1,
        'calibration_number' => 'CAL-PROT-0001',
        'calibration_date' => '2026-06-12',
        'calibration_due' => '2027-06-12',
        'calibration_method' => 'EXTERNAL',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-PROT-0001',
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
        'updated_at' => '2026-06-12 09:00:00',
        'action_date' => null,
        'resume' => null,
        'customer_id' => 1,
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

    $response = $this->get(route('certificates.show', 1));

    $response
        ->assertOk()
        ->assertSee('Certificate Preview')
        ->assertSee('Certificate Information')
        ->assertSee('CERT-PROT-0001')
        ->assertSee('EXTERNAL')
        ->assertSee('12 June 2026')
        ->assertSee('Calibration Information')
        ->assertSee('CAL-PROT-0001')
        ->assertSee('12 June 2027')
        ->assertSee('PASS')
        ->assertSee('No environment data found.')
        ->assertSee('No activities found.')
        ->assertSee('No standards used.')
        ->assertSee('No test results found.')
        ->assertSeeInOrder([
            'Test Result',
            'Resume',
            '—',
        ]);
});

it('renders a dash when calibration result is missing', function () {
    createCertificatePreviewTestTables();

    DB::table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => 2,
        'description' => 'Prototype instrument',
        'created_at' => '2026-10-07 09:17:33',
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
        'certificate_number' => 'CERT-PROT-0001',
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
        'updated_at' => '2026-06-12 09:00:00',
        'action_date' => null,
        'resume' => null,
        'customer_id' => 1,
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

    $response = $this->get(route('certificates.show', 1));

    $response
        ->assertOk()
        ->assertSee('Calibration Result')
        ->assertSee('—');
});

it('renders customer and scoped instrument identification without duplicate instruments', function () {
    createCertificatePreviewTestTables();

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
        'detail_location_code' => 'FM 05/06',
        'detail_location_name' => 'Floor Mix 05/06',
        'description' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);

    DB::table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => 2,
        'description' => 'Prototype instrument',
        'created_at' => '2026-10-07 09:17:33',
    ]);

    DB::table('instrument_type')->insert([
        [
            'instrument_type_id' => 5,
            'instrument_id' => 3,
            'type_name' => 'Weight',
            'created_at' => '2026-06-12 09:00:00',
            'asset_number' => 'ID12-1123100257-0',
            'serial_number' => 'Sr. 28 Nr. 752',
            'brand' => null,
            'model' => null,
        ],
        [
            'instrument_type_id' => 6,
            'instrument_id' => 3,
            'type_name' => 'Diameter',
            'created_at' => '2026-06-12 09:00:00',
            'asset_number' => null,
            'serial_number' => 'Sr. 19 Nr. 528',
            'brand' => null,
            'model' => null,
        ],
        [
            'instrument_type_id' => 7,
            'instrument_id' => 3,
            'type_name' => 'PD',
            'created_at' => '2026-06-12 09:00:00',
            'asset_number' => null,
            'serial_number' => 'Sr. 24 Nr. 745',
            'brand' => 'Sodim',
            'model' => 'PDV - PD',
        ],
        [
            'instrument_type_id' => 99,
            'instrument_id' => 3,
            'type_name' => 'Unused Type',
            'created_at' => '2026-06-12 09:00:00',
            'asset_number' => 'SHOULD-NOT-APPEAR',
            'serial_number' => 'UNUSED-SERIAL',
            'brand' => null,
            'model' => null,
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
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-PROT-0001',
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
        'updated_at' => '2026-06-12 09:00:00',
        'action_date' => null,
        'resume' => null,
        'customer_id' => 1,
    ]);

    DB::table('calibration_scope')->insert([
        [
            'calibration_scope_id' => 203,
            'calibration_id' => 3,
            'instrument_type_id' => 7,
            'test_profile_id' => null,
            'scope_order' => 3,
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'calibration_scope_id' => 201,
            'calibration_id' => 3,
            'instrument_type_id' => 5,
            'test_profile_id' => null,
            'scope_order' => 1,
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'calibration_scope_id' => 202,
            'calibration_id' => 3,
            'instrument_type_id' => 6,
            'test_profile_id' => null,
            'scope_order' => 2,
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
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

    $response = $this->get(route('certificates.show', 1));

    $response
        ->assertOk()
        ->assertSee('Certificate Preview')
        ->assertSee('Customer')
        ->assertSee('PT. BENTOEL PRIMA')
        ->assertSee('Jl. Raya Perusahaan, Karanglo, Singosari, Malang')
        ->assertSee('Instrument Identification')
        ->assertSee('Sodiline')
        ->assertSee('FMD / FM 05/06')
        ->assertSee('Sr. 28 Nr. 752')
        ->assertSee('ID12-1123100257-0')
        ->assertSee('Sr. 19 Nr. 528')
        ->assertSee('Sodim')
        ->assertSee('PDV - PD')
        ->assertSeeInOrder([
            'Weight',
            'Diameter',
            'PD',
        ])
        ->assertDontSee('Unused Type')
        ->assertDontSee('UNUSED-SERIAL')
        ->assertDontSee('SHOULD-NOT-APPEAR')
        ->assertSee('No test results found.');
});

it('renders environment, ordered activities, and ordered standard usages', function () {
    createCertificatePreviewTestTables();

    DB::table('instrument')->insert([
        [
            'instrument_id' => 3,
            'instrument_name' => 'Sodiline',
            'detail_location_id' => 2,
            'description' => 'Prototype instrument',
            'created_at' => '2026-10-07 09:17:33',
        ],
        [
            'instrument_id' => 6,
            'instrument_name' => 'Pressure Drop Standard A',
            'detail_location_id' => null,
            'description' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'instrument_id' => 7,
            'instrument_name' => 'Pressure Drop Standard B',
            'detail_location_id' => null,
            'description' => null,
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
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-PROT-0001',
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
        'updated_at' => '2026-06-12 09:00:00',
        'action_date' => null,
        'resume' => null,
        'customer_id' => 1,
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

    DB::table('calibration_environment')->insert([
        'calibration_environment_id' => 1,
        'calibration_id' => 3,
        'temperature_value' => '25.000',
        'temperature_unit' => '°C',
        'humidity_value' => '65.000',
        'humidity_unit' => '%',
        'pressure_value' => null,
        'pressure_unit' => null,
        'remarks' => 'Prototype environment data',
        'created_at' => '2026-06-12 09:00:00',
    ]);

    DB::table('calibration_activity')->insert([
        [
            'calibration_activity_id' => 13,
            'calibration_id' => 3,
            'activity_type' => 'Verification',
            'activity_description' => 'Verification',
            'activity_result' => null,
            'activity_order' => 3,
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
        [
            'calibration_activity_id' => 12,
            'calibration_id' => 3,
            'activity_type' => 'Testing',
            'activity_description' => 'Leakage test',
            'activity_result' => null,
            'activity_order' => 2,
            'created_at' => '2026-06-12 09:00:00',
        ],
    ]);

    DB::table('calibration_standard_usage')->insert([
        [
            'calibration_standard_usage_id' => 22,
            'calibration_id' => 3,
            'standard_instrument_id' => 7,
            'usage_order' => 2,
            'usage_purpose' => 'Pressure Drop',
            'remarks' => 'Second standard remark',
            'created_at' => '2026-06-12 09:00:00',
            'standard_range' => '198.1',
            'standard_unit' => 'mmWG',
        ],
        [
            'calibration_standard_usage_id' => 21,
            'calibration_id' => 3,
            'standard_instrument_id' => 6,
            'usage_order' => 1,
            'usage_purpose' => 'Pressure Drop',
            'remarks' => 'First standard remark',
            'created_at' => '2026-06-12 09:00:00',
            'standard_range' => '99.0',
            'standard_unit' => 'mmWG',
        ],
    ]);

    Model::preventLazyLoading();

    $response = $this->get(route('certificates.show', 1));

    $response
        ->assertOk()
        ->assertSee('Environment')
        ->assertSee('Prototype environment data')
        ->assertSee('25')
        ->assertSee('°C')
        ->assertSee('65')
        ->assertSee('%')
        ->assertDontSee('No environment data found.')
        ->assertSee('Activity')
        ->assertSeeInOrder([
            'Cleaning All Module',
            'Leakage test',
            'Verification',
        ])
        ->assertDontSee('No activities found.')
        ->assertSee('Standard(s) Used')
        ->assertSeeInOrder([
            'Pressure Drop Standard A',
            '99.0',
            'First standard remark',
            'Pressure Drop Standard B',
            '198.1',
            'Second standard remark',
        ])
        ->assertSee('mmWG')
        ->assertDontSee('No standards used.');
});

it('renders scoped test results and ordered readings', function () {
    createCertificatePreviewTestTables();

    DB::table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => 2,
        'description' => 'Prototype instrument',
        'created_at' => '2026-10-07 09:17:33',
    ]);

    DB::table('instrument_type')->insert([
        [
            'instrument_type_id' => 5,
            'instrument_id' => 3,
            'type_name' => 'Weight',
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'instrument_type_id' => 6,
            'instrument_id' => 3,
            'type_name' => 'Diameter',
            'created_at' => '2026-06-12 09:00:00',
        ],
    ]);

    DB::table('test_profile')->insert([
        [
            'test_profile_id' => 2,
            'instrument_type_id' => 5,
            'profile_name' => 'Weight Calibration',
            'description' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'test_profile_id' => 3,
            'instrument_type_id' => 6,
            'profile_name' => 'Diameter Calibration',
            'description' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
    ]);

    DB::table('test_point')->insert([
        [
            'test_point_id' => 302,
            'test_profile_id' => 2,
            'point_order' => 2,
            'nominal_value' => '500.250000',
            'unit' => 'g',
            'tolerance_plus' => null,
            'tolerance_minus' => null,
            'description' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'test_point_id' => 301,
            'test_profile_id' => 2,
            'point_order' => 1,
            'nominal_value' => '100.125000',
            'unit' => 'g',
            'tolerance_plus' => null,
            'tolerance_minus' => null,
            'description' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'test_point_id' => 304,
            'test_profile_id' => 3,
            'point_order' => 1,
            'nominal_value' => '10.500000',
            'unit' => 'mm',
            'tolerance_plus' => null,
            'tolerance_minus' => null,
            'description' => null,
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
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-PROT-0001',
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
        'updated_at' => '2026-06-12 09:00:00',
        'action_date' => null,
        'resume' => null,
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
            'instrument_type_id' => 6,
            'test_profile_id' => 3,
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
            'result_status' => 'FAIL',
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
            'standard_value' => '500.250000',
            'average_value' => '500.200000',
            'correction_value' => '0.200000',
        ],
        [
            'calibration_test_result_id' => 901,
            'calibration_scope_id' => 202,
            'test_point_id' => 301,
            'result_status' => 'PASS',
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
            'standard_value' => '100.125000',
            'average_value' => '100.250000',
            'correction_value' => '0.125000',
        ],
        [
            'calibration_test_result_id' => 903,
            'calibration_scope_id' => 201,
            'test_point_id' => 304,
            'result_status' => 'PASS',
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
            'standard_value' => '10.500000',
            'average_value' => '10.510000',
            'correction_value' => '0.010000',
        ],
    ]);

    DB::table('calibration_reading')->insert([
        [
            'calibration_reading_id' => 802,
            'calibration_test_result_id' => 901,
            'reading_order' => 2,
            'reference_value' => '100.000000',
            'instrument_value' => '100.030000',
            'error_value' => '0.030000',
            'uncertainty_value' => null,
            'unit' => 'g',
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'calibration_reading_id' => 801,
            'calibration_test_result_id' => 901,
            'reading_order' => 1,
            'reference_value' => '111.111000',
            'instrument_value' => '111.222000',
            'error_value' => '0.111000',
            'uncertainty_value' => '0.009000',
            'unit' => 'g',
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'calibration_reading_id' => 803,
            'calibration_test_result_id' => 903,
            'reading_order' => 1,
            'reference_value' => '10.000000',
            'instrument_value' => '10.010000',
            'error_value' => '0.010000',
            'uncertainty_value' => '0.001000',
            'unit' => 'mm',
            'created_at' => '2026-06-12 09:00:00',
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

    $response = $this->get(route('certificates.show', 1));

    $response
        ->assertOk()
        ->assertSee('Test Result')
        ->assertSee('Test Point / Nominal')
        ->assertSee('Standard Value')
        ->assertSee('Average Value')
        ->assertSee('Reading No.')
        ->assertSee('FAIL')
        ->assertSee('No readings found.')
        ->assertSeeInOrder([
            'Diameter Calibration',
            'Weight Calibration',
        ])
        ->assertSeeInOrder([
            '10.5',
            '100.125',
            '500.25',
        ])
        ->assertSee('100.25')
        ->assertSee('0.125')
        ->assertSee('500.2')
        ->assertSee('0.2')
        ->assertSee('10.51')
        ->assertSee('0.01')
        ->assertSee('111.111')
        ->assertSee('111.222')
        ->assertSee('0.111')
        ->assertSee('0.009')
        ->assertSee('100.03')
        ->assertSee('0.03')
        ->assertSeeInOrder([
            '111.111',
            '111.222',
            '0.111',
            '0.009',
            '100.03',
            '0.03',
        ])
        ->assertDontSee('No test results found.');
});

it('renders stored calibration resume and ignores calibration remarks', function () {
    createCertificatePreviewTestTables();

    DB::table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => 2,
        'description' => 'Prototype instrument',
        'created_at' => '2026-10-07 09:17:33',
    ]);

    DB::table('calibration')->insert([
        'calibration_id' => 3,
        'instrument_id' => 3,
        'calibration_provider_id' => 1,
        'calibration_number' => 'CAL-PROT-0001',
        'calibration_date' => '2026-06-12',
        'calibration_due' => '2027-06-12',
        'calibration_method' => 'EXTERNAL',
        'calibration_result' => 'PASS',
        'certificate_number' => 'CERT-PROT-0001',
        'remarks' => 'Data sementara untuk prototype',
        'created_at' => '2026-06-12 09:00:00',
        'updated_at' => '2026-06-12 09:00:00',
        'action_date' => null,
        'resume' => 'Instrument working properly',
        'customer_id' => 1,
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

    $response = $this->get(route('certificates.show', 1));

    $response
        ->assertOk()
        ->assertSeeInOrder([
            'Test Result',
            'Resume',
            'Instrument working properly',
        ])
        ->assertDontSee('Data sementara untuk prototype');
});

it('returns not found for an unknown certificate', function () {
    createCertificatePreviewTestTables();

    $response = $this->get(route('certificates.show', 999));

    $response->assertNotFound();
});
