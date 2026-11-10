<?php

use App\CalibrationDueNotifications\CalibrationDueNotificationService;
use App\Mail\CalibrationDueReminderMail;
use App\Models\Calibration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    foreach ([
        'calibration_due_notification_items',
        'calibration_due_notification_batches',
        'calibration',
        'instrument_type',
        'instrument',
        'detail_location',
        'location',
    ] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('location', function (Blueprint $table): void {
        $table->id('location_id');
        $table->string('location_name');
    });
    Schema::create('detail_location', function (Blueprint $table): void {
        $table->id('detail_location_id');
        $table->unsignedBigInteger('location_id')->nullable();
    });
    Schema::create('instrument', function (Blueprint $table): void {
        $table->id('instrument_id');
        $table->string('instrument_name');
        $table->string('serial_number')->nullable();
        $table->unsignedBigInteger('detail_location_id')->nullable();
    });
    Schema::create('instrument_type', function (Blueprint $table): void {
        $table->id('instrument_type_id');
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name');
    });
    Schema::create('calibration', function (Blueprint $table): void {
        $table->id('calibration_id');
        $table->unsignedBigInteger('instrument_id');
        $table->unsignedBigInteger('instrument_type_id')->nullable();
        $table->date('calibration_date')->nullable();
        $table->date('calibration_due')->nullable();
        $table->string('calibration_method');
        $table->string('session_status');
        $table->timestampTz('completed_at')->nullable();
    });
    Schema::create('calibration_due_notification_batches', function (Blueprint $table): void {
        $table->id();
        $table->string('notification_date');
        $table->string('timezone', 64);
        $table->string('recipient', 320);
        $table->string('status', 32);
        $table->unsignedSmallInteger('attempts')->default(0);
        $table->timestampTz('claimed_at')->nullable();
        $table->timestampTz('sent_at')->nullable();
        $table->timestampTz('failed_at')->nullable();
        $table->text('failure_message')->nullable();
        $table->timestampsTz(0);
        $table->unique(['notification_date', 'recipient']);
    });
    Schema::create('calibration_due_notification_items', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('batch_id');
        $table->unsignedBigInteger('instrument_id');
        $table->unsignedBigInteger('instrument_type_id');
        $table->unsignedBigInteger('latest_calibration_id');
        $table->string('calibration_method', 32);
        $table->text('instrument_name_snapshot');
        $table->text('instrument_type_snapshot');
        $table->text('serial_number_snapshot')->nullable();
        $table->text('location_snapshot')->nullable();
        $table->date('due_date_snapshot');
        $table->string('milestone_code', 32);
        $table->date('milestone_date');
        $table->string('recipient', 320);
        $table->string('status', 32);
        $table->unsignedSmallInteger('attempts')->default(0);
        $table->timestampTz('last_attempt_at')->nullable();
        $table->text('failure_message')->nullable();
        $table->timestampsTz(0);
        $table->unique(['instrument_type_id', 'due_date_snapshot', 'milestone_code', 'recipient']);
    });
});

it('sends identical consolidated emails independently to each configured recipient', function (): void {
    seedMultiRecipientFixtures();

    config([
        'calibration_due_notifications.enabled' => true,
        'calibration_due_notifications.recipients' => [
            'service.malang@vision-teknik.com',
            'indra_tri_sutanto@bat.com',
        ],
        'calibration_due_notifications.recipient' => null,
        'calibration_due_notifications.catch_up_days' => 0,
    ]);
    Mail::fake();

    $result = app(CalibrationDueNotificationService::class)
        ->run(DB::connection(), CarbonImmutable::parse('2026-11-06 08:00:00', 'Asia/Jakarta'));

    expect($result['status'])->toBe(CalibrationDueNotificationService::STATUS_SENT);

    $mails = [];
    Mail::assertSent(CalibrationDueReminderMail::class, function (CalibrationDueReminderMail $mail) use (&$mails): bool {
        $mails[] = $mail;

        return true;
    });

    expect($result)->toMatchArray([
        'status' => CalibrationDueNotificationService::STATUS_SENT,
        'reminder_count' => 2,
        'sent_count' => 4,
        'recipient_count' => 2,
        'sent_recipient_count' => 2,
    ])
        ->and($mails)->toHaveCount(2)
        ->and(DB::table('calibration_due_notification_batches')->count())->toBe(2)
        ->and(DB::table('calibration_due_notification_items')->count())->toBe(4)
        ->and($mails[0]->reminders)->toEqual($mails[1]->reminders);

    Mail::assertSent(CalibrationDueReminderMail::class, ['service.malang@vision-teknik.com', 'indra_tri_sutanto@bat.com']);
    expect($mails[0]->cc)->toBe([])
        ->and($mails[0]->bcc)->toBe([]);
});

