<?php

use App\Models\Calibration;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\NotificationE2e03bLiveRunner;

if ($argc !== 3 || ! in_array($argv[1], ['preflight', 'send'], true)) {
    fwrite(STDERR, "Usage: php scripts/notif-e2e-03f-runner.php <preflight|send> <marker-path>\n");
    exit(2);
}

$mode = $argv[1];
$markerPath = $argv[2];

if (trim($markerPath) === '' || is_file($markerPath)) {
    fwrite(STDERR, "The canonical execution marker already exists or is invalid.\n");
    exit(3);
}

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'calibration_due_notifications.recipients' => ['service.malang@vision-teknik.com'],
    'calibration_due_notifications.recipient' => null,
    'mail.cc' => null,
    'mail.bcc' => null,
]);

createNotificationFixtureSchema();

$now = CarbonImmutable::now('Asia/Jakarta')->startOfDay()->setTime(8, 0);
$seed = static function () use ($now): void {
    seedNotificationFixture($now);
};
$runner = new NotificationE2e03bLiveRunner;

try {
    $result = $mode === 'preflight'
        ? $runner->preflight($seed, $now)
        : $runner->runAuthorized(
            NotificationE2e03bLiveRunner::AUTHORIZATION_TOKEN,
            NotificationE2e03bLiveRunner::AUTHORIZATION_TOKEN,
            $markerPath,
            $seed,
            $now,
        );

    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Guarded notification runner failed: '.$exception::class.PHP_EOL);
    exit(1);
}

function createNotificationFixtureSchema(): void
{
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
}

function seedNotificationFixture(CarbonImmutable $now): void
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
}
