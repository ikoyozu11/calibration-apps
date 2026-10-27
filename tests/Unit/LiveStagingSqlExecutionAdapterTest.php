<?php

use App\StagingSqlDeployment\ApprovedStagingSqlArtifacts;
use App\StagingSqlDeployment\AtomicStagingSqlPackage;
use App\StagingSqlDeployment\AtomicStagingSqlPackageBuilder;
use App\StagingSqlDeployment\FakeStagingSqlConnectionFactory;
use App\StagingSqlDeployment\FakeStagingSqlSession;
use App\StagingSqlDeployment\FixedGitRevisionReader;
use App\StagingSqlDeployment\LiveStagingSqlExecutionAdapter;
use App\StagingSqlDeployment\SealedPdoStagingSqlConnectionFactory;
use App\StagingSqlDeployment\StagingSqlDeploymentAuthorization;
use App\StagingSqlDeployment\StagingSqlDeploymentException;
use App\StagingSqlDeployment\StagingSqlDeploymentTarget;
use App\StagingSqlDeployment\StagingSqlExecutionOutcome;

function liveAdapterPackage(): AtomicStagingSqlPackage
{
    return (new AtomicStagingSqlPackageBuilder(
        repositoryRoot: dirname(__DIR__, 2),
        gitRevisionReader: new FixedGitRevisionReader(ApprovedStagingSqlArtifacts::APPROVED_HEAD),
    ))->build();
}

function liveAdapterAuth(?string $backupSha = null): StagingSqlDeploymentAuthorization
{
    return StagingSqlDeploymentAuthorization::scopedForApprovedRelease(
        $backupSha ?? ApprovedStagingSqlArtifacts::APPROVED_BACKUP_SHA256
    );
}

it('simulates a valid offline atomic deployment with one connection and one commit', function () {
    $factory = new FakeStagingSqlConnectionFactory;
    $adapter = new LiveStagingSqlExecutionAdapter($factory);
    $package = liveAdapterPackage();

    $outcome = $adapter->execute(
        $package,
        StagingSqlDeploymentTarget::approvedLocalStaging(),
        liveAdapterAuth(),
    );

    $session = $factory->session();

    expect($outcome->status)->toBe(StagingSqlExecutionOutcome::STATUS_COMMITTED)
        ->and($outcome->committed)->toBeTrue()
        ->and($outcome->openedPostgreSql)->toBeFalse()
        ->and($factory->connectAttempts)->toBe(1)
        ->and($session->beginCount)->toBe(1)
        ->and($session->commitCount)->toBe(1)
        ->and($session->rollbackCount)->toBe(0)
        ->and($session->closeCount)->toBe(1)
        ->and(count($session->executedStatements))->toBeGreaterThanOrEqual(3)
        ->and(implode("\n", $session->executedStatements))->toContain('CREATE TABLE public.roles')
        ->and(implode("\n", $session->executedStatements))->toContain('started_by_app_user_id')
        ->and(implode("\n", $session->executedStatements))->toContain('calibration session owner is immutable after insert')
        ->and($session->timeoutSettings)->not->toBeEmpty();
});

it('uses exactly three ordered SQL bodies with no top-level COMMIT', function () {
    $package = liveAdapterPackage();
    $bodies = $package->orderedBodySql();

    expect($bodies)->toHaveCount(3);
    foreach ($bodies as $body) {
        expect($body)->not->toMatch('/^\s*COMMIT\b/i');
    }
});

it('prevents connection when authorization is missing', function () {
    $factory = new FakeStagingSqlConnectionFactory;
    $adapter = new LiveStagingSqlExecutionAdapter($factory);

    $outcome = $adapter->execute(
        liveAdapterPackage(),
        StagingSqlDeploymentTarget::approvedLocalStaging(),
        StagingSqlDeploymentAuthorization::denied(),
    );

    expect($outcome->committed)->toBeFalse()
        ->and($factory->connectAttempts)->toBe(0)
        ->and($outcome->message)->toContain('write authorization');
});

it('prevents connection for the wrong database target', function () {
    $factory = new FakeStagingSqlConnectionFactory;
    $adapter = new LiveStagingSqlExecutionAdapter($factory);

    $outcome = $adapter->execute(
        liveAdapterPackage(),
        new StagingSqlDeploymentTarget('127.0.0.1', 5432, 'calibration_db'),
        liveAdapterAuth(),
    );

    expect($factory->connectAttempts)->toBe(0)
        ->and($outcome->message)->toContain('Production database is forbidden');
});

it('prevents connection for the maintenance database', function () {
    $factory = new FakeStagingSqlConnectionFactory;
    $adapter = new LiveStagingSqlExecutionAdapter($factory);

    $outcome = $adapter->execute(
        liveAdapterPackage(),
        new StagingSqlDeploymentTarget('127.0.0.1', 5432, 'postgres'),
        liveAdapterAuth(),
    );

    expect($factory->connectAttempts)->toBe(0)
        ->and($outcome->message)->toContain('Maintenance database is forbidden');
});

