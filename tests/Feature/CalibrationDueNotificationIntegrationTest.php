<?php

use App\CalibrationDueNotifications\CalibrationDueMilestoneCalculator;
use App\CalibrationDueNotifications\CalibrationDueNotificationService;
use App\CalibrationDueNotifications\CalibrationDueReminderSelector;
use App\CalibrationDueNotifications\CurrentCalibrationDueResolver;
use App\CalibrationSessions\CalibrationSessionConnection;
use App\Mail\CalibrationDueReminderMail;
use App\Models\Calibration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $database = getenv('CALIBRATION_NOTIFICATION_TEST_DATABASE');
    $host = getenv('CALIBRATION_NOTIFICATION_TEST_HOST') ?: '127.0.0.1';
    $port = getenv('CALIBRATION_NOTIFICATION_TEST_PORT') ?: '5432';
    $username = getenv('CALIBRATION_NOTIFICATION_TEST_USERNAME');
    $password = getenv('CALIBRATION_NOTIFICATION_TEST_PASSWORD');

    if (! is_string($database) || ! preg_match('/^calibration_notification_test_[a-f0-9]{12}$/', $database)) {
        throw new RuntimeException('The disposable notification test database is not configured.');
    }

    $_SERVER['CALIBRATION_NOTIFICATION_TEST_DATABASE'] = $database;

    config([
        'database.connections.notification_test' => [
            'driver' => 'pgsql',
            'url' => null,
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
        'calibration_due_notifications.enabled' => false,
        'calibration_due_notifications.recipient' => null,
        'calibration_due_notifications.timezone' => 'Asia/Jakarta',
        'calibration_due_notifications.catch_up_days' => 3,
        'calibration_due_notifications.max_retry_attempts' => 3,
    ]);

    DB::purge('notification_test');
    $connection = DB::connection('notification_test');

    expect($connection->scalar('select current_database()'))->toBe($database)
        ->and($connection->scalar('select inet_server_port()'))->toBe(5432);

    $connection->statement('TRUNCATE TABLE calibration_due_notification_items, calibration_due_notification_batches, calibration, instrument_type, instrument, detail_location, location RESTART IDENTITY CASCADE');
});

afterEach(function (): void {
    DB::purge('notification_test');
});

it('resolves only the latest completed calibration and suppresses older due dates', function (): void {
    $connection = notificationTestConnection();

    insertInstrumentPair($connection, 1, 1, 'Internal Gauge', 'INTERNAL');
    insertInstrumentPair($connection, 2, 2, 'External Gauge', 'EXTERNAL');

    insertCalibration($connection, 101, 1, 1, '2026-01-01', '2026-03-01', Calibration::STATUS_COMPLETED, '2026-01-01 08:00:00');
    insertCalibration($connection, 102, 1, 1, '2026-02-01', null, Calibration::STATUS_COMPLETED, '2026-02-01 08:00:00');
    insertCalibration($connection, 103, 1, 1, '2026-04-01', '2026-04-10', Calibration::STATUS_CANCELLED, '2026-04-01 08:00:00');
    insertCalibration($connection, 104, 1, 1, '2026-05-01', '2026-05-10', Calibration::STATUS_IN_PROGRESS, null);
    insertCalibration($connection, 201, 2, 2, '2026-11-01', '2026-11-09', Calibration::STATUS_COMPLETED, '2026-11-01 08:00:00');

    $sessionConnection = app(CalibrationSessionConnection::class);
    $resolved = (new CurrentCalibrationDueResolver($sessionConnection))->resolve($connection)->keyBy('instrument_type_id');

    expect($resolved[1]->latest_calibration_id)->toBe(102)
        ->and($resolved[1]->calibration_due)->toBeNull()
        ->and($resolved[2]->latest_calibration_id)->toBe(201)
        ->and($resolved[2]->calibration_due)->toBe('2026-11-09');

    $selected = (new CalibrationDueReminderSelector(
        new CurrentCalibrationDueResolver($sessionConnection),
        new CalibrationDueMilestoneCalculator,
    ))->select($connection, CarbonImmutable::parse('2026-11-06', 'Asia/Jakarta'));

    expect($selected)->toHaveCount(1)
        ->and($selected->first()->instrumentTypeId)->toBe(2)
        ->and($selected->first()->milestoneCode)->toBe('three_days_before');
});

