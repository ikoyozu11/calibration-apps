<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function createCalibrationDetailTestTables(): void
{
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
        $table->decimal('max_capacity')->nullable();
        $table->string('max_capacity_unit')->nullable();
        $table->decimal('resolution')->nullable();
        $table->string('resolution_unit')->nullable();
        $table->string('brand')->nullable();
        $table->string('model')->nullable();
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

    Schema::create('calibration_scope', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_scope_id')->primary();
        $table->unsignedBigInteger('calibration_id');
        $table->unsignedBigInteger('instrument_type_id');
        $table->unsignedBigInteger('test_profile_id')->nullable();
        $table->integer('scope_order');
        $table->text('remarks')->nullable();
        $table->timestamp('created_at');
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

function insertCalibrationDetailInstrument(): void
{
    DB::table('instrument')->insert([
        'instrument_id' => 3,
        'instrument_name' => 'Sodiline',
        'detail_location_id' => 2,
        'description' => 'Prototype instrument',
        'created_at' => '2026-10-07 09:17:33',
    ]);
}

function insertCalibrationDetailCalibration(?string $calibrationDue = '2027-06-12'): void
{
    DB::table('calibration')->insert([
        'calibration_id' => 3,
        'instrument_id' => 3,
        'calibration_provider_id' => 1,
        'calibration_number' => 'CAL-PROT-0001',
        'calibration_date' => '2026-06-12',
        'calibration_due' => $calibrationDue,
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
}

it('renders calibration information with ordered scopes, profiles, and test points', function () {
    createCalibrationDetailTestTables();
    insertCalibrationDetailInstrument();
    insertCalibrationDetailCalibration();

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
        [
            'instrument_type_id' => 7,
            'instrument_id' => 3,
            'type_name' => 'PD',
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'instrument_type_id' => 8,
            'instrument_id' => 3,
            'type_name' => 'Unprofiled Type',
            'created_at' => '2026-06-12 09:00:00',
        ],
    ]);

    DB::table('test_profile')->insert([
        [
            'test_profile_id' => 2,
            'instrument_type_id' => 5,
            'profile_name' => 'Weight Calibration',
            'description' => 'Weight profile',
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'test_profile_id' => 3,
            'instrument_type_id' => 6,
            'profile_name' => 'Diameter Calibration',
            'description' => 'Diameter profile',
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'test_profile_id' => 7,
            'instrument_type_id' => 7,
            'profile_name' => 'PD Calibration',
            'description' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
    ]);

    DB::table('calibration_scope')->insert([
        [
            'calibration_scope_id' => 203,
            'calibration_id' => 3,
            'instrument_type_id' => 7,
            'test_profile_id' => 7,
            'scope_order' => 3,
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'calibration_scope_id' => 201,
            'calibration_id' => 3,
            'instrument_type_id' => 5,
            'test_profile_id' => 2,
            'scope_order' => 1,
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'calibration_scope_id' => 202,
            'calibration_id' => 3,
            'instrument_type_id' => 6,
            'test_profile_id' => 3,
            'scope_order' => 2,
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'calibration_scope_id' => 204,
            'calibration_id' => 3,
            'instrument_type_id' => 8,
            'test_profile_id' => null,
            'scope_order' => 4,
            'remarks' => null,
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
            'tolerance_plus' => '0.200000',
            'tolerance_minus' => '0.200000',
            'description' => 'Second weight point',
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'test_point_id' => 301,
            'test_profile_id' => 2,
            'point_order' => 1,
            'nominal_value' => '100.125000',
            'unit' => 'g',
            'tolerance_plus' => '0.100000',
            'tolerance_minus' => '0.100000',
            'description' => 'First weight point',
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'test_point_id' => 303,
            'test_profile_id' => 2,
            'point_order' => 3,
            'nominal_value' => '999.123456',
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
            'tolerance_plus' => '0.010000',
            'tolerance_minus' => '0.020000',
            'description' => 'Diameter point',
            'created_at' => '2026-06-12 09:00:00',
        ],
    ]);

    $this->travelTo(Carbon::parse('2026-10-08'));

    Model::preventLazyLoading();

    $response = $this->get(route('calibrations.show', 3));

    $response
        ->assertOk()
        ->assertSee('Calibration Information')
        ->assertSee('CAL-PROT-0001')
        ->assertSee('2026-06-12')
        ->assertSee('2027-06-12')
        ->assertSee('EXTERNAL')
        ->assertSee('PASS')
        ->assertSee('CERT-PROT-0001')
        ->assertSee('Data sementara untuk prototype')
        ->assertSee('Instrument working properly')
        ->assertSee('On Track')
        ->assertSee('Calibration Scope')
        ->assertSee('Test Profile:')
        ->assertSee('Test Profile ID')
        ->assertSeeInOrder([
            'Weight',
            'ID: 5',
            '2',
            'Weight Calibration',
            'Diameter',
            'ID: 6',
            '3',
            'Diameter Calibration',
            'PD',
            'ID: 7',
            '7',
            'PD Calibration',
            'Unprofiled Type',
            'ID: 8',
        ])
        ->assertSeeInOrder([
            'First weight point',
            'Second weight point',
            '999.123456',
        ]);

    $response
        ->assertSee('100.125')
        ->assertSee('500.25')
        ->assertSee('g')
        ->assertSee('0.1')
        ->assertSee('0.2')
        ->assertSee('10.5')
        ->assertSee('mm')
        ->assertSee('0.01')
        ->assertSee('0.02')
        ->assertSee('Diameter point')
        ->assertSee('No test points found.')
        ->assertSeeInOrder([
            '999.123456',
            'g',
            '—',
            '—',
            '—',
        ])
        ->assertSee('Test Results')
        ->assertSee('No test results found.');
});

it('renders a scope without a test profile without error', function () {
    createCalibrationDetailTestTables();
    insertCalibrationDetailInstrument();
    insertCalibrationDetailCalibration();

    DB::table('instrument_type')->insert([
        'instrument_type_id' => 8,
        'instrument_id' => 3,
        'type_name' => 'Unprofiled Type',
        'created_at' => '2026-06-12 09:00:00',
    ]);

    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => 204,
        'calibration_id' => 3,
        'instrument_type_id' => 8,
        'test_profile_id' => null,
        'scope_order' => 1,
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);

    Model::preventLazyLoading();

    $response = $this->get(route('calibrations.show', 3));

    $response
        ->assertOk()
        ->assertSee('Unprofiled Type')
        ->assertSee('Test Profile:')
        ->assertDontSee('No test points found.');
});

it('renders an empty state when a test profile has no test points', function () {
    createCalibrationDetailTestTables();
    insertCalibrationDetailInstrument();
    insertCalibrationDetailCalibration();

    DB::table('instrument_type')->insert([
        'instrument_type_id' => 7,
        'instrument_id' => 3,
        'type_name' => 'PD',
        'created_at' => '2026-06-12 09:00:00',
    ]);

    DB::table('test_profile')->insert([
        'test_profile_id' => 7,
        'instrument_type_id' => 7,
        'profile_name' => 'PD Calibration',
        'description' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);

    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => 203,
        'calibration_id' => 3,
        'instrument_type_id' => 7,
        'test_profile_id' => 7,
        'scope_order' => 1,
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);

    Model::preventLazyLoading();

    $response = $this->get(route('calibrations.show', 3));

    $response
        ->assertOk()
        ->assertSee('PD Calibration')
        ->assertSee('No test points found.');
});

it('renders stored calibration test results and ordered readings', function () {
    createCalibrationDetailTestTables();
    insertCalibrationDetailInstrument();
    insertCalibrationDetailCalibration();

    DB::table('instrument_type')->insert([
        'instrument_type_id' => 5,
        'instrument_id' => 3,
        'type_name' => 'Weight',
        'created_at' => '2026-06-12 09:00:00',
    ]);

    DB::table('test_profile')->insert([
        'test_profile_id' => 2,
        'instrument_type_id' => 5,
        'profile_name' => 'Weight Calibration',
        'description' => 'Weight profile',
        'created_at' => '2026-06-12 09:00:00',
    ]);

    DB::table('calibration_scope')->insert([
        'calibration_scope_id' => 201,
        'calibration_id' => 3,
        'instrument_type_id' => 5,
        'test_profile_id' => 2,
        'scope_order' => 1,
        'remarks' => null,
        'created_at' => '2026-06-12 09:00:00',
    ]);

    DB::table('test_point')->insert([
        [
            'test_point_id' => 302,
            'test_profile_id' => 2,
            'point_order' => 2,
            'nominal_value' => '500.250000',
            'unit' => 'g',
            'tolerance_plus' => '0.200000',
            'tolerance_minus' => '0.200000',
            'description' => 'Second weight point',
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'test_point_id' => 301,
            'test_profile_id' => 2,
            'point_order' => 1,
            'nominal_value' => '100.125000',
            'unit' => 'g',
            'tolerance_plus' => '0.100000',
            'tolerance_minus' => '0.100000',
            'description' => 'First weight point',
            'created_at' => '2026-06-12 09:00:00',
        ],
        [
            'test_point_id' => 303,
            'test_profile_id' => 2,
            'point_order' => 3,
            'nominal_value' => '999.123456',
            'unit' => 'g',
            'tolerance_plus' => null,
            'tolerance_minus' => null,
            'description' => null,
            'created_at' => '2026-06-12 09:00:00',
        ],
    ]);

    DB::table('calibration_test_result')->insert([
        [
            'calibration_test_result_id' => 902,
            'calibration_scope_id' => 201,
            'test_point_id' => 302,
            'result_status' => null,
            'remarks' => null,
            'created_at' => '2026-06-12 09:00:00',
            'standard_value' => null,
            'average_value' => null,
            'correction_value' => null,
        ],
        [
            'calibration_test_result_id' => 901,
            'calibration_scope_id' => 201,
            'test_point_id' => 301,
            'result_status' => 'PASS',
            'remarks' => 'Weight result remark',
            'created_at' => '2026-06-12 09:00:00',
            'standard_value' => '100.125000',
            'average_value' => '100.250000',
            'correction_value' => '0.125000',
        ],
        [
            'calibration_test_result_id' => 903,
            'calibration_scope_id' => 201,
            'test_point_id' => 303,
            'result_status' => 'FAIL',
            'remarks' => 'Third result without readings',
            'created_at' => '2026-06-12 09:00:00',
            'standard_value' => '999.123456',
            'average_value' => '999.000000',
            'correction_value' => '-0.123456',
        ],
    ]);

    DB::table('calibration_reading')->insert([
        [
            'calibration_reading_id' => 802,
            'calibration_test_result_id' => 901,
            'reading_order' => 2,
            'reference_value' => '100.000000',
            'instrument_value' => '100.222000',
            'error_value' => '0.222000',
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
    ]);

    Model::preventLazyLoading();

    $response = $this->get(route('calibrations.show', 3));

    $response
        ->assertOk()
        ->assertSee('Test Results')
        ->assertSee('Readings')
        ->assertSee('Weight result remark')
        ->assertSee('Third result without readings')
        ->assertSee('100.125')
        ->assertSee('100.25')
        ->assertSee('0.125')
        ->assertSee('111.111')
        ->assertSee('111.222')
        ->assertSee('0.111')
        ->assertSee('0.009')
        ->assertSee('100.222')
        ->assertSee('0.222')
        ->assertSee('No readings found.')
        ->assertSeeInOrder([
            'Weight result remark',
            '111.111',
            '111.222',
            '0.111',
            '0.009',
            '100.222',
            '0.222',
            '—',
        ])
        ->assertSeeInOrder([
            '100.125',
            '500.25',
            '999.123456',
        ]);
});

it('renders calibration status from its due date', function (?string $calibrationDue, ?string $expectedStatus, ?string $unexpectedStatus) {
    createCalibrationDetailTestTables();
    insertCalibrationDetailInstrument();
    insertCalibrationDetailCalibration($calibrationDue);

    $this->travelTo(Carbon::parse('2026-10-08'));

    $response = $this->get(route('calibrations.show', 3));

    if (is_null($expectedStatus)) {
        $response
            ->assertDontSee('On Track')
            ->assertDontSee('Overdue');

        return;
    }

    $response
        ->assertSee($expectedStatus)
        ->assertDontSee($unexpectedStatus);
})->with([
    'future due date' => ['2027-06-12', 'On Track', 'Overdue'],
    'past due date' => ['2025-06-12', 'Overdue', 'On Track'],
    'missing due date' => [null, null, null],
]);

it('returns not found for an unknown calibration', function () {
    createCalibrationDetailTestTables();

    $response = $this->get(route('calibrations.show', 999));

    $response->assertNotFound();
});

it('links calibration history to calibration detail', function () {
    createCalibrationDetailTestTables();
    insertCalibrationDetailInstrument();
    insertCalibrationDetailCalibration();

    $response = $this->get(route('instruments.show', 3));

    $response
        ->assertSee('CAL-PROT-0001')
        ->assertSee('href="'.route('calibrations.show', 3).'"', false);
});