it('prevents connection for the wrong host', function () {
    $factory = new FakeStagingSqlConnectionFactory;
    $adapter = new LiveStagingSqlExecutionAdapter($factory);

    $outcome = $adapter->execute(
        liveAdapterPackage(),
        new StagingSqlDeploymentTarget('10.0.0.8', 5432, 'calibration_staging_db'),
        liveAdapterAuth(),
    );

    expect($factory->connectAttempts)->toBe(0)
        ->and($outcome->message)->toContain('host is not approved');
});

it('prevents connection when the backup hash mismatches', function () {
    $factory = new FakeStagingSqlConnectionFactory;
    $adapter = new LiveStagingSqlExecutionAdapter($factory);

    $outcome = $adapter->execute(
        liveAdapterPackage(),
        StagingSqlDeploymentTarget::approvedLocalStaging(),
        liveAdapterAuth('ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff'),
    );

    expect($factory->connectAttempts)->toBe(0)
        ->and($outcome->message)->toContain('Backup hash mismatch');
});

it('prevents connection when package artifact hashes are wrong', function () {
    $factory = new FakeStagingSqlConnectionFactory;
    $adapter = new LiveStagingSqlExecutionAdapter($factory);
    $ordered = ApprovedStagingSqlArtifacts::ordered();
    $ordered[0]['sha256'] = str_repeat('0', 64);

    expect(fn () => (new AtomicStagingSqlPackageBuilder(
        repositoryRoot: dirname(__DIR__, 2),
        gitRevisionReader: new FixedGitRevisionReader(ApprovedStagingSqlArtifacts::APPROVED_HEAD),
        artifacts: $ordered,
        requiredHead: ApprovedStagingSqlArtifacts::APPROVED_HEAD,
    ))->build())->toThrow(StagingSqlDeploymentException::class, 'SQL artifact hash mismatch');

    expect($factory->connectAttempts)->toBe(0);
});

it('prevents connection when Git HEAD is unexpected', function () {
    $factory = new FakeStagingSqlConnectionFactory;

    expect(fn () => (new AtomicStagingSqlPackageBuilder(
        repositoryRoot: dirname(__DIR__, 2),
        gitRevisionReader: new FixedGitRevisionReader(str_repeat('b', 40)),
    ))->build())->toThrow(StagingSqlDeploymentException::class, 'Git HEAD does not match');

    expect($factory->connectAttempts)->toBe(0);
});

it('prevents SQL execution when baseline fingerprint drifts', function () {
    $session = new FakeStagingSqlSession;
    $session->failBaselineFingerprint = true;
    $factory = new FakeStagingSqlConnectionFactory($session);
    $adapter = new LiveStagingSqlExecutionAdapter($factory);

    $outcome = $adapter->execute(
        liveAdapterPackage(),
        StagingSqlDeploymentTarget::approvedLocalStaging(),
        liveAdapterAuth(),
    );

    expect($factory->connectAttempts)->toBe(1)
        ->and($session->beginCount)->toBe(1)
        ->and($session->executedStatements)->toBe([])
        ->and($session->commitCount)->toBe(0)
        ->and($session->rollbackCount)->toBe(1)
        ->and($outcome->committed)->toBeFalse()
        ->and($outcome->message)->toContain('fingerprint');
});

it('prevents SQL execution when identity mismatches production', function () {
    $session = new FakeStagingSqlSession;
    $session->failOnConnectIdentity = true;
    $factory = new FakeStagingSqlConnectionFactory($session);
    $adapter = new LiveStagingSqlExecutionAdapter($factory);

    $outcome = $adapter->execute(
        liveAdapterPackage(),
        StagingSqlDeploymentTarget::approvedLocalStaging(),
        liveAdapterAuth(),
    );

    expect($session->executedStatements)->toBe([])
        ->and($session->commitCount)->toBe(0)
        ->and($session->rollbackCount)->toBe(1)
        ->and($outcome->message)->toContain('calibration_staging_db');
});

it('rolls back when SQL execution fails', function () {
    $session = new FakeStagingSqlSession;
    $session->failOnSqlContaining = true;
    $session->sqlFailureNeedle = 'ADD COLUMN started_by_app_user_id';
    $factory = new FakeStagingSqlConnectionFactory($session);
    $adapter = new LiveStagingSqlExecutionAdapter($factory);

    $outcome = $adapter->execute(
        liveAdapterPackage(),
        StagingSqlDeploymentTarget::approvedLocalStaging(),
        liveAdapterAuth(),
    );

    expect($outcome->status)->toBe(StagingSqlExecutionOutcome::STATUS_ROLLED_BACK)
        ->and($session->commitCount)->toBe(0)
        ->and($session->rollbackCount)->toBe(1)
        ->and($session->closeCount)->toBe(1)
        ->and($session->isOpen())->toBeFalse();
});