it('sends one consolidated fake email and does not resend a sent batch', function (): void {
    $connection = notificationTestConnection();
    insertInstrumentPair($connection, 1, 1, 'Internal Gauge', 'INTERNAL');
    insertInstrumentPair($connection, 2, 2, 'External Gauge', 'EXTERNAL');
    insertCalibration($connection, 301, 1, 1, '2026-10-01', '2026-11-06', Calibration::STATUS_COMPLETED, '2026-10-01 08:00:00');
    insertCalibration($connection, 302, 2, 2, '2026-10-01', '2026-11-09', Calibration::STATUS_COMPLETED, '2026-10-01 08:00:00');

    config([
        'calibration_due_notifications.enabled' => true,
        'calibration_due_notifications.recipient' => 'qa@example.test',
    ]);
    Mail::fake();

    $sessionConnection = app(CalibrationSessionConnection::class);
    $service = new CalibrationDueNotificationService(
        new CalibrationDueReminderSelector(
            new CurrentCalibrationDueResolver($sessionConnection),
            new CalibrationDueMilestoneCalculator,
        ),
        $sessionConnection,
    );
    $now = CarbonImmutable::parse('2026-11-06 08:00:00', 'Asia/Jakarta');

    $first = $service->run($connection, $now);

    $sentMail = null;
    Mail::assertSent(CalibrationDueReminderMail::class, function (CalibrationDueReminderMail $mail) use (&$sentMail): bool {
        $sentMail = $mail;

        return true;
    });
    $html = $sentMail->render();

    expect($first)->toMatchArray(['status' => 'sent', 'reminder_count' => 3, 'sent_count' => 3])
        ->and($sentMail->reminders)->toHaveCount(3)
        ->and(collect($sentMail->reminders)->pluck('milestoneCode')->all())
        ->toContain('due_today')
        ->toContain('three_days_before')
        ->and($html)->toContain('Internal Gauge')
        ->and($html)->toContain('External Gauge')
        ->and($html)->not->toContain('.pdf')
        ->and($connection->table('calibration_due_notification_batches')->count())->toBe(1)
        ->and($connection->table('calibration_due_notification_items')->count())->toBe(3)
        ->and($connection->table('calibration_due_notification_items')->where('status', 'sent')->count())->toBe(3);

    $second = $service->run($connection, $now);

    Mail::assertSent(CalibrationDueReminderMail::class, 1);
    expect($second['status'])->toBe('already_sent');
});

it('runs the real notification command against the disposable connection', function (): void {
    $connection = notificationTestConnection();
    insertInstrumentPair($connection, 1, 1, 'Command Gauge', 'INTERNAL');
    insertCalibration($connection, 601, 1, 1, '2026-10-01', '2026-11-06', Calibration::STATUS_COMPLETED, '2026-10-01 08:00:00');

    config([
        'calibration_due_notifications.enabled' => true,
        'calibration_due_notifications.recipient' => 'qa@example.test',
    ]);
    Mail::fake();

    $this->artisan('calibration:send-due-reminders')
        ->assertExitCode(0);

    Mail::assertSent(CalibrationDueReminderMail::class, 1);
});

