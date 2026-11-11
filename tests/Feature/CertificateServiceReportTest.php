<?php

use App\Certificates\ServiceReport;
use App\Certificates\ServiceReportPdfRenderer;
use App\Models\Certificate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

function createServiceReportTestTables(): void
{
    Schema::create('instrument', function (Blueprint $table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name', 150);
        $table->string('serial_number')->nullable();
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
        addCalibrationSessionColumns($table);
        $table->string('snapshot_instrument_type_name')->nullable();
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
        $table->string('snapshot_standard_code')->nullable();
        $table->string('snapshot_instrument_name')->nullable();
        $table->string('snapshot_type_specification')->nullable();
        $table->string('snapshot_serial_number')->nullable();
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
        $table->string('standard_value', 50)->nullable();
        $table->string('average_value', 50)->nullable();
        $table->string('correction_value', 50)->nullable();
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
            'serial_number' => 'Sr. 28 Nr. 752',
            'detail_location_id' => 2,
            'description' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'instrument_id' => 6,
            'instrument_name' => 'Pressure Drop Standard',
            'serial_number' => 'ET4276',
            'detail_location_id' => null,
            'description' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'instrument_id' => 8,
            'instrument_name' => 'Ambiguous Standard',
            'serial_number' => null,
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
        'resume' => env('SERVICE_REPORT_LONG_RESUME') === '1'
            ? str_repeat('Instrument working properly. Detailed engineer remarks are preserved in full for the long-report preview. ', 12)
            : 'Instrument working properly',
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

    $extraPreviewRows = (int) env('SERVICE_REPORT_PREVIEW_EXTRA_ROWS', 0);

    if ($extraPreviewRows > 0) {
        for ($index = 1; $index <= $extraPreviewRows; $index++) {
            DB::table('test_point')->insert([
                'test_point_id' => 1000 + $index,
                'test_profile_id' => 4,
                'point_order' => 100 + $index,
                'nominal_value' => (string) (100 + $index),
                'unit' => 'mmWG',
                'tolerance_plus' => null,
                'tolerance_minus' => null,
                'description' => 'Preview test point '.$index,
                'created_at' => '2026-06-12 09:00:00',
            ]);
            DB::table('calibration_test_result')->insert([
                'calibration_test_result_id' => 2000 + $index,
                'calibration_scope_id' => 201,
                'test_point_id' => 1000 + $index,
                'result_status' => 'PASS',
                'remarks' => null,
                'created_at' => '2026-06-12 09:00:00',
                'standard_value' => (string) (100 + $index),
                'average_value' => (string) (100 + $index),
                'correction_value' => '0.0',
            ]);
        }
    }

    if (env('SERVICE_REPORT_THREE_COMPONENT_PREVIEW') === '1') {
        DB::table('calibration_scope')->insert([
            'calibration_scope_id' => 203,
            'calibration_id' => 3,
            'instrument_type_id' => 7,
            'test_profile_id' => 4,
            'scope_order' => 3,
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
        ]);

        DB::table('calibration_test_result')->insert([
            'calibration_test_result_id' => 904,
            'calibration_scope_id' => 203,
            'test_point_id' => 401,
            'result_status' => 'PASS',
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
            'standard_value' => '99.0',
            'average_value' => '99.0',
            'correction_value' => '0.0',
        ]);
    }

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
        'certificate_number' => 'SR.VT001/11/26',
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
        ->toContain('SR.VT001/11/26')
        ->not->toContain('Page {PAGE_NUM} of {PAGE_COUNT}')
        ->not->toContain('DESIGN PREVIEW')
        ->not->toContain('Internal Calibration')
        ->toContain('GENERAL INFORMATION')
        ->toContain('PT. BENTOEL PRIMA')
        ->toContain('Jl. Raya Perusahaan, Karanglo, Singosari, Malang')
        ->toContain('12 June 2026')
        ->toContain('INSTRUMENT IDENTIFICATION')
        ->toContain('Sodiline')
        ->toContain('FMD / FM05-06')
        ->toContain('Sr. 28 Nr. 752')
        ->toContain('Sodim')
        ->toContain('ENVIRONMENT')
        ->not->toContain('ENVIRONMENTAL CONDITIONS')
        ->toContain('25 °C')
        ->toContain('65 %')
        ->toContain('ACTIVITY')
        ->toContain('Cleaning')
        ->toContain('Testing')
        ->toContain('STANDARD(S) USED')
        ->toContain('Instrument Name')
        ->toContain('Type')
        ->toContain('Serial Number')
        ->toContain('TEST RESULT')
        ->toContain('Standard Reading Avg')
        ->toContain('Correction Tolerance: ±3% from standard value')
        ->toContain('Correction Tolerance: 0.25 / -0.25 g')
        ->toContain('RESUME')
        ->toContain('Instrument working properly')
        ->not->toContain('Dokumen ini diterbitkan secara digital dan sah oleh Internal PT Vision Teknik.')
        ->not->toContain('Dokumen ini diterbitkan secara digital dan sah oleh Tim QA PT Vision Teknik.')
        ->not->toContain('Authorized Signatory')
        ->not->toContain('NOT-THE-DOCUMENT-NUMBER')
        ->not->toContain('Data sementara untuk prototype')
        ->not->toContain('UNUSED-SERIAL')
        ->not->toContain('Unused Type')
        ->not->toContain('AMBIG-1')
        ->not->toContain('AMBIG-2')
        ->not->toContain('Reference Value')
        ->and($html)->toMatch('/PD[\\s\\S]+Weight/');

    expect($html)->not->toContain('Cleaning All Module')
        ->and($html)->not->toContain('Leakage test');
    expect($html)->toMatch('/Ambiguous Standard[\\s\\S]+Pressure Drop Standard/');
    expect($html)->toMatch('/99\\.8[\\s\\S]+100[\\s\\S]+500/');

    $response = $this->get(route('certificates.pdf', 1));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
    if (filled(env('SERVICE_REPORT_PREVIEW_PATH'))) {
        $preview = app(ServiceReportPdfRenderer::class)
            ->render(Certificate::query()->findOrFail(1))
            ->output(['compress' => 0]);
        file_put_contents((string) env('SERVICE_REPORT_PREVIEW_PATH'), $preview);
    }
    expect($response->headers->get('content-disposition'))->toContain('inline')
        ->and($response->headers->get('content-disposition'))->toContain('SR.VT001-11-26.pdf')
        ->and(substr($response->getContent(), 0, 5))->toBe('%PDF-')
        ->and($response->getContent())->not->toContain('{PAGE_NUM}')
        ->and($response->getContent())->not->toContain('{PAGE_COUNT}');

    $download = $this->get(route('certificates.download', 1));

    $download->assertOk();
    expect($download->headers->get('content-disposition'))->toContain('attachment')
        ->and($download->headers->get('content-disposition'))->toContain('SR.VT001-11-26.pdf');

    Storage::fake('calibration_documents');
    Storage::disk('calibration_documents')->put('issued/certificate.pdf', "%PDF-1.4\nstored\n");
    DB::table('certificate')->where('certificate_id', 1)->update([
        'file_path' => 'issued/certificate.pdf',
        'file_name' => 'SR.VT001/11/26.pdf',
    ]);

    $stored = $this->get(route('certificates.pdf', 1));

    $stored->assertOk();
    expect($stored->headers->get('content-disposition'))->toContain('inline')
        ->and($stored->headers->get('content-disposition'))->toContain('SR.VT001-11-26.pdf');

    $storedDownload = $this->get(route('certificates.download', 1));

    $storedDownload->assertOk();
    expect($storedDownload->headers->get('content-disposition'))->toContain('attachment')
        ->and($storedDownload->headers->get('content-disposition'))->toContain('SR.VT001-11-26.pdf');
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
        ->toContain('SERVICE REPORT')
        ->toContain('12 June 2026')
        ->not->toContain('Document Number')
        ->not->toContain('RESUME')
        ->not->toContain('NOT-THE-DOCUMENT-NUMBER');

    $response = $this->get(route('certificates.pdf', 1));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
});

it('returns not found for an unknown certificate pdf', function () {
    createServiceReportTestTables();

    $this->get(route('certificates.pdf', 999))->assertNotFound();
});

it('renders the golden master fixture as a single A4 page', function () {
    createServiceReportTestTables();

    DB::table('customer')->insert([
        'customer_id' => 1,
        'customer_name' => 'PT. BENTOEL PRIMA',
        'address' => 'Jl. Raya Perusahaan, Karanglo, Singosari, Malang',
        'created_at' => '2026-11-04 09:00:00',
    ]);

    DB::table('location')->insert([
        'location_id' => 1,
        'location_code' => 'FMD',
        'location_name' => 'FMD Factory',
        'description' => null,
        'created_at' => '2026-11-04 09:00:00',
    ]);

    DB::table('detail_location')->insert([
        'detail_location_id' => 2,
        'location_id' => 1,
        'detail_location_code' => 'FM 01/07',
        'detail_location_name' => 'FM 01/07',
        'description' => null,
        'created_at' => '2026-11-04 09:00:00',
    ]);

    DB::table('instrument')->insert([
        [
            'instrument_id' => 3,
            'instrument_name' => 'Cigarette Test Station',
            'serial_number' => 'Sr. 3 Nr. 44',
            'detail_location_id' => 2,
            'description' => null,
            'created_at' => '2026-11-04 09:00:00',
        ],
        [
            'instrument_id' => 6,
            'instrument_name' => 'Pressure Drop Standard',
            'serial_number' => 'ET4276',
            'detail_location_id' => null,
            'description' => null,
            'created_at' => '2026-11-04 09:00:00',
        ],
    ]);

    DB::table('instrument_type')->insert([
        'instrument_type_id' => 7,
        'instrument_id' => 3,
        'type_name' => 'Sodiline PD',
        'created_at' => '2026-11-04 09:00:00',
        'brand' => 'Sodim',
        'model' => null,
    ]);

    DB::table('test_profile')->insert([
        'test_profile_id' => 4,
        'instrument_type_id' => 7,
        'profile_name' => 'Sodiline PD',
        'description' => 'Correction Tolerance ± 3 % from STD Value',
        'created_at' => '2026-11-04 09:00:00',
    ]);

    $points = [
        ['value' => '99.0', 'average' => '99.0', 'correction' => '0.0'],
        ['value' => '198.1', 'average' => '199.0', 'correction' => '-0.9'],
        ['value' => '293.5', 'average' => '294.0', 'correction' => '-0.5'],
        ['value' => '394.9', 'average' => '394.0', 'correction' => '0.9'],
        ['value' => '487.0', 'average' => '485.0', 'correction' => '2.0'],
    ];

    foreach ($points as $index => $point) {
        DB::table('test_point')->insert([
            'test_point_id' => 401 + $index,
            'test_profile_id' => 4,
            'point_order' => $index + 1,
            'nominal_value' => $point['value'],
            'unit' => 'mmWG',
            'tolerance_plus' => null,
            'tolerance_minus' => null,
            'description' => null,
            'created_at' => '2026-11-04 09:00:00',
        ]);
    }

    DB::table('calibration')->insert([
        'calibration_id' => 3,
        'instrument_id' => 3,
        'calibration_provider_id' => 1,
        'calibration_number' => 'CAL-GOLDEN-0001',
        'calibration_date' => '2026-11-04',
        'calibration_due' => '2027-11-04',
        'calibration_method' => 'INTERNAL',
        'calibration_result' => 'PASS',
        'certificate_number' => 'SR.VT001/11/26',
        'remarks' => null,
        'created_at' => '2026-11-04 09:00:00',
        'updated_at' => '2026-11-04 09:00:00',
        'action_date' => null,
        'resume' => 'Instrument working properly',
        'customer_id' => 1,
        'snapshot_instrument_type_name' => 'PD',
    ]);

    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => 201,
        'calibration_id' => 3,
        'instrument_type_id' => 7,
        'test_profile_id' => 4,
        'scope_order' => 1,
        'remarks' => null,
        'created_at' => '2026-11-04 09:00:00',
    ]);

    foreach ($points as $index => $point) {
        DB::table('calibration_test_result')->insert([
            'calibration_test_result_id' => 901 + $index,
            'calibration_scope_id' => 201,
            'test_point_id' => 401 + $index,
            'result_status' => 'PASS',
            'remarks' => null,
            'created_at' => '2026-11-04 09:00:00',
            'standard_value' => $point['value'],
            'average_value' => $point['average'],
            'correction_value' => $point['correction'],
        ]);
    }

    DB::table('calibration_environment')->insert([
        'calibration_environment_id' => 1,
        'calibration_id' => 3,
        'temperature_value' => '24.9',
        'temperature_unit' => '°C',
        'humidity_value' => '64.8',
        'humidity_unit' => '%RH',
        'pressure_value' => null,
        'pressure_unit' => null,
        'remarks' => null,
        'created_at' => '2026-11-04 09:00:00',
    ]);

    foreach (['Cleaning All Module', 'Verification', 'Leakage test', 'Cleaning/replace vacuum filter', 'Cleaning/adjust internal reference'] as $index => $activity) {
        DB::table('calibration_activity')->insert([
            'calibration_activity_id' => 11 + $index,
            'calibration_id' => 3,
            'activity_type' => $activity,
            'activity_description' => null,
            'activity_result' => null,
            'activity_order' => $index + 1,
            'created_at' => '2026-11-04 09:00:00',
        ]);
    }

    $standardSerials = ['ET4276', 'ET4614', 'ET4782', 'ET4260', 'ET4934'];

    foreach (['99.0', '198.1', '293.5', '394.9', '487.0'] as $index => $range) {
        DB::table('calibration_standard_usage')->insert([
            'calibration_standard_usage_id' => 21 + $index,
            'calibration_id' => 3,
            'standard_instrument_id' => 6,
            'usage_order' => $index + 1,
            'usage_purpose' => 'Pressure Drop',
            'remarks' => null,
            'created_at' => '2026-11-04 09:00:00',
            'standard_range' => $range,
            'standard_unit' => 'mmWG',
            'snapshot_instrument_name' => 'Pressure Drop Standard',
            'snapshot_type_specification' => $range.' mmWG',
            'snapshot_serial_number' => $standardSerials[$index],
        ]);
    }

    DB::table('certificate')->insert([
        'certificate_id' => 1,
        'calibration_id' => 3,
        'certificate_number' => 'SR.VT001/11/26',
        'certificate_type' => 'INTERNAL',
        'issued_date' => '2026-11-04',
        'file_path' => null,
        'file_name' => null,
        'remarks' => null,
        'created_at' => '2026-11-04 09:00:00',
    ]);

    Model::preventLazyLoading();

    $pdf = app(ServiceReportPdfRenderer::class)->render(Certificate::query()->findOrFail(1));
    $output = $pdf->output(['compress' => 0]);

    if (filled(env('SERVICE_REPORT_GOLDEN_PREVIEW_PATH'))) {
        file_put_contents((string) env('SERVICE_REPORT_GOLDEN_PREVIEW_PATH'), $output);
    }

    expect($pdf->getDomPDF()->getCanvas()->get_page_count())->toBe(1)
        ->and($output)->toStartWith('%PDF-');
});