it('rolls back when post-DDL verification fails', function () {
    $session = new FakeStagingSqlSession;
    $session->failPostDdl = true;
    $factory = new FakeStagingSqlConnectionFactory($session);
    $adapter = new LiveStagingSqlExecutionAdapter($factory);

    $outcome = $adapter->execute(
        liveAdapterPackage(),
        StagingSqlDeploymentTarget::approvedLocalStaging(),
        liveAdapterAuth(),
    );

    expect($outcome->status)->toBe(StagingSqlExecutionOutcome::STATUS_VERIFICATION_FAILED)
        ->and($session->commitCount)->toBe(0)
        ->and($session->rollbackCount)->toBe(1)
        ->and($outcome->committed)->toBeFalse();
});

it('prevents COMMIT on statement timeout', function () {
    $session = new FakeStagingSqlSession;
    $session->timeoutBeforeCommit = true;
    $factory = new FakeStagingSqlConnectionFactory($session);
    $adapter = new LiveStagingSqlExecutionAdapter($factory);

    $outcome = $adapter->execute(
        liveAdapterPackage(),
        StagingSqlDeploymentTarget::approvedLocalStaging(),
        liveAdapterAuth(),
    );

    expect($session->commitCount)->toBe(0)
        ->and($session->rollbackCount)->toBe(1)
        ->and($outcome->committed)->toBeFalse()
        ->and($outcome->message)->toContain('timeout');
});

it('classifies ambiguous COMMIT without automatic retry', function () {
    $session = new FakeStagingSqlSession;
    $session->ambiguousCommit = true;
    $factory = new FakeStagingSqlConnectionFactory($session);
    $adapter = new LiveStagingSqlExecutionAdapter($factory);

    $first = $adapter->execute(
        liveAdapterPackage(),
        StagingSqlDeploymentTarget::approvedLocalStaging(),
        liveAdapterAuth(),
    );

    expect($first->status)->toBe(StagingSqlExecutionOutcome::STATUS_AMBIGUOUS_COMMIT)
        ->and($first->message)->toContain('No automatic retry')
        ->and($session->commitCount)->toBe(0);

    // Adapter does not retry internally; a second call is a separate operator action.
    $session2 = new FakeStagingSqlSession;
    $session2->ambiguousCommit = true;
    $factory2 = new FakeStagingSqlConnectionFactory($session2);
    $second = (new LiveStagingSqlExecutionAdapter($factory2))->execute(
        liveAdapterPackage(),
        StagingSqlDeploymentTarget::approvedLocalStaging(),
        liveAdapterAuth(),
    );

    expect($second->status)->toBe(StagingSqlExecutionOutcome::STATUS_AMBIGUOUS_COMMIT)
        ->and($factory2->connectAttempts)->toBe(1);
});

it('refuses sealed PDO factory connection acquisition', function () {
    $factory = new SealedPdoStagingSqlConnectionFactory;
    $adapter = new LiveStagingSqlExecutionAdapter($factory);

    $outcome = $adapter->execute(
        liveAdapterPackage(),
        StagingSqlDeploymentTarget::approvedLocalStaging(),
        liveAdapterAuth(),
    );

    expect($outcome->committed)->toBeFalse()
        ->and($outcome->openedPostgreSql)->toBeFalse()
        ->and($outcome->message)->toContain('sealed')
        ->and($factory->isUnsealed())->toBeFalse();

    expect(fn () => $factory->unsealForAuthorizedCheckpoint(StagingSqlDeploymentAuthorization::APPROVED_CHECKPOINT))
        ->toThrow(StagingSqlDeploymentException::class, 'remains sealed until the 21Z-D entry point');
});

it('forbids default DB fallback and never invokes PDO in fake mode', function () {
    $factory = new FakeStagingSqlConnectionFactory;
    $adapter = new LiveStagingSqlExecutionAdapter($factory);

    $outcome = $adapter->execute(
        liveAdapterPackage(),
        StagingSqlDeploymentTarget::approvedLocalStaging(),
        liveAdapterAuth(),
    );

    expect($outcome->openedPostgreSql)->toBeFalse();

    $liveSource = file_get_contents(dirname(__DIR__, 2).'/app/StagingSqlDeployment/LiveStagingSqlExecutionAdapter.php');
    $fakeSource = file_get_contents(dirname(__DIR__, 2).'/app/StagingSqlDeployment/FakeStagingSqlSession.php');

    expect($liveSource)->not->toContain('new PDO')
        ->and($liveSource)->not->toContain('DB::connection')
        ->and($fakeSource)->not->toContain('new PDO')
        ->and($fakeSource)->not->toContain('pgsql:');
});
