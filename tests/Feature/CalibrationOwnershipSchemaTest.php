<?php

use App\Models\Calibration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestRefusingPostgresConnector;

beforeEach(function (): void {
    DB::statement('PRAGMA foreign_keys = ON');

    Schema::create('app_user', function (Blueprint $table): void {
        $table->unsignedBigInteger('app_user_id')->primary();
        $table->boolean('is_active')->default(true);
    });

    Schema::create('calibration', function (Blueprint $table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->unsignedBigInteger('instrument_id')->default(1);
        $table->string('calibration_number')->default('CAL-OWN-1');
        $table->string('calibration_method')->default('INTERNAL');
        $table->string('session_status', 20)->default('completed');
        $table->unsignedBigInteger('started_by_app_user_id')->nullable();
        $table->timestamps();

        $table->foreign('started_by_app_user_id')
            ->references('app_user_id')
            ->on('app_user')
            ->restrictOnDelete();
    });
});

it('exposes a nullable started_by_app_user_id ownership column', function () {
    expect(Schema::hasColumn('calibration', 'started_by_app_user_id'))->toBeTrue();

    DB::table('calibration')->insert([
        'calibration_id' => 1,
        'started_by_app_user_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = DB::table('calibration')->where('calibration_id', 1)->first();

    expect($row->started_by_app_user_id)->toBeNull();
});

it('accepts a valid app_user foreign key owner', function () {
    DB::table('app_user')->insert([
        'app_user_id' => 90,
        'is_active' => 1,
    ]);

    DB::table('calibration')->insert([
        'calibration_id' => 2,
        'session_status' => 'in_progress',
        'started_by_app_user_id' => 90,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('calibration')->where('calibration_id', 2)->value('started_by_app_user_id'))
        ->toBe(90);
});

it('rejects an owner that does not exist in app_user', function () {
    expect(fn () => DB::table('calibration')->insert([
        'calibration_id' => 3,
        'started_by_app_user_id' => 999,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('preserves owner values and remains compatible with null legacy sessions', function () {
    DB::table('app_user')->insert([
        ['app_user_id' => 90, 'is_active' => 1],
        ['app_user_id' => 91, 'is_active' => 1],
    ]);

    DB::table('calibration')->insert([
        [
            'calibration_id' => 10,
            'session_status' => 'completed',
            'started_by_app_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'calibration_id' => 11,
            'session_status' => 'in_progress',
            'started_by_app_user_id' => 90,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('calibration')->where('calibration_id', 11)->update([
        'session_status' => 'in_progress',
    ]);

    $owned = Calibration::query()->findOrFail(11);
    $legacy = Calibration::query()->findOrFail(10);

    expect($owned->started_by_app_user_id)->toBe(90)
        ->and($legacy->started_by_app_user_id)->toBeNull();
});

it('restricts deleting an app_user that owns a calibration session', function () {
    DB::table('app_user')->insert([
        'app_user_id' => 90,
        'is_active' => 1,
    ]);

    DB::table('calibration')->insert([
        'calibration_id' => 20,
        'started_by_app_user_id' => 90,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('app_user')->where('app_user_id', 90)->delete())
        ->toThrow(QueryException::class);

    expect(DB::table('calibration')->where('calibration_id', 20)->value('started_by_app_user_id'))
        ->toBe(90);
});

it('does not open postgresql while validating ownership schema', function () {
    expect(Schema::hasColumn('calibration', 'started_by_app_user_id'))->toBeTrue();
    expect(TestRefusingPostgresConnector::$connectCalls)->toBe(0);
});