it('renders Quantum Neo test tables in left-right-left rows', function () {
    $group = static function (string $label): array {
        return [
            'label' => $label,
            'columns' => [
                ['label' => 'Standard Value ('.$label.')', 'unit' => 'mm'],
                ['label' => 'Standard Reading Avg ('.$label.')', 'unit' => 'mm'],
                ['label' => 'Correction ('.$label.')', 'unit' => 'mm'],
            ],
            'rows' => [['cells' => ['16.00', '16.00', '0.00']]],
            'remarks' => '',
            'tolerance' => null,
        ];
    };
    $groups = [$group('SIZE'), $group('LENGTH'), $group('DIAMETER')];
    $report = new class($groups)
    {
        public function __construct(private array $groups) {}

        public function instrumentIdentity(): array
        {
            return [
                'name' => 'Quantum Neo',
                'location' => 'FMD',
                'detail_location' => 'FM 01/07',
                'type' => 'X-ray',
                'brand' => 'Quantum',
                'model' => 'Neo',
                'serial_number' => 'QN-001',
                'asset_number' => null,
                'max_capacity' => null,
                'resolution' => null,
            ];
        }

        public function activities(): array
        {
            return [];
        }

        public function standards(): array
        {
            return [];
        }

        public function standardList(): array
        {
            return [
                'Circumference Standard; 16.00 & 30.00 mm; STD/C/01',
                'Length Standard; 34.5 mm; 28104',
                'Diameter Standard; 7.587 mm; 28104',
            ];
        }

        public function testResultGroups(): array
        {
            return $this->groups;
        }

        public function testResultLayouts(): array
        {
            return ServiceReport::testResultLayoutsFor($this->groups);
        }

        public function customerAddress(): ?string
        {
            return null;
        }

        public function documentNumber(): string
        {
            return 'SR.VT004/11/26';
        }

        public function calibrationDateShort(): string
        {
            return '04 Nov 2026';
        }

        public function issuedDate(): string
        {
            return '04 Nov 2026';
        }

        public function customerName(): string
        {
            return 'PT. BENTOEL PRIMA';
        }

        public function location(): string
        {
            return 'FMD';
        }

        public function detailLocation(): string
        {
            return 'FM 01/07';
        }

        public function hasActionDate(): bool
        {
            return false;
        }

        public function hasTemperature(): bool
        {
            return false;
        }

        public function temperature(): string
        {
            return '';
        }

        public function hasHumidity(): bool
        {
            return false;
        }

        public function humidity(): string
        {
            return '';
        }

        public function resume(): string
        {
            return 'Instrument working properly';
        }
    };

    $html = view('certificates.service-report-v2', ['report' => $report])->render();
    $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
    $output = $pdf->output();

    if (filled(env('SERVICE_REPORT_ADAPTIVE_LAYOUT_PREVIEW_PATH'))) {
        file_put_contents((string) env('SERVICE_REPORT_ADAPTIVE_LAYOUT_PREVIEW_PATH'), $output);
    }

    expect($html)
        ->toContain('SIZE')
        ->toContain('LENGTH')
        ->toContain('DIAMETER')
        ->toContain('standard-panel')
        ->toContain('activity-number">01</td>')
        ->toContain('activity-number">03</td>')
        ->and(substr_count($html, 'class="test-columns"'))->toBe(1)
        ->and(substr_count($html, 'class="test-standalone"'))->toBe(1)
        ->and($output)->toStartWith('%PDF-');
});