it('returns safely when the feature is disabled or the recipient is missing', function (): void {
    $connection = notificationTestConnection();
    Mail::fake();

    $sessionConnection = app(CalibrationSessionConnection::class);
    $service = new CalibrationDueNotificationService(
        app(CalibrationDueReminderSelector::class),
        $sessionConnection,
    );

    expect($service->run($connection, CarbonImmutable::parse('2026-11-06', 'Asia/Jakarta'))['status'])->toBe('disabled');

    config([
        'calibration_due_notifications.enabled' => true,
        'calibration_due_notifications.recipient' => null,
    ]);

    expect($service->run($connection, CarbonImmutable::parse('2026-11-06', 'Asia/Jakarta'))['status'])->toBe('recipient_missing');
    Mail::assertNothingSent();
});

it('marks stale processing as delivery unknown and never retries it automatically', function (): void {
    $connection = notificationTestConnection();
    insertInstrumentPair($connection, 1, 1, 'Stale Gauge', 'INTERNAL');
    insertCalibration($connection, 401, 1, 1, '2026-10-01', '2026-11-09', Calibration::STATUS_COMPLETED, '2026-10-01 08:00:00');
    insertNotificationBatch($connection, 'processing', '2026-11-06', 'qa@example.test', '2026-11-05 07:00:00');
    insertNotificationItem($connection, 1, 1, 401, '2026-11-09', 'three_days_before', '2026-11-06', 'processing', 1);

    config([
        'calibration_due_notifications.enabled' => true,
        'calibration_due_notifications.recipient' => 'qa@example.test',
    ]);
    Mail::fake();

    $service = app(CalibrationDueNotificationService::class);
    $result = $service->run($connection, CarbonImmutable::parse('2026-11-06 08:00:00', 'Asia/Jakarta'));

    expect($result['status'])->toBe(CalibrationDueNotificationService::STATUS_DELIVERY_UNKNOWN)
        ->and($connection->table('calibration_due_notification_batches')->value('status'))->toBe(CalibrationDueNotificationService::STATUS_DELIVERY_UNKNOWN)
        ->and($connection->table('calibration_due_notification_items')->value('status'))->toBe(CalibrationDueNotificationService::STATUS_DELIVERY_UNKNOWN);

    Mail::assertNothingSent();
    expect($service->run($connection, CarbonImmutable::parse('2026-11-06 08:01:00', 'Asia/Jakarta'))['status'])
        ->toBe(CalibrationDueNotificationService::STATUS_DELIVERY_UNKNOWN);
    Mail::assertNothingSent();
});

it('does not exceed the configured three attempts for failed history items', function (): void {
    $connection = notificationTestConnection();
    insertInstrumentPair($connection, 1, 1, 'Retry Gauge', 'INTERNAL');
    insertCalibration($connection, 451, 1, 1, '2026-10-01', '2026-11-06', Calibration::STATUS_COMPLETED, '2026-10-01 08:00:00');
    insertNotificationBatch($connection, 'pending', '2026-11-06', 'qa@example.test');
    insertNotificationItem($connection, 1, 1, 451, '2026-11-06', 'due_today', '2026-11-06', 'failed', 3);

    config([
        'calibration_due_notifications.enabled' => true,
        'calibration_due_notifications.recipient' => 'qa@example.test',
        'calibration_due_notifications.catch_up_days' => 0,
        'calibration_due_notifications.max_retry_attempts' => 3,
    ]);
    Mail::fake();

    $result = app(CalibrationDueNotificationService::class)
        ->run($connection, CarbonImmutable::parse('2026-11-06 08:00:00', 'Asia/Jakarta'));

    expect($result['status'])->toBe('already_processed')
        ->and($connection->table('calibration_due_notification_items')->value('attempts'))->toBe(3)
        ->and($connection->table('calibration_due_notification_items')->value('status'))->toBe('failed');
    Mail::assertNothingSent();
});

