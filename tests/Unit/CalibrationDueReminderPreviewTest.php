<?php

use App\CalibrationDueNotifications\CalibrationDueMilestoneCalculator;
use App\CalibrationDueNotifications\CalibrationDueReminderPreview;
use App\CalibrationDueNotifications\CalibrationDueReminderSelector;
use App\CalibrationSessions\CalibrationSessionConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Schema::create('instrument', function ($table): void {
        $table->unsignedBigInteger('instrument_id')->primary();
        $table->string('instrument_name');
        $table->string('serial_number')->nullable();
        $table->unsignedBigInteger('detail_location_id')->nullable();
    });
    Schema::create('instrument_type', function ($table): void {
        $table->unsignedBigInteger('instrument_type_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name');
    });
    Schema::create('detail_location', function ($table): void {
        $table->unsignedBigInteger('detail_location_id')->primary();
        $table->unsignedBigInteger('location_id')->nullable();
    });
    Schema::create('location', function ($table): void {
        $table->unsignedBigInteger('location_id')->primary();
        $table->string('location_name');
    });
    Schema::create('calibration', function ($table): void {
        $table->unsignedBigInteger('calibration_id')->primary();
        $table->unsignedBigInteger('instrument_id');
        $table->unsignedBigInteger('instrument_type_id');
        $table->string('session_status');
        $table->string('calibration_method');
        $table->date('calibration_due')->nullable();
        $table->date('calibration_date')->nullable();
        $table->timestamp('completed_at')->nullable();
    });

    Schema::create('calibration_due_notification_batches', function ($table): void {
        $table->id();
        $table->date('notification_date');
        $table->string('timezone');
        $table->string('recipient');
        $table->string('status');
        $table->unsignedInteger('attempts')->default(0);
        $table->timestamp('claimed_at')->nullable();
        $table->timestamp('sent_at')->nullable();
        $table->timestamp('failed_at')->nullable();
        $table->text('failure_message')->nullable();
        $table->timestamps();
    });

    Schema::create('calibration_due_notification_items', function ($table): void {
        $table->id();
        $table->foreignId('batch_id');
        $table->unsignedBigInteger('instrument_id');
        $table->unsignedBigInteger('instrument_type_id');
        $table->unsignedBigInteger('latest_calibration_id');
        $table->string('calibration_method');
        $table->string('instrument_name_snapshot');
        $table->string('instrument_type_snapshot');
        $table->string('serial_number_snapshot')->nullable();
        $table->string('location_snapshot')->nullable();
        $table->date('due_date_snapshot');
        $table->string('milestone_code');
        $table->date('milestone_date');
        $table->string('recipient');
        $table->string('status');
        $table->unsignedInteger('attempts')->default(0);
        $table->timestamp('last_attempt_at')->nullable();
        $table->text('failure_message')->nullable();
        $table->timestamps();
    });

    Config::set('calibration_due_notifications.recipients', [
        'service.malang@vision-teknik.com',
        'indra_tri_sutanto@bat.com',
    ]);
    Config::set('calibration_due_notifications.catch_up_days', 3);
    Config::set('calibration_due_notifications.max_retry_attempts', 3);

    DB::table('instrument')->insert([
        'instrument_id' => 1,
        'instrument_name' => 'Test Instrument',
        'serial_number' => 'SN-1',
    ]);
    DB::table('instrument_type')->insert([
        'instrument_type_id' => 2,
        'instrument_id' => 1,
        'type_name' => 'Type',
    ]);
    DB::table('calibration')->insert([
        'calibration_id' => 3,
        'instrument_id' => 1,
        'instrument_type_id' => 2,
        'session_status' => 'completed',
        'calibration_method' => 'INTERNAL',
        'calibration_due' => '2027-03-31',
        'calibration_date' => '2027-03-01',
        'completed_at' => '2027-03-01 08:00:00',
    ]);
});

afterEach(function (): void {
    Schema::dropIfExists('calibration_due_notification_items');
    Schema::dropIfExists('calibration_due_notification_batches');
    Schema::dropIfExists('calibration');
    Schema::dropIfExists('location');
    Schema::dropIfExists('detail_location');
    Schema::dropIfExists('instrument_type');
    Schema::dropIfExists('instrument');
});