it('recovers a definitive recipient failure without resending the successful recipient', function (): void {
    seedMultiRecipientFixtures();
    Mail::fake();
    $fake = Mail::getFacadeRoot();
    Mail::shouldReceive('to')->andReturnUsing(function (string $recipient) use ($fake): object {
        if ($recipient === 'service.malang@vision-teknik.com') {
            throw new RuntimeException('Definitive recipient A failure.');
        }

        return $fake->to($recipient);
    });

    $result = app(CalibrationDueNotificationService::class)
        ->run(DB::connection(), CarbonImmutable::parse('2026-11-06 08:00:00', 'Asia/Jakarta'));

    expect($result)->toMatchArray([
        'status' => CalibrationDueNotificationService::STATUS_PARTIAL_FAILURE,
        'sent_recipient_count' => 1,
        'failed_recipient_count' => 1,
    ])
        ->and(DB::table('calibration_due_notification_batches')->where('recipient', 'service.malang@vision-teknik.com')->value('status'))->toBe(CalibrationDueNotificationService::STATUS_FAILED)
        ->and(DB::table('calibration_due_notification_batches')->where('recipient', 'indra_tri_sutanto@bat.com')->value('status'))->toBe(CalibrationDueNotificationService::STATUS_SENT)
        ->and(DB::table('calibration_due_notification_batches')->where('recipient', 'service.malang@vision-teknik.com')->value('attempts'))->toBe(1)
        ->and(DB::table('calibration_due_notification_batches')->where('recipient', 'indra_tri_sutanto@bat.com')->value('attempts'))->toBe(1);

    Mail::swap($fake);
    Mail::fake();
    $recoveryResult = app(CalibrationDueNotificationService::class)
        ->run(DB::connection(), CarbonImmutable::parse('2026-11-06 08:01:00', 'Asia/Jakarta'));

    expect($recoveryResult['status'])->toBe(CalibrationDueNotificationService::STATUS_SENT)
        ->and(DB::table('calibration_due_notification_batches')->where('recipient', 'service.malang@vision-teknik.com')->value('status'))->toBe(CalibrationDueNotificationService::STATUS_SENT)
        ->and(DB::table('calibration_due_notification_batches')->where('recipient', 'service.malang@vision-teknik.com')->value('attempts'))->toBe(2)
        ->and(DB::table('calibration_due_notification_batches')->where('recipient', 'indra_tri_sutanto@bat.com')->value('attempts'))->toBe(1);

    Mail::assertSent(CalibrationDueReminderMail::class, 'service.malang@vision-teknik.com');
    Mail::assertNotSent(CalibrationDueReminderMail::class, 'indra_tri_sutanto@bat.com');
});

