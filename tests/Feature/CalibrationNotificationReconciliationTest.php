<?php

use App\Authorization\CalibrationNotificationRecipientResolver;
use App\CalibrationDueNotifications\AuthoritativeCalibrationPairEnumeration;
use App\CalibrationDueNotifications\AuthoritativeCalibrationPairEnumerator;
use App\CalibrationDueNotifications\CalibrationNotificationCandidate;
use App\CalibrationDueNotifications\CalibrationNotificationPairState;
use App\CalibrationDueNotifications\CalibrationNotificationPersistence;
use App\CalibrationDueNotifications\CalibrationNotificationReconciliationOrchestrator;
use App\CalibrationDueNotifications\CalibrationPairEnumerator;
use App\CalibrationDueNotifications\CalibrationPairStateDiscovery;
use App\Models\CalibrationNotification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    createReconciliationSchema();
    $this->connection = DB::connection();
    $this->recipient = User::query()->firstOrFail();
    $this->persistence = app(CalibrationNotificationPersistence::class);
});

it('enumerates every pair in stable keyset order and includes active stored pairs', function (): void {
    DB::table('instrument')->insert([
        'instrument_id' => 11,
        'instrument_name' => 'Second Instrument',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('instrument_type')->insert([
        'instrument_type_id' => 22,
        'instrument_id' => 11,
        'type_name' => 'Second Type',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('calibration_notifications')->insert(reconciliationNotificationRow([
        'instrument_id' => null,
        'instrument_type_id' => null,
        'event_key' => 'v1|instrument:99|type:88|cycle:30|due:2026-11-10|event:due_soon',
    ]));

    $result = (new AuthoritativeCalibrationPairEnumerator(1))->enumerate($this->connection);

    expect($result->complete)->toBeTrue()
        ->and($result->pairs->map(fn ($pair): string => $pair->key())->all())
        ->toBe(['10:20', '10:21', '11:22', '99:88']);
});

it('fails closed when an active event key is malformed or uses an unsupported version', function (): void {
    foreach ([
        'not-an-event-key',
        'v2|instrument:99|type:88|cycle:30|due:2026-11-10|event:due_soon',
        'v1|instrument:|type:88|cycle:30|due:2026-11-10|event:due_soon',
        'v1|instrument:99|type:88|cycle:30|due:2026-99-99|event:due_soon',
    ] as $eventKey) {
        DB::table('calibration_notifications')->insert(reconciliationNotificationRow([
            'instrument_id' => null,
            'instrument_type_id' => null,
            'event_key' => $eventKey,
        ]));

        $result = (new AuthoritativeCalibrationPairEnumerator)->enumerate($this->connection);

        expect($result->complete)->toBeFalse()
            ->and($result->failureCategory)->toBe('enumeration_failed');

        DB::table('calibration_notifications')->delete();
    }
});

it('applies a positive bounded chunk_size from configuration', function (): void {
    config()->set('calibration_notification_reconciliation.chunk_size', 2);
    expect(app(CalibrationPairEnumerator::class)->chunkSize)->toBe(2);

    config()->set('calibration_notification_reconciliation.chunk_size', 0);
    expect(app(CalibrationPairEnumerator::class)->chunkSize)
        ->toBe(AuthoritativeCalibrationPairEnumerator::DEFAULT_CHUNK_SIZE);

    config()->set('calibration_notification_reconciliation.chunk_size', 99999);
    expect(app(CalibrationPairEnumerator::class)->chunkSize)
        ->toBe(AuthoritativeCalibrationPairEnumerator::MAX_CHUNK_SIZE);
});

it('runs a genuinely read-only dry run', function (): void {
    $discovery = Mockery::mock(CalibrationPairStateDiscovery::class);
    $discovery->shouldReceive('discoverPair')
        ->andReturn(CalibrationNotificationPairState::fromCandidate(reconciliationCandidate()));

    $orchestrator = new CalibrationNotificationReconciliationOrchestrator(
        app(AuthoritativeCalibrationPairEnumerator::class),
        $discovery,
        $this->persistence,
        app(CalibrationNotificationRecipientResolver::class),
    );

    $report = $orchestrator->reconcile(
        $this->connection,
        CarbonImmutable::parse('2026-11-01', 'Asia/Jakarta'),
        dryRun: true,
    );

    expect($report->dryRun)->toBeTrue()
        ->and($report->succeeded())->toBeTrue()
        ->and($report->created)->toBe(0)
        ->and(CalibrationNotification::query()->count())->toBe(0);
});

it('creates due soon and overdue occurrences and remains idempotent', function (): void {
    $dueSoon = reconciliationCandidate([
        'instrumentTypeId' => 20,
        'eventType' => 'due_soon',
        'dueDate' => '2026-11-10',
    ]);
    $overdue = reconciliationCandidate([
        'instrumentTypeId' => 21,
        'eventType' => 'overdue',
        'dueDate' => '2026-10-31',
    ]);
    $discovery = Mockery::mock(CalibrationPairStateDiscovery::class);
    $discovery->shouldReceive('discoverPair')->andReturnUsing(
        fn ($connection, int $instrumentId, int $instrumentTypeId): CalibrationNotificationPairState => match ($instrumentTypeId) {
            20 => CalibrationNotificationPairState::fromCandidate($dueSoon),
            21 => CalibrationNotificationPairState::fromCandidate($overdue),
            default => CalibrationNotificationPairState::onTrack($instrumentId, $instrumentTypeId),
        },
    );
    $orchestrator = reconciliationOrchestrator($discovery, $this->persistence);

    $first = $orchestrator->reconcile($this->connection, CarbonImmutable::parse('2026-11-01', 'Asia/Jakarta'));
    $second = $orchestrator->reconcile($this->connection, CarbonImmutable::parse('2026-11-01', 'Asia/Jakarta'));

    expect($first->succeeded())->toBeTrue()
        ->and($first->created)->toBe(2)
        ->and($second->created)->toBe(0)
        ->and($second->alreadyCurrent)->toBe(2)
        ->and(CalibrationNotification::query()->count())->toBe(2);
});

it('resolves an on-track pair but does not resolve source-invalid state', function (): void {
    $this->persistence->reconcile([reconciliationCandidate()]);
    $discovery = Mockery::mock(CalibrationPairStateDiscovery::class);
    $discovery->shouldReceive('discoverPair')->andReturn(
        CalibrationNotificationPairState::onTrack(10, 20),
        CalibrationNotificationPairState::sourceInvalid(10, 21),
    );
    $orchestrator = reconciliationOrchestrator($discovery, $this->persistence);

    $report = $orchestrator->reconcile(
        $this->connection,
        CarbonImmutable::parse('2026-11-01', 'Asia/Jakarta'),
    );

    expect($report->resolved)->toBe(1)
        ->and($report->skippedInvalid)->toBe(1)
        ->and(CalibrationNotification::query()->whereNull('resolved_at')->count())->toBe(0);
});

it('isolates a pair discovery failure and reports an unsuccessful run', function (): void {
    $this->persistence->reconcile([reconciliationCandidate()]);
    $discovery = Mockery::mock(CalibrationPairStateDiscovery::class);
    $discovery->shouldReceive('discoverPair')->andReturnUsing(
        fn ($connection, int $instrumentId, int $instrumentTypeId): CalibrationNotificationPairState => $instrumentTypeId === 20
            ? throw new RuntimeException('source unavailable')
            : CalibrationNotificationPairState::onTrack($instrumentId, $instrumentTypeId),
    );
    $orchestrator = reconciliationOrchestrator($discovery, $this->persistence);

    $report = $orchestrator->reconcile(
        $this->connection,
        CarbonImmutable::parse('2026-11-01', 'Asia/Jakarta'),
    );

    expect($report->succeeded())->toBeFalse()
        ->and($report->failures[0]['instrument_id'])->toBe(10)
        ->and(CalibrationNotification::query()->whereNull('resolved_at')->count())->toBe(1);
});

it('does not process or resolve anything from an incomplete enumeration', function (): void {
    $this->persistence->reconcile([reconciliationCandidate()]);
    $enumerator = Mockery::mock(CalibrationPairEnumerator::class);
    $enumerator->shouldReceive('enumerate')->andReturn(
        AuthoritativeCalibrationPairEnumeration::incomplete('enumeration_failed'),
    );
    $discovery = Mockery::mock(CalibrationPairStateDiscovery::class);
    $discovery->shouldReceive('discoverPair')->never();
    $orchestrator = new CalibrationNotificationReconciliationOrchestrator(
        $enumerator,
        $discovery,
        $this->persistence,
        app(CalibrationNotificationRecipientResolver::class),
    );

    $report = $orchestrator->reconcile(
        $this->connection,
        CarbonImmutable::parse('2026-11-01', 'Asia/Jakarta'),
    );

    expect($report->complete)->toBeFalse()
        ->and($report->processed)->toBe(0)
        ->and(CalibrationNotification::query()->whereNull('resolved_at')->count())->toBe(1);
});

it('keeps reconciliation command and scheduler disabled by default', function (): void {
    expect(config('calibration_notification_reconciliation.enabled'))->toBeFalse();
});

it('keeps the reconciliation schedule conditional on the staging opt-in', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php');

    expect($source)->toContain('notificationReconciliationEnabled')
        ->and($source)->toContain("->dailyAt('08:00')")
        ->and($source)->toContain("->timezone((string) config('calibration_notification_reconciliation.timezone', 'Asia/Jakarta'))")
        ->and($source)->toContain('->withoutOverlapping()');
});

function reconciliationOrchestrator(
    CalibrationPairStateDiscovery $discovery,
    CalibrationNotificationPersistence $persistence,
): CalibrationNotificationReconciliationOrchestrator {
    return new CalibrationNotificationReconciliationOrchestrator(
        app(AuthoritativeCalibrationPairEnumerator::class),
        $discovery,
        $persistence,
        app(CalibrationNotificationRecipientResolver::class),
    );
}

function reconciliationCandidate(array $overrides = []): CalibrationNotificationCandidate
{
    $values = array_merge([
        'instrumentId' => 10,
        'instrumentTypeId' => 20,
        'sourceCalibrationId' => 30,
        'eventType' => 'due_soon',
        'dueDate' => '2026-11-10',
        'instrumentNameSnapshot' => 'Pressure Tester',
        'instrumentTypeSnapshot' => 'Drop Tester',
        'serialNumberSnapshot' => 'SN-10',
    ], $overrides);

    return new CalibrationNotificationCandidate(
        instrumentId: $values['instrumentId'],
        instrumentTypeId: $values['instrumentTypeId'],
        sourceCalibrationId: $values['sourceCalibrationId'],
        eventType: $values['eventType'],
        eventKey: sprintf(
            'v1|instrument:%d|type:%d|cycle:%d|due:%s|event:%s',
            $values['instrumentId'],
            $values['instrumentTypeId'],
            $values['sourceCalibrationId'],
            $values['dueDate'],
            $values['eventType'],
        ),
        dueDate: CarbonImmutable::parse($values['dueDate'], 'Asia/Jakarta'),
        instrumentNameSnapshot: $values['instrumentNameSnapshot'],
        instrumentTypeSnapshot: $values['instrumentTypeSnapshot'],
        serialNumberSnapshot: $values['serialNumberSnapshot'],
    );
}

function reconciliationNotificationRow(array $overrides = []): array
{
    return array_merge([
        'recipient_user_id' => User::query()->firstOrFail()->id,
        'instrument_id' => 10,
        'instrument_type_id' => 20,
        'source_calibration_id' => 30,
        'event_type' => 'due_soon',
        'event_key' => 'v1|instrument:10|type:20|cycle:30|due:2026-11-10|event:due_soon',
        'occurrence_revision' => 1,
        'instrument_name_snapshot' => 'Pressure Tester',
        'instrument_type_snapshot' => 'Drop Tester',
        'serial_number_snapshot' => 'SN-10',
        'due_date_snapshot' => '2026-11-10',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function createReconciliationSchema(): void
{
    Schema::dropIfExists('calibration_notifications');
    Schema::dropIfExists('calibration');
    Schema::dropIfExists('instrument_type');
    Schema::dropIfExists('instrument');

    Schema::create('instrument', function (Blueprint $table): void {
        $table->id('instrument_id');
        $table->string('instrument_name');
        $table->timestamps();
    });
    Schema::create('instrument_type', function (Blueprint $table): void {
        $table->id('instrument_type_id');
        $table->unsignedBigInteger('instrument_id');
        $table->string('type_name');
        $table->timestamps();
    });
    Schema::create('calibration', function (Blueprint $table): void {
        $table->id('calibration_id');
        $table->unsignedBigInteger('instrument_id');
        $table->unsignedBigInteger('instrument_type_id')->nullable();
        $table->string('session_status');
        $table->date('calibration_date')->nullable();
        $table->date('calibration_due')->nullable();
        $table->timestampTz('completed_at')->nullable();
        $table->timestampsTz();
    });
    Schema::create('calibration_notifications', function (Blueprint $table): void {
        $table->id('calibration_notification_id');
        $table->unsignedBigInteger('recipient_user_id');
        $table->unsignedBigInteger('instrument_id')->nullable();
        $table->unsignedBigInteger('instrument_type_id')->nullable();
        $table->unsignedBigInteger('source_calibration_id')->nullable();
        $table->string('event_type', 32);
        $table->string('event_key', 512);
        $table->unsignedSmallInteger('occurrence_revision')->default(1);
        $table->text('instrument_name_snapshot');
        $table->text('instrument_type_snapshot');
        $table->text('serial_number_snapshot')->nullable();
        $table->date('due_date_snapshot');
        $table->timestampTz('read_at')->nullable();
        $table->timestampTz('resolved_at')->nullable();
        $table->unsignedBigInteger('resolution_calibration_id')->nullable();
        $table->timestampTz('created_at')->useCurrent();
        $table->timestampTz('updated_at')->useCurrent();
        $table->unique(['recipient_user_id', 'event_key', 'occurrence_revision']);
        $table->foreign('recipient_user_id')->references('id')->on('users');
        $table->foreign('instrument_id')->references('instrument_id')->on('instrument')->nullOnDelete();
        $table->foreign('instrument_type_id')->references('instrument_type_id')->on('instrument_type')->nullOnDelete();
        $table->foreign('source_calibration_id')->references('calibration_id')->on('calibration')->nullOnDelete();
        $table->foreign('resolution_calibration_id')->references('calibration_id')->on('calibration')->nullOnDelete();
    });

    DB::table('instrument')->insert([
        'instrument_id' => 10,
        'instrument_name' => 'Pressure Tester',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    foreach ([20, 21] as $typeId) {
        DB::table('instrument_type')->insert([
            'instrument_type_id' => $typeId,
            'instrument_id' => 10,
            'type_name' => 'Drop Tester',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    DB::table('calibration')->insert([
        'calibration_id' => 30,
        'instrument_id' => 10,
        'instrument_type_id' => 20,
        'session_status' => 'completed',
        'calibration_date' => '2026-10-10',
        'calibration_due' => '2026-11-10',
        'completed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
