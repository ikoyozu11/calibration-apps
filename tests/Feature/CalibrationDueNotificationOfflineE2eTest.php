<?php

use App\CalibrationDueNotifications\CalibrationDueNotificationService;
use App\Mail\CalibrationDueReminderMail;
use App\Models\Calibration;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Support\NotificationE2eRecipientAllowlist;

const NOTIFICATION_E2E_DATE = '2026-11-06';

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

    config([
        'calibration_due_notifications.enabled' => true,
        'calibration_due_notifications.recipients' => [NotificationE2eRecipientAllowlist::ALLOWED_RECIPIENT],
        'calibration_due_notifications.recipient' => null,
        'calibration_due_notifications.catch_up_days' => 0,
        'calibration_due_notifications.max_retry_attempts' => 1,
        'mail.cc' => null,
        'mail.bcc' => null,
    ]);

    Carbon::setTestNow(CarbonImmutable::parse(NOTIFICATION_E2E_DATE.' 08:00:00', 'Asia/Jakarta'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('runs the existing Artisan command through the isolated one-reminder flow', function (): void {
    seedNotificationE2eFixture();
    $recipients = NotificationE2eRecipientAllowlist::assertEffectiveRecipients();
    Mail::fake();

    $this->artisan('calibration:send-due-reminders')
        ->assertExitCode(0);

    $mails = [];
    Mail::assertSent(CalibrationDueReminderMail::class, function (CalibrationDueReminderMail $mail) use (&$mails): bool {
        $mails[] = $mail;

        return true;
    });

    expect($recipients)->toBe([NotificationE2eRecipientAllowlist::ALLOWED_RECIPIENT])
        ->and($mails)->toHaveCount(1)
        ->and($mails[0]->reminders)->toHaveCount(1)
        ->and($mails[0]->reminders[0]->instrumentName)->toBe('E2E TEST INSTRUMENT - DO NOT ACTION')
        ->and($mails[0]->reminders[0]->serialNumber)->toBe('E2E-NOTIF-02-TEST')
        ->and($mails[0]->reminders[0]->milestoneCode)->toBe('due_today')
        ->and($mails[0]->reminders[0]->calibrationDue->toDateString())->toBe(NOTIFICATION_E2E_DATE)
        ->and($mails[0]->render())->toContain('E2E TEST INSTRUMENT - DO NOT ACTION')
        ->and($mails[0]->render())->toContain('E2E-NOTIF-02-TEST')
        ->and(DB::table('calibration_due_notification_batches')->count())->toBe(1)
        ->and(DB::table('calibration_due_notification_batches')->value('recipient'))
        ->toBe(NotificationE2eRecipientAllowlist::ALLOWED_RECIPIENT)
        ->and(DB::table('calibration_due_notification_batches')->value('status'))
        ->toBe(CalibrationDueNotificationService::STATUS_SENT)
        ->and(DB::table('calibration_due_notification_items')->count())->toBe(1)
        ->and(DB::table('calibration_due_notification_items')->value('milestone_code'))->toBe('due_today')
        ->and(DB::table('calibration_due_notification_items')->value('status'))
        ->toBe(CalibrationDueNotificationService::STATUS_SENT);
});

it('does not send a duplicate when the isolated command is invoked again', function (): void {
    seedNotificationE2eFixture();
    NotificationE2eRecipientAllowlist::assertEffectiveRecipients();
    Mail::fake();

    $this->artisan('calibration:send-due-reminders')->assertExitCode(0);
    $this->artisan('calibration:send-due-reminders')->assertExitCode(0);

    Mail::assertSent(CalibrationDueReminderMail::class, 1);
    expect(DB::table('calibration_due_notification_batches')->count())->toBe(1)
        ->and(DB::table('calibration_due_notification_items')->count())->toBe(1);
});

it('fails closed for unsafe effective recipient configurations', function (array $recipients, ?string $legacy): void {
    config([
        'calibration_due_notifications.recipients' => $recipients,
        'calibration_due_notifications.recipient' => $legacy,
    ]);
    Mail::fake();

    expect(fn (): array => NotificationE2eRecipientAllowlist::assertEffectiveRecipients())
        ->toThrow(InvalidArgumentException::class);
    Mail::assertNothingSent();
})->with([
    'operational second recipient' => [[
        NotificationE2eRecipientAllowlist::ALLOWED_RECIPIENT,
        'indra_tri_sutanto@bat.com',
    ], null],
    'additional recipient' => [[
        NotificationE2eRecipientAllowlist::ALLOWED_RECIPIENT,
        'unexpected@example.test',
    ], null],
    'duplicate recipient' => [[
        NotificationE2eRecipientAllowlist::ALLOWED_RECIPIENT,
        'SERVICE.MALANG@VISION-TEKNIK.COM',
    ], null],
    'empty configuration' => [[], null],
    'fallback recipient' => [[], NotificationE2eRecipientAllowlist::ALLOWED_RECIPIENT],
]);

function seedNotificationE2eFixture(): void
{
    DB::table('location')->insert([
        'location_id' => 1,
        'location_name' => 'E2E TEST LOCATION',
    ]);
    DB::table('detail_location')->insert([
        'detail_location_id' => 1,
        'location_id' => 1,
    ]);
    DB::table('instrument')->insert([
        'instrument_id' => 1,
        'instrument_name' => 'E2E TEST INSTRUMENT - DO NOT ACTION',
        'serial_number' => 'E2E-NOTIF-02-TEST',
        'detail_location_id' => 1,
    ]);
    DB::table('instrument_type')->insert([
        'instrument_type_id' => 1,
        'instrument_id' => 1,
        'type_name' => 'E2E TEST TYPE',
    ]);
    DB::table('calibration')->insert([
        'calibration_id' => 1,
        'instrument_id' => 1,
        'instrument_type_id' => 1,
        'calibration_date' => '2026-10-01',
        'calibration_due' => NOTIFICATION_E2E_DATE,
        'calibration_method' => 'INTERNAL',
        'session_status' => Calibration::STATUS_COMPLETED,
        'completed_at' => '2026-10-01 08:00:00',
    ]);
}