it('continues to the second recipient when the second recipient fails without resending the first batch', function (): void {
    seedMultiRecipientFixtures();
    Mail::fake();
    $fake = Mail::getFacadeRoot();
    Mail::shouldReceive('to')->andReturnUsing(function (string $recipient) use ($fake): object {
        if ($recipient === 'indra_tri_sutanto@bat.com') {
            throw new RuntimeException('Definitive recipient B failure.');
        }

        return $fake->to($recipient);
    });

    $result = app(CalibrationDueNotificationService::class)
        ->run(DB::connection(), CarbonImmutable::parse('2026-11-06 08:00:00', 'Asia/Jakarta'));

    expect($result['status'])->toBe(CalibrationDueNotificationService::STATUS_PARTIAL_FAILURE)
        ->and($result['sent_recipient_count'])->toBe(1)
        ->and($result['failed_recipient_count'])->toBe(1);

    expect(DB::table('calibration_due_notification_batches')->where('recipient', 'service.malang@vision-teknik.com')->value('status'))->toBe(CalibrationDueNotificationService::STATUS_SENT)
        ->and(DB::table('calibration_due_notification_batches')->where('recipient', 'indra_tri_sutanto@bat.com')->value('status'))->toBe(CalibrationDueNotificationService::STATUS_FAILED)
        ->and(DB::table('calibration_due_notification_batches')->where('recipient', 'service.malang@vision-teknik.com')->value('attempts'))->toBe(1)
        ->and(DB::table('calibration_due_notification_batches')->where('recipient', 'indra_tri_sutanto@bat.com')->value('attempts'))->toBe(1);
});

it('reports partial failure when both recipients fail definitively', function (): void {
    seedMultiRecipientFixtures();
    Mail::fake();
    $fake = Mail::getFacadeRoot();
    Mail::shouldReceive('to')->andReturnUsing(function (string $recipient): object {
        throw new RuntimeException("Definitive failure for {$recipient}.");
    });

    $result = app(CalibrationDueNotificationService::class)
        ->run(DB::connection(), CarbonImmutable::parse('2026-11-06 08:00:00', 'Asia/Jakarta'));

    expect($result)->toMatchArray([
        'status' => CalibrationDueNotificationService::STATUS_PARTIAL_FAILURE,
        'sent_recipient_count' => 0,
        'failed_recipient_count' => 2,
    ])
        ->and(DB::table('calibration_due_notification_batches')->where('status', CalibrationDueNotificationService::STATUS_FAILED)->count())->toBe(2);
});

function seedMultiRecipientFixtures(): void
{
    DB::table('instrument')->insert([
        ['instrument_id' => 1, 'instrument_name' => 'Shared Gauge A', 'serial_number' => 'A-1'],
        ['instrument_id' => 2, 'instrument_name' => 'Shared Gauge B', 'serial_number' => 'B-1'],
    ]);
    DB::table('instrument_type')->insert([
        ['instrument_type_id' => 1, 'instrument_id' => 1, 'type_name' => 'INTERNAL'],
        ['instrument_type_id' => 2, 'instrument_id' => 2, 'type_name' => 'EXTERNAL'],
    ]);
    DB::table('calibration')->insert([
        ['calibration_id' => 1, 'instrument_id' => 1, 'instrument_type_id' => 1, 'calibration_date' => '2026-10-01', 'calibration_due' => '2026-11-06', 'calibration_method' => 'INTERNAL', 'session_status' => Calibration::STATUS_COMPLETED, 'completed_at' => '2026-10-01 08:00:00'],
        ['calibration_id' => 2, 'instrument_id' => 2, 'instrument_type_id' => 2, 'calibration_date' => '2026-10-01', 'calibration_due' => '2026-11-06', 'calibration_method' => 'EXTERNAL', 'session_status' => Calibration::STATUS_COMPLETED, 'completed_at' => '2026-10-01 08:00:00'],
    ]);

    config([
        'calibration_due_notifications.enabled' => true,
        'calibration_due_notifications.recipients' => [
            'service.malang@vision-teknik.com',
            'indra_tri_sutanto@bat.com',
        ],
        'calibration_due_notifications.recipient' => null,
        'calibration_due_notifications.catch_up_days' => 0,
    ]);
}