it('previews reminders without mail or history writes', function (): void {
    Mail::fake();
    $selector = app(CalibrationDueReminderSelector::class);
    $preview = new CalibrationDueReminderPreview($selector, app(CalibrationSessionConnection::class));

    $result = $preview->preview(
        DB::connection(),
        CarbonImmutable::parse('2027-03-31 08:00:00', 'Asia/Jakarta'),
    );

    expect($result['eligible_count'])->toBe(4)
        ->and($result['recipient_count'])->toBe(2)
        ->and($result['expected_email_count'])->toBe(2)
        ->and($result['skipped_count'])->toBe(0)
        ->and($result['milestone_breakdown'])->toBe([
            CalibrationDueMilestoneCalculator::DUE_TODAY => 1,
            CalibrationDueMilestoneCalculator::THREE_DAYS => 1,
        ])
        ->and($result['due_dates'])->toBe(['2027-03-31' => 2])
        ->and(DB::table('calibration_due_notification_batches')->count())->toBe(0)
        ->and(DB::table('calibration_due_notification_items')->count())->toBe(0);

    Mail::assertNothingSent();
});

it('excludes sent and delivery unknown history', function (): void {
    $selector = app(CalibrationDueReminderSelector::class);
    $date = '2027-03-31';
    foreach ([
        ['service.malang@vision-teknik.com', 'sent', 0],
        ['indra_tri_sutanto@bat.com', 'delivery_unknown', 1],
    ] as [$recipient, $status, $attempts]) {
        $batchId = DB::table('calibration_due_notification_batches')->insertGetId([
            'notification_date' => $date,
            'timezone' => 'Asia/Jakarta',
            'recipient' => $recipient,
            'status' => $status,
            'attempts' => $attempts,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('calibration_due_notification_items')->insert([
            'batch_id' => $batchId,
            'instrument_id' => 1,
            'instrument_type_id' => 2,
            'latest_calibration_id' => 3,
            'calibration_method' => 'INTERNAL',
            'instrument_name_snapshot' => 'Test Instrument',
            'instrument_type_snapshot' => 'Type',
            'due_date_snapshot' => $date,
            'milestone_code' => CalibrationDueMilestoneCalculator::DUE_TODAY,
            'milestone_date' => $date,
            'recipient' => $recipient,
            'status' => $status,
            'attempts' => $attempts,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    Config::set('calibration_due_notifications.recipients', [
        'service.malang@vision-teknik.com',
        'indra_tri_sutanto@bat.com',
    ]);
    $preview = new CalibrationDueReminderPreview($selector, app(CalibrationSessionConnection::class));

    $result = $preview->preview(
        DB::connection(),
        CarbonImmutable::parse('2027-03-31 08:00:00', 'Asia/Jakarta'),
    );

    expect($result['eligible_count'])->toBe(0)
        ->and($result['expected_email_count'])->toBe(0)
        ->and($result['skipped_count'])->toBe(4)
        ->and($result['recipients'][0]['status'])->toBe('already_sent')
        ->and($result['recipients'][1]['status'])->toBe('delivery_unknown');
});

it('includes failed items that remain below the retry limit', function (): void {
    $date = '2027-03-31';
    $recipient = 'service.malang@vision-teknik.com';
    $batchId = DB::table('calibration_due_notification_batches')->insertGetId([
        'notification_date' => $date,
        'timezone' => 'Asia/Jakarta',
        'recipient' => $recipient,
        'status' => 'failed',
        'attempts' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('calibration_due_notification_items')->insert([
        'batch_id' => $batchId,
        'instrument_id' => 1,
        'instrument_type_id' => 2,
        'latest_calibration_id' => 3,
        'calibration_method' => 'INTERNAL',
        'instrument_name_snapshot' => 'Test Instrument',
        'instrument_type_snapshot' => 'Type',
        'due_date_snapshot' => $date,
        'milestone_code' => CalibrationDueMilestoneCalculator::DUE_TODAY,
        'milestone_date' => $date,
        'recipient' => $recipient,
        'status' => 'failed',
        'attempts' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $preview = new CalibrationDueReminderPreview(
        app(CalibrationDueReminderSelector::class),
        app(CalibrationSessionConnection::class),
    );
    $result = $preview->preview(
        DB::connection(),
        CarbonImmutable::parse('2027-03-31 08:00:00', 'Asia/Jakarta'),
    );

    expect($result['retry_eligible_count'])->toBe(1)
        ->and($result['expected_email_count'])->toBe(2)
        ->and($result['recipients'][0]['status'])->toBe('retry_or_pending')
        ->and(DB::table('calibration_due_notification_batches')->count())->toBe(1)
        ->and(DB::table('calibration_due_notification_items')->count())->toBe(1);
});
