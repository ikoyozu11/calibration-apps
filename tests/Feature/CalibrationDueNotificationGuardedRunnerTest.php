<?php

use App\Models\Calibration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mime\Email;
use Tests\Support\NotificationE2e03bExecutionMarker;
use Tests\Support\NotificationE2e03bLiveRunner;
use Tests\Support\NotificationE2eGuardedRunner;
use Tests\Support\NotificationE2eRecipientAllowlist;

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
        'calibration_due_notifications.recipients' => [NotificationE2eRecipientAllowlist::ALLOWED_RECIPIENT],
        'calibration_due_notifications.recipient' => null,
        'mail.cc' => null,
        'mail.bcc' => null,
    ]);
});

it('runs the guarded dry-run with a current Asia/Jakarta due date', function (): void {
    $now = CarbonImmutable::now('Asia/Jakarta')->startOfDay()->setTime(8, 0);
    $result = (new NotificationE2eGuardedRunner)->runDryRun(
        fn (): null => seedNotificationE2e03aFixture($now),
        $now,
    );

    expect($result)->toMatchArray([
        'exit_code' => 0,
        'recipient' => NotificationE2eRecipientAllowlist::ALLOWED_RECIPIENT,
        'reminder_count' => 1,
        'mail_count' => 1,
        'batch_count' => 1,
        'item_count' => 1,
        'status' => 'sent',
        'due_date' => $now->toDateString(),
        'milestone' => 'due_today',
    ]);
});

it('rejects the runner before opening a database when the default is unsafe', function (): void {
    $default = config('database.default');
    $sqlite = config('database.connections.sqlite');

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite' => [
            ...$sqlite,
            'database' => 'not-memory.sqlite',
        ],
    ]);

    try {
        expect(fn (): array => (new NotificationE2eGuardedRunner)->runDryRun(
            fn (): null => null,
            CarbonImmutable::now('Asia/Jakarta'),
        ))->toThrow(RuntimeException::class, 'SQLite :memory:');
    } finally {
        config([
            'database.default' => $default,
            'database.connections.sqlite' => $sqlite,
        ]);
    }
});

it('rejects a pre-existing notification history before dispatch', function (): void {
    DB::table('calibration_due_notification_batches')->insert([
        'notification_date' => CarbonImmutable::now('Asia/Jakarta')->toDateString(),
        'timezone' => 'Asia/Jakarta',
        'recipient' => NotificationE2eRecipientAllowlist::ALLOWED_RECIPIENT,
        'status' => 'sent',
        'attempts' => 1,
    ]);

    expect(fn (): array => (new NotificationE2eGuardedRunner)->runDryRun(
        fn (): null => null,
        CarbonImmutable::now('Asia/Jakarta'),
    ))->toThrow(RuntimeException::class, 'already contains notification history');
});

it('requires complete SMTP configuration before live preflight', function (): void {
    $now = CarbonImmutable::now('Asia/Jakarta')->startOfDay()->setTime(8, 0);

    expect(fn (): array => (new NotificationE2e03bLiveRunner)->preflight(
        fn (): null => seedNotificationE2e03aFixture($now),
        $now,
    ))->toThrow(RuntimeException::class, 'Laravel SMTP mailer');

    expect(DB::table('calibration')->count())->toBe(0);
});

it('decorates only the approved synthetic outgoing email envelope', function (): void {
    $message = (new Email)
        ->to(NotificationE2eRecipientAllowlist::ALLOWED_RECIPIENT)
        ->subject('Calibration Due Reminder')
        ->html('<p>E2E TEST INSTRUMENT - DO NOT ACTION</p>')
        ->text('E2E TEST INSTRUMENT - DO NOT ACTION');

    (new NotificationE2e03bLiveRunner)->prepareOutgoingMessage($message);

    expect($message->getSubject())->toStartWith('[TEST - DO NOT ACTION]')
        ->and($message->getHtmlBody())->toContain('No calibration action is required.')
        ->and($message->getTextBody())->toContain('NOTIF-E2E-03B TEST ONLY');
});

it('claims the live-send marker only once', function (): void {
    $directory = sys_get_temp_dir();
    $path = $directory.DIRECTORY_SEPARATOR.'notif-e2e-03b-'.bin2hex(random_bytes(8)).'.marker';
    $marker = new NotificationE2e03bExecutionMarker;

    try {
        $marker->claim($path);

        expect(function () use ($marker, $path): void {
            $marker->claim($path);
        })
            ->toThrow(RuntimeException::class, 'already exists');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

function seedNotificationE2e03aFixture(CarbonImmutable $now): null
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
        'calibration_date' => $now->subMonth()->toDateString(),
        'calibration_due' => $now->toDateString(),
        'calibration_method' => 'INTERNAL',
        'session_status' => Calibration::STATUS_COMPLETED,
        'completed_at' => $now->subMonth()->setTime(8, 0)->toDateTimeString(),
    ]);

    return null;
}