it('obsoletes a pending reminder when the current due date changes', function (): void {
    $connection = notificationTestConnection();
    insertInstrumentPair($connection, 1, 1, 'Changed Due Gauge', 'INTERNAL');
    insertCalibration($connection, 501, 1, 1, '2026-10-01', '2026-11-09', Calibration::STATUS_COMPLETED, '2026-10-01 08:00:00');
    insertNotificationBatch($connection, 'pending', '2026-11-07', 'qa@example.test');
    insertNotificationItem($connection, 1, 1, 501, '2026-11-09', 'three_days_before', '2026-11-06', 'pending');
    $connection->table('calibration')->where('calibration_id', 501)->update(['calibration_due' => '2026-11-10']);

    config([
        'calibration_due_notifications.enabled' => true,
        'calibration_due_notifications.recipient' => 'qa@example.test',
    ]);
    Mail::fake();

    $result = app(CalibrationDueNotificationService::class)
        ->run($connection, CarbonImmutable::parse('2026-11-07 08:00:00', 'Asia/Jakarta'));

    expect($result['status'])->toBe(CalibrationDueNotificationService::STATUS_SENT)
        ->and($connection->table('calibration_due_notification_items')->where('due_date_snapshot', '2026-11-09')->value('status'))
        ->toBe(CalibrationDueNotificationService::STATUS_OBSOLETE)
        ->and($connection->table('calibration_due_notification_items')->where('due_date_snapshot', '2026-11-10')->value('status'))
        ->toBe(CalibrationDueNotificationService::STATUS_SENT);
    Mail::assertSent(CalibrationDueReminderMail::class, 1);
});

function notificationTestConnection(): Connection
{
    return DB::connection('notification_test');
}

function insertInstrumentPair(Connection $connection, int $instrumentId, int $instrumentTypeId, string $name, string $method): void
{
    $connection->table('instrument')->insert([
        'instrument_id' => $instrumentId,
        'instrument_name' => $name,
        'serial_number' => "SN-$instrumentId",
        'detail_location_id' => null,
    ]);
    $connection->table('instrument_type')->insert([
        'instrument_type_id' => $instrumentTypeId,
        'instrument_id' => $instrumentId,
        'type_name' => $method,
    ]);
}

function insertCalibration(Connection $connection, int $id, int $instrumentId, int $instrumentTypeId, string $date, ?string $due, string $status, ?string $completedAt): void
{
    $connection->table('calibration')->insert([
        'calibration_id' => $id,
        'instrument_id' => $instrumentId,
        'instrument_type_id' => $instrumentTypeId,
        'calibration_date' => $date,
        'calibration_due' => $due,
        'calibration_method' => $instrumentTypeId === 2 ? 'EXTERNAL' : 'INTERNAL',
        'session_status' => $status,
        'completed_at' => $completedAt,
    ]);
}

function insertNotificationBatch(Connection $connection, string $status, string $date, string $recipient, ?string $claimedAt = null): void
{
    $connection->table('calibration_due_notification_batches')->insert([
        'notification_date' => $date,
        'timezone' => 'Asia/Jakarta',
        'recipient' => $recipient,
        'status' => $status,
        'attempts' => 1,
        'claimed_at' => $claimedAt,
    ]);
}

function insertNotificationItem(Connection $connection, int $batchId, int $instrumentId, int $calibrationId, string $due, string $milestone, string $milestoneDate, string $status, int $attempts = 0): void
{
    $connection->table('calibration_due_notification_items')->insert([
        'batch_id' => $batchId,
        'instrument_id' => $instrumentId,
        'instrument_type_id' => 1,
        'latest_calibration_id' => $calibrationId,
        'calibration_method' => 'INTERNAL',
        'instrument_name_snapshot' => 'Stale Gauge',
        'instrument_type_snapshot' => 'INTERNAL',
        'serial_number_snapshot' => 'SN-'.$instrumentId,
        'location_snapshot' => null,
        'due_date_snapshot' => $due,
        'milestone_code' => $milestone,
        'milestone_date' => $milestoneDate,
        'recipient' => 'qa@example.test',
        'status' => $status,
        'attempts' => $attempts,
    ]);
}
