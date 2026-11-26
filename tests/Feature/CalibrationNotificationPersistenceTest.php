<?php

use App\Authorization\ApplicationRoleName;
use App\CalibrationDueNotifications\CalibrationNotificationCandidate;
use App\CalibrationDueNotifications\CalibrationNotificationConnection;
use App\CalibrationDueNotifications\CalibrationNotificationPairState;
use App\CalibrationDueNotifications\CalibrationNotificationPersistence;
use App\CalibrationDueNotifications\CalibrationNotificationReadModel;
use App\CalibrationDueNotifications\CalibrationNotificationReadModelData;
use App\Livewire\CalibrationNotificationCenter;
use App\Models\CalibrationNotification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function (): void {
    createNotificationPersistenceSchema();
    $this->connection = DB::connection();
    $this->recipient = User::query()->firstOrFail();
    app()->instance(
        CalibrationNotificationConnection::class,
        app(CalibrationNotificationConnection::class)->forIsolatedTest(DB::connection()),
    );
    $this->candidate = notificationPersistenceCandidate();
    $this->persistence = app(CalibrationNotificationPersistence::class);
});

it('creates the isolated notification schema with the required columns and uniqueness', function () {
    expect(Schema::hasTable('calibration_notifications'))->toBeTrue()
        ->and(Schema::hasColumns('calibration_notifications', [
            'calibration_notification_id',
            'recipient_user_id',
            'instrument_id',
            'instrument_type_id',
            'source_calibration_id',
            'event_type',
            'event_key',
            'instrument_name_snapshot',
            'instrument_type_snapshot',
            'serial_number_snapshot',
            'due_date_snapshot',
            'read_at',
            'resolved_at',
            'resolution_calibration_id',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

it('keeps the guarded SQL CHECK contract documented alongside the isolated fixture', function () {
    $sql = file_get_contents(base_path('database/sql/calibration_notifications.sql'));
    $reference = file_get_contents(base_path(
        'database/sql/reference/2026_11_23_000001_create_calibration_notifications.php',
    ));

    expect($sql)->toBeString()
        ->toContain('CHECK (event_type IN (\'due_soon\', \'overdue\'))')
        ->toContain('CHECK (length(trim(event_key)) > 0)')
        ->toContain('CHECK (occurrence_revision > 0)')
        ->toContain('CHECK (length(trim(instrument_name_snapshot)) > 0)')
        ->toContain('CHECK (length(trim(instrument_type_snapshot)) > 0)')
        ->and($reference)->toBeString()
        ->toContain("event_type IN ('due_soon', 'overdue')")
        ->toContain('length(trim(event_key)) > 0')
        ->toContain('occurrence_revision > 0')
        ->toContain('length(trim(instrument_name_snapshot)) > 0')
        ->toContain('length(trim(instrument_type_snapshot)) > 0');
});

it('persists one notification for each eligible recipient and candidate', function () {
    $result = $this->persistence->reconcile([$this->candidate]);

    expect($result['inserted'])->toBe(1)
        ->and(CalibrationNotification::query()->count())->toBe(1)
        ->and(CalibrationNotification::query()->first()->recipient_user_id)->toBe($this->recipient->id);
});

it('is idempotent and preserves read_at on repeated reconciliation', function () {
    $this->persistence->reconcile([$this->candidate]);
    $notification = CalibrationNotification::query()->firstOrFail();

    expect($this->persistence->markAsRead($this->recipient, $notification->getKey()))->toBeTrue();

    $readAt = $notification->fresh()->read_at;
    $result = $this->persistence->reconcile([$this->candidate]);

    expect($result['inserted'])->toBe(0)
        ->and($result['existing'])->toBe(1)
        ->and($notification->fresh()->read_at?->toIso8601String())->toBe($readAt?->toIso8601String())
        ->and(CalibrationNotification::query()->count())->toBe(1);
});

it('supports separate event keys for due soon, overdue, corrected dates, and new cycles', function () {
    $dueSoon = notificationPersistenceCandidate(['eventType' => 'due_soon', 'dueDate' => '2026-11-10']);
    $overdue = notificationPersistenceCandidate([
        'eventType' => 'overdue',
        'dueDate' => '2026-11-03',
    ]);
    $corrected = notificationPersistenceCandidate([
        'eventType' => 'due_soon',
        'dueDate' => '2026-11-12',
    ]);
    $newCycle = notificationPersistenceCandidate(['sourceCalibrationId' => 31]);

    $this->persistence->reconcile([$dueSoon, $overdue, $corrected, $newCycle]);

    expect(CalibrationNotification::query()->count())->toBe(4)
        ->and(CalibrationNotification::query()->pluck('event_key')->unique())->toHaveCount(4);
});

it('keeps multiple instrument types independent', function () {
    $secondType = notificationPersistenceCandidate(['instrumentTypeId' => 21]);

    $this->persistence->reconcile([$this->candidate, $secondType]);

    expect(CalibrationNotification::query()->count())->toBe(2)
        ->and(CalibrationNotification::query()->pluck('instrument_type_id')->sort()->values()->all())->toBe([20, 21]);
});

it('resolves stale occurrences only through an explicit authoritative pair operation', function () {
    $this->persistence->reconcile([$this->candidate]);
    $notification = CalibrationNotification::query()->firstOrFail();

    $resolved = $this->persistence->resolvePair(10, 20, 'current-event-key', 30);

    expect($resolved)->toBe(1)
        ->and($notification->fresh()->resolved_at)->not->toBeNull()
        ->and($notification->fresh()->read_at)->toBeNull();

    $this->persistence->reconcile([]);

    expect($notification->fresh()->resolved_at)->not->toBeNull()
        ->and($this->persistence->unreadCountFor($this->recipient))->toBe(0);
});

it('does not bulk-resolve a stored occurrence when a partial candidate set is empty', function () {
    $this->persistence->reconcile([$this->candidate]);
    $notification = CalibrationNotification::query()->firstOrFail();

    $this->persistence->reconcile([]);

    expect($notification->fresh()->resolved_at)->toBeNull()
        ->and($this->persistence->unreadCountFor($this->recipient))->toBe(1);
});

it('creates a new revision when a resolved historical event key re-enters', function () {
    $this->persistence->reconcile([$this->candidate]);
    $notification = CalibrationNotification::query()->firstOrFail();
    $this->persistence->markAsRead($this->recipient, $notification->getKey());
    $this->persistence->resolvePair(10, 20, 'different-current-key');
    $historical = $notification->fresh();

    $result = $this->persistence->reconcile([$this->candidate]);

    expect($result['inserted'])->toBe(1)
        ->and($result['revised'])->toBe(1)
        ->and(CalibrationNotification::query()->count())->toBe(2)
        ->and(CalibrationNotification::query()->whereNull('resolved_at')->value('occurrence_revision'))->toBe(2)
        ->and($historical->fresh()->read_at)->not->toBeNull()
        ->and($historical->fresh()->resolved_at)->not->toBeNull();

    $retry = $this->persistence->reconcile([$this->candidate]);

    expect($retry['inserted'])->toBe(0)
        ->and($retry['revised'])->toBe(0)
        ->and($retry['existing'])->toBe(1)
        ->and(CalibrationNotification::query()->count())->toBe(2);
});

it('reconciles due soon to overdue and resolves the obsolete active occurrence', function () {
    $this->persistence->reconcile([$this->candidate]);
    $overdue = notificationPersistenceCandidate([
        'eventType' => 'overdue',
        'dueDate' => '2026-11-03',
    ]);

    $result = $this->persistence->reconcileAuthoritativePair(CalibrationNotificationPairState::fromCandidate($overdue));

    expect($result['resolved'])->toBe(1)
        ->and($result['inserted'])->toBe(1)
        ->and(CalibrationNotification::query()->whereNull('resolved_at')->value('event_type'))->toBe('overdue');
});

it('reconciles due soon to on track without creating a replacement', function () {
    $this->persistence->reconcile([$this->candidate]);

    $result = $this->persistence->reconcileAuthoritativePair(CalibrationNotificationPairState::onTrack(10, 20));

    expect($result['resolved'])->toBe(1)
        ->and($result['inserted'])->toBe(0)
        ->and(CalibrationNotification::query()->whereNull('resolved_at')->count())->toBe(0);
});

it('resolves a corrected eligible due date before creating its replacement', function () {
    $this->persistence->reconcile([$this->candidate]);
    $corrected = notificationPersistenceCandidate(['dueDate' => '2026-11-12']);

    $result = $this->persistence->reconcileAuthoritativePair(
        CalibrationNotificationPairState::fromCandidate($corrected),
    );

    expect($result['resolved'])->toBe(1)
        ->and($result['inserted'])->toBe(1)
        ->and(CalibrationNotification::query()->whereNull('resolved_at')->value('event_key'))
        ->toBe($corrected->eventKey);
});

it('resolves the old cycle before creating a new completed calibration occurrence', function () {
    $this->persistence->reconcile([$this->candidate]);
    $newCycle = notificationPersistenceCandidate([
        'sourceCalibrationId' => 31,
        'dueDate' => '2027-11-10',
    ]);

    $result = $this->persistence->reconcileAuthoritativePair(
        CalibrationNotificationPairState::fromCandidate($newCycle),
    );

    expect($result['resolved'])->toBe(1)
        ->and($result['inserted'])->toBe(1)
        ->and(CalibrationNotification::query()->whereNull('resolved_at')->value('source_calibration_id'))
        ->toBe(31);
});

it('does not resolve or insert for a non-authoritative pair state', function () {
    $this->persistence->reconcile([$this->candidate]);

    $result = $this->persistence->reconcileAuthoritativePair(CalibrationNotificationPairState::sourceInvalid(10, 20));

    expect($result['status'])->toBe('not_authoritative')
        ->and($result['resolved'])->toBe(0)
        ->and(CalibrationNotification::query()->whereNull('resolved_at')->count())->toBe(1);
});

it('resolves a target-missing pair without creating a replacement', function () {
    $this->persistence->reconcile([$this->candidate]);

    $result = $this->persistence->reconcileAuthoritativePair(
        CalibrationNotificationPairState::targetMissing(10, 20),
    );

    expect($result['status'])->toBe('reconciled')
        ->and($result['resolved'])->toBe(1)
        ->and($result['inserted'])->toBe(0)
        ->and(CalibrationNotification::query()->whereNull('resolved_at')->count())->toBe(0);
});

it('rolls back a failed persistence transaction and can retry safely', function () {
    $invalid = notificationPersistenceCandidate([
        'sourceCalibrationId' => 999,
        'eventKey' => 'v1|instrument:10|type:20|cycle:999|due:2026-11-10|event:due_soon',
    ]);

    $failed = false;

    try {
        $this->persistence->reconcile([$invalid]);
    } catch (Throwable) {
        $failed = true;
    }

    expect($failed)->toBeTrue()
        ->and(CalibrationNotification::query()->count())->toBe(0);

    $retry = $this->persistence->reconcile([$this->candidate]);

    expect($retry['inserted'])->toBe(1)
        ->and(CalibrationNotification::query()->count())->toBe(1);
});

it('marks one notification read with recipient ownership enforcement', function () {
    $this->persistence->reconcile([$this->candidate]);
    $notification = CalibrationNotification::query()->firstOrFail();
    $otherUser = User::factory()->create();

    expect($this->persistence->markAsRead($otherUser, $notification->getKey()))->toBeFalse()
        ->and($notification->fresh()->read_at)->toBeNull()
        ->and($this->persistence->markAsRead($this->recipient, $notification->getKey()))->toBeTrue()
        ->and($notification->fresh()->read_at)->not->toBeNull();
});

it('marks all active notifications read and excludes resolved unread rows from the badge', function () {
    $second = notificationPersistenceCandidate(['instrumentTypeId' => 21]);
    $this->persistence->reconcile([$this->candidate, $second]);
    $resolved = CalibrationNotification::query()->where('instrument_type_id', 20)->firstOrFail();
    $resolved->forceFill(['resolved_at' => now()])->save();

    expect($this->persistence->unreadCountFor($this->recipient))->toBe(1)
        ->and($this->persistence->markAllActiveAsRead($this->recipient))->toBe(1)
        ->and($this->persistence->unreadCountFor($this->recipient))->toBe(0);
});

it('backfills a newly eligible user while excluding roleless users', function () {
    $this->persistence->reconcile([$this->candidate]);
    $viewer = User::factory()->create();
    actingAsRole(ApplicationRoleName::Viewer, $viewer);
    $roleless = User::factory()->create();

    $result = $this->persistence->reconcile([$this->candidate]);

    expect($result['inserted'])->toBe(1)
        ->and(CalibrationNotification::query()->where('recipient_user_id', $viewer->id)->count())->toBe(1)
        ->and(CalibrationNotification::query()->where('recipient_user_id', $roleless->id)->count())->toBe(0);
});

it('does not mutate calibration records while persisting notifications', function () {
    $before = dbNotificationCalibrationSnapshot();

    $this->persistence->reconcile([$this->candidate]);

    expect(dbNotificationCalibrationSnapshot())->toBe($before);
});

it('returns the latest five active notifications for the recipient', function () {
    $candidates = collect(range(1, 6))->map(fn (int $typeId): CalibrationNotificationCandidate => notificationPersistenceCandidate([
        'instrumentTypeId' => 20,
        'eventKey' => "manual-key-{$typeId}",
    ]));
    $this->persistence->reconcile($candidates);

    expect($this->persistence->latestActiveFor($this->recipient))->toHaveCount(5);
});

it('builds an authenticated read model with active unread count and stable latest ordering', function () {
    $first = notificationPersistenceCandidate(['eventKey' => 'read-model-first']);
    $second = notificationPersistenceCandidate([
        'instrumentTypeId' => 21,
        'eventKey' => 'read-model-second',
    ]);
    $this->persistence->reconcile([$first, $second]);

    $model = app(CalibrationNotificationReadModel::class);
    $snapshot = $model->snapshotForAuthenticatedUser();

    expect($snapshot->unreadCount)->toBe(2)
        ->and($snapshot->recipientName)->toBe($this->recipient->name)
        ->and($snapshot->recentNotifications)->toHaveCount(2)
        ->and($snapshot->recentNotifications[0]->targetAvailable)->toBeTrue()
        ->and($snapshot->recentNotifications[0]->targetUrl)->toContain('/instruments/10/types/');
});

it('uses the explicit notification connection for profile, notification, and target reads', function () {
    $this->persistence->reconcile([$this->candidate], $this->connection);
    $connectionNames = [];

    DB::listen(function (QueryExecuted $query) use (&$connectionNames): void {
        if (str_contains($query->sql, 'calibration_notifications')
            || str_contains($query->sql, 'instrument')) {
            $connectionNames[] = $query->connectionName;
        }
    });

    $snapshot = app(CalibrationNotificationReadModel::class)
        ->snapshotForAuthenticatedUser($this->connection);

    expect($snapshot->recentNotifications[0]->targetAvailable)->toBeTrue()
        ->and(array_unique($connectionNames))->toBe([$this->connection->getName()]);
});

it('excludes read and resolved notifications from the unread badge but keeps read active rows', function () {
    $second = notificationPersistenceCandidate(['instrumentTypeId' => 21]);
    $this->persistence->reconcile([$this->candidate, $second]);
    $read = CalibrationNotification::query()->where('instrument_type_id', 20)->firstOrFail();
    $resolved = CalibrationNotification::query()->where('instrument_type_id', 21)->firstOrFail();
    $this->persistence->markAsRead($this->recipient, $read->getKey());
    $resolved->forceFill(['resolved_at' => now()])->save();

    $model = app(CalibrationNotificationReadModel::class);
    $snapshot = $model->snapshotForAuthenticatedUser();

    expect($snapshot->unreadCount)->toBe(0)
        ->and($snapshot->recentNotifications)->toHaveCount(1)
        ->and($snapshot->recentNotifications[0]->isRead)->toBeTrue();
});

it('marks one and all active notifications only for the authenticated user', function () {
    $second = notificationPersistenceCandidate(['instrumentTypeId' => 21]);
    $this->persistence->reconcile([$this->candidate, $second]);
    $otherUser = User::factory()->create();
    $otherNotification = CalibrationNotification::query()->firstOrFail()->replicate();
    $otherNotification->forceFill([
        'recipient_user_id' => $otherUser->id,
        'event_key' => 'other-user-event',
    ])->save();

    $model = app(CalibrationNotificationReadModel::class);

    expect($model->markAsReadForAuthenticatedUser(999999))->toBeFalse()
        ->and($model->markAsReadForAuthenticatedUser((int) CalibrationNotification::query()
            ->where('recipient_user_id', $this->recipient->id)
            ->where('instrument_type_id', 20)
            ->value('calibration_notification_id')))->toBeTrue()
        ->and($model->markAsReadForAuthenticatedUser((int) $otherNotification->getKey()))->toBeFalse()
        ->and($model->markAllActiveAsReadForAuthenticatedUser())->toBe(1)
        ->and($otherNotification->fresh()->read_at)->toBeNull();
});

it('returns a fallback instrument target when the stored type target is missing', function () {
    $this->persistence->reconcile([$this->candidate]);
    CalibrationNotification::query()->update(['instrument_type_id' => null]);

    $model = app(CalibrationNotificationReadModel::class);
    $item = $model->snapshotForAuthenticatedUser()->recentNotifications[0];

    expect($item->targetAvailable)->toBeTrue()
        ->and($item->targetUrl)->toContain('/instruments/10');
});

it('does not produce a target for an invalid instrument type relationship', function () {
    DB::table('instrument')->insert([
        'instrument_id' => 11,
        'instrument_name' => 'Other Instrument',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('instrument_type')->insert([
        'instrument_type_id' => 22,
        'instrument_id' => 11,
        'type_name' => 'Other Type',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->persistence->reconcile([$this->candidate]);
    CalibrationNotification::query()->update(['instrument_type_id' => 22]);

    $model = app(CalibrationNotificationReadModel::class);
    $item = $model->snapshotForAuthenticatedUser()->recentNotifications[0];

    expect($item->targetAvailable)->toBeFalse()
        ->and($item->targetUrl)->toBeNull();
});

it('preserves notification snapshots and calibration data during read operations', function () {
    $this->persistence->reconcile([$this->candidate]);
    $before = dbNotificationCalibrationSnapshot();

    $model = app(CalibrationNotificationReadModel::class);
    $item = $model->snapshotForAuthenticatedUser()->recentNotifications[0];

    expect($item->instrumentName)->toBe('Pressure Tester')
        ->and($item->instrumentType)->toBe('Drop Tester')
        ->and(dbNotificationCalibrationSnapshot())->toBe($before);
});

it('denies the read model to an authenticated user without instruments view capability', function () {
    $this->persistence->reconcile([$this->candidate]);
    actingAsRolelessUser();

    $model = app(CalibrationNotificationReadModel::class);

    expect(fn (): CalibrationNotificationReadModelData => $model->snapshotForAuthenticatedUser())
        ->toThrow(AuthorizationException::class);
});

it('allows a Viewer to read notification targets without exposing calibration actions', function () {
    $this->persistence->reconcile([$this->candidate]);
    actingAsRole(ApplicationRoleName::Viewer, $this->recipient);

    $model = app(CalibrationNotificationReadModel::class);
    $snapshot = $model->snapshotForAuthenticatedUser();

    expect($snapshot->recipientRole)->toBe(ApplicationRoleName::Viewer->value)
        ->and($snapshot->recentNotifications[0]->targetAvailable)->toBeTrue();
});

it('keeps the notification center disabled by default without querying its table', function () {
    expect(config('calibration_notification_center.enabled'))->toBeFalse();

    DB::listen(function (QueryExecuted $query): void {
        expect($query->sql)->not->toContain('calibration_notifications');
    });

    Livewire::test(CalibrationNotificationCenter::class)
        ->assertSet('unreadCount', 0)
        ->assertSet('notifications', []);
});

it('renders and updates the enabled notification center through the read model', function () {
    config()->set('calibration_notification_center.enabled', true);
    $this->persistence->reconcile([$this->candidate]);

    $notification = CalibrationNotification::query()->firstOrFail();
    $component = Livewire::test(CalibrationNotificationCenter::class)
        ->assertSet('unreadCount', 1)
        ->assertSee('Notifications')
        ->assertSee('Calibration alerts')
        ->assertSee('Pressure Tester')
        ->assertSee('Drop Tester')
        ->assertSee('Due 10 Nov 2026')
        ->assertDontSee('SN-10')
        ->call('markAllAsRead')
        ->assertSet('unreadCount', 0);

    expect($notification->fresh()->read_at)->not->toBeNull();
    $component->call('openNotification', $notification->getKey())
        ->assertRedirect(route('instruments.types.show', [
            'instrument' => 10,
            'instrumentType' => 20,
        ]));
});

function notificationPersistenceCandidate(array $overrides = []): CalibrationNotificationCandidate
{
    $values = array_merge([
        'instrumentId' => 10,
        'instrumentTypeId' => 20,
        'sourceCalibrationId' => 30,
        'eventType' => 'due_soon',
        'eventKey' => 'v1|instrument:10|type:20|cycle:30|due:2026-11-10|event:due_soon',
        'dueDate' => '2026-11-10',
        'instrumentNameSnapshot' => 'Pressure Tester',
        'instrumentTypeSnapshot' => 'Drop Tester',
        'serialNumberSnapshot' => 'SN-10',
    ], $overrides);

    if (! array_key_exists('eventKey', $overrides)) {
        $values['eventKey'] = sprintf(
            'v1|instrument:%d|type:%d|cycle:%d|due:%s|event:%s',
            $values['instrumentId'],
            $values['instrumentTypeId'],
            $values['sourceCalibrationId'],
            $values['dueDate'],
            $values['eventType'],
        );
    }

    return new CalibrationNotificationCandidate(
        instrumentId: $values['instrumentId'],
        instrumentTypeId: $values['instrumentTypeId'],
        sourceCalibrationId: $values['sourceCalibrationId'],
        eventType: $values['eventType'],
        eventKey: $values['eventKey'],
        dueDate: CarbonImmutable::parse($values['dueDate'], 'Asia/Jakarta'),
        instrumentNameSnapshot: $values['instrumentNameSnapshot'],
        instrumentTypeSnapshot: $values['instrumentTypeSnapshot'],
        serialNumberSnapshot: $values['serialNumberSnapshot'],
    );
}

function dbNotificationCalibrationSnapshot(): array
{
    return json_decode(json_encode([
        'instrument' => DB::table('instrument')->get()->toArray(),
        'instrument_type' => DB::table('instrument_type')->get()->toArray(),
        'calibration' => DB::table('calibration')->get()->toArray(),
    ], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
}

function createNotificationPersistenceSchema(): void
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

    DB::table('instrument')->insert([
        'instrument_id' => 10,
        'instrument_name' => 'Pressure Tester',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([1, 2, 3, 4, 5, 6, 20, 21] as $instrumentTypeId) {
        DB::table('instrument_type')->insert([
            'instrument_type_id' => $instrumentTypeId,
            'instrument_id' => 10,
            'type_name' => "Drop Tester {$instrumentTypeId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

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
        $table->index(['recipient_user_id', 'resolved_at', 'read_at', 'created_at']);
        $table->foreign('recipient_user_id')->references('id')->on('users');
        $table->foreign('instrument_id')->references('instrument_id')->on('instrument')->nullOnDelete();
        $table->foreign('instrument_type_id')->references('instrument_type_id')->on('instrument_type')->nullOnDelete();
        $table->foreign('source_calibration_id')->references('calibration_id')->on('calibration')->nullOnDelete();
        $table->foreign('resolution_calibration_id')->references('calibration_id')->on('calibration')->nullOnDelete();
    });

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
    DB::table('calibration')->insert([
        'calibration_id' => 31,
        'instrument_id' => 10,
        'instrument_type_id' => 20,
        'session_status' => 'completed',
        'calibration_date' => '2027-10-10',
        'calibration_due' => '2027-11-10',
        'completed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
