<?php

use App\CalibrationSessions\CalibrationSessionConnection;
use App\StagingSqlDeployment\ApprovedStagingSqlArtifacts;
use App\StagingSqlDeployment\AtomicStagingSqlPackageBuilder;
use App\StagingSqlDeployment\ContractStagingSqlExecutionSimulator;
use App\StagingSqlDeployment\DeltaAtomicStagingSqlPackageBuilder;
use App\StagingSqlDeployment\DeltaStagingSqlDeploymentGateValidator;
use App\StagingSqlDeployment\DeltaStagingSqlReleaseApproval;
use App\StagingSqlDeployment\DeltaStagingSqlReleaseManifest;
use App\StagingSqlDeployment\DisabledStagingSqlExecutionAdapter;
use App\StagingSqlDeployment\FakeStagingSqlConnectionFactory;
use App\StagingSqlDeployment\FixedGitRevisionReader;
use App\StagingSqlDeployment\NotificationStagingSqlApprovalAuthority;
use App\StagingSqlDeployment\NotificationStagingSqlApprovalClaims;
use App\StagingSqlDeployment\NotificationStagingSqlApprovalManifest;
use App\StagingSqlDeployment\NotificationStagingSqlApprovalManifestReader;
use App\StagingSqlDeployment\NotificationStagingSqlApprovalReplayProtection;
use App\StagingSqlDeployment\NotificationStagingSqlApprovalReservation;
use App\StagingSqlDeployment\NotificationStagingSqlBackupVerifier;
use App\StagingSqlDeployment\NotificationStagingSqlDeploymentAdapter;
use App\StagingSqlDeployment\NotificationStagingSqlDeploymentEvidence;
use App\StagingSqlDeployment\NotificationStagingSqlEd25519ApprovalAuthority;
use App\StagingSqlDeployment\NotificationStagingSqlExecutionExecutor;
use App\StagingSqlDeployment\NotificationStagingSqlExecutionOutcome;
use App\StagingSqlDeployment\NotificationStagingSqlExecutionPlan;
use App\StagingSqlDeployment\NotificationStagingSqlExecutionVerifier;
use App\StagingSqlDeployment\NotificationStagingSqlPackage;
use App\StagingSqlDeployment\NotificationStagingSqlPackageBuilder;
use App\StagingSqlDeployment\NotificationStagingSqlPostDeploymentVerifier;
use App\StagingSqlDeployment\NotificationStagingSqlPreDeploymentVerifier;
use App\StagingSqlDeployment\NotificationStagingSqlReadOnlyMetadataReader;
use App\StagingSqlDeployment\NotificationStagingSqlSchemaInspection;
use App\StagingSqlDeployment\NotificationStagingSqlSchemaInspector;
use App\StagingSqlDeployment\NotificationStagingSqlSchemaPreflight;
use App\StagingSqlDeployment\NotificationStagingSqlTrustedApproval;
use App\StagingSqlDeployment\NotificationStagingSqlTrustedVerificationKeyProvider;
use App\StagingSqlDeployment\PostgreSqlNotificationStagingSqlMetadataReader;
use App\StagingSqlDeployment\StagingSqlDeploymentAuthorization;
use App\StagingSqlDeployment\StagingSqlDeploymentException;
use App\StagingSqlDeployment\StagingSqlDeploymentGateValidator;
use App\StagingSqlDeployment\StagingSqlDeploymentTarget;
use App\StagingSqlDeployment\StagingSqlExecutionOutcome;
use App\StagingSqlDeployment\StagingSqlScriptParser;
use App\StagingSqlDeployment\StagingSqlSession;
use Illuminate\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

function stagingSqlRepoRoot(): string
{
    return dirname(__DIR__, 2);
}

function stagingSqlApprovedAuth(string $backupSha = ApprovedStagingSqlArtifacts::APPROVED_BACKUP_SHA256): StagingSqlDeploymentAuthorization
{
    return StagingSqlDeploymentAuthorization::scopedForApprovedRelease($backupSha);
}

function stagingSqlWriteTempArtifact(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), '21za_sql_');
    expect($path)->toBeString();
    file_put_contents($path, $contents);

    return $path;
}

function stagingSqlBuilderForTempFiles(array $absolutePaths, array $hashes, string $head = ApprovedStagingSqlArtifacts::APPROVED_HEAD): AtomicStagingSqlPackageBuilder
{
    $artifacts = [];
    foreach ($absolutePaths as $index => $absolute) {
        $artifacts[] = [
            'relative_path' => $absolute,
            'sha256' => $hashes[$index],
        ];
    }

    return new AtomicStagingSqlPackageBuilder(
        repositoryRoot: '',
        gitRevisionReader: new FixedGitRevisionReader($head),
        parser: new StagingSqlScriptParser,
        artifacts: $artifacts,
        requiredHead: ApprovedStagingSqlArtifacts::APPROVED_HEAD,
    );
}

it('creates a valid atomic package from the three approved artifacts', function () {
    $builder = new AtomicStagingSqlPackageBuilder(
        repositoryRoot: stagingSqlRepoRoot(),
        gitRevisionReader: new FixedGitRevisionReader(ApprovedStagingSqlArtifacts::APPROVED_HEAD),
    );

    $package = $builder->build();
    $bodies = $package->orderedBodySql();
    $gates = new StagingSqlDeploymentGateValidator;

    expect($package->artifacts)->toHaveCount(3)
        ->and($bodies)->toHaveCount(3)
        ->and($package->orderedRelativePaths())->toBe([
            'database/sql/roles.sql',
            'database/sql/calibration_started_by_app_user.sql',
            'database/sql/calibration_started_by_immutability_guard.sql',
        ])
        ->and($bodies[0])->toContain('CREATE TABLE public.roles')
        ->and($bodies[1])->toContain('started_by_app_user_id')
        ->and($bodies[2])->toContain('calibration session owner is immutable after insert')
        ->and(method_exists($package, 'transactionalSql'))->toBeFalse();

    $gates->assertPackageReady($package);
    $gates->assertBodiesContainNoTopLevelCommit($bodies);
});

it('exposes ordered bodies without embedded top-level COMMIT', function () {
    $package = (new AtomicStagingSqlPackageBuilder(
        repositoryRoot: stagingSqlRepoRoot(),
        gitRevisionReader: new FixedGitRevisionReader(ApprovedStagingSqlArtifacts::APPROVED_HEAD),
    ))->build();

    (new StagingSqlDeploymentGateValidator)->assertBodiesContainNoTopLevelCommit($package->orderedBodySql());

    expect(count($package->orderedBodySql()))->toBe(3);
});

it('rejects a source hash mismatch', function () {
    $path = stagingSqlWriteTempArtifact("BEGIN;\nSELECT 1;\nCOMMIT;\n");
    $builder = stagingSqlBuilderForTempFiles([$path, $path, $path], [
        'deadbeef',
        hash_file('sha256', $path),
        hash_file('sha256', $path),
    ]);

    expect(fn () => $builder->build())
        ->toThrow(StagingSqlDeploymentException::class, 'SQL artifact hash mismatch');
});

it('rejects an unexpected Git HEAD', function () {
    $builder = new AtomicStagingSqlPackageBuilder(
        repositoryRoot: stagingSqlRepoRoot(),
        gitRevisionReader: new FixedGitRevisionReader(str_repeat('a', 40)),
    );

    expect(fn () => $builder->build())
        ->toThrow(StagingSqlDeploymentException::class, 'Git HEAD does not match');
});

it('rejects wrong artifact order at the gate', function () {
    $ordered = ApprovedStagingSqlArtifacts::ordered();
    $builder = new AtomicStagingSqlPackageBuilder(
        repositoryRoot: stagingSqlRepoRoot(),
        gitRevisionReader: new FixedGitRevisionReader(ApprovedStagingSqlArtifacts::APPROVED_HEAD),
        artifacts: [$ordered[1], $ordered[0], $ordered[2]],
        requiredHead: ApprovedStagingSqlArtifacts::APPROVED_HEAD,
    );

    $package = $builder->build();

    expect(fn () => (new StagingSqlDeploymentGateValidator)->assertPackageReady($package))
        ->toThrow(StagingSqlDeploymentException::class, 'Wrong staging SQL artifact order');
});

it('ignores SQL comments while extracting wrappers', function () {
    $sql = <<<'SQL'
-- leading comment with BEGIN; COMMIT; words
BEGIN;
/* block comment BEGIN; COMMIT; */
SELECT 1;
COMMIT;
SQL;
    $body = (new StagingSqlScriptParser)->stripOuterTransactionWrapper($sql);

    expect($body)->toBe('SELECT 1;')
        ->and($body)->not->toContain('COMMIT');
});

it('preserves dollar-quoted function bodies with nested BEGIN/END', function () {
    $sql = <<<'SQL'
BEGIN;
CREATE OR REPLACE FUNCTION public.demo()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.id IS NULL THEN
        RAISE EXCEPTION 'x';
    END IF;
    RETURN NEW;
END;
$$;
COMMIT;
SQL;
    $body = (new StagingSqlScriptParser)->stripOuterTransactionWrapper($sql);

    expect($body)->toContain('AS $$')
        ->and($body)->toContain("BEGIN\n    IF NEW.id IS NULL THEN")
        ->and($body)->toContain('END;')
        ->and(substr_count(strtoupper($body), 'COMMIT'))->toBe(0);
});

it('allows nested BEGIN/END inside DO dollar quotes', function () {
    $sql = <<<'SQL'
BEGIN;
DO $guard$
BEGIN
    PERFORM 1;
END;
$guard$;
COMMIT;
SQL;
    $body = (new StagingSqlScriptParser)->stripOuterTransactionWrapper($sql);

    expect($body)->toContain('DO $guard$')
        ->and($body)->toContain('PERFORM 1;');
});

it('rejects an unexpected top-level COMMIT in the body', function () {
    $sql = <<<'SQL'
BEGIN;
SELECT 1;
COMMIT;
SELECT 2;
COMMIT;
SQL;

    expect(fn () => (new StagingSqlScriptParser)->stripOuterTransactionWrapper($sql))
        ->toThrow(StagingSqlDeploymentException::class);
});

it('rejects a missing transaction wrapper', function () {
    expect(fn () => (new StagingSqlScriptParser)->stripOuterTransactionWrapper("SELECT 1;\n"))
        ->toThrow(StagingSqlDeploymentException::class, 'missing a transaction wrapper')
        ->and(fn () => (new StagingSqlScriptParser)->stripOuterTransactionWrapper("SELECT 1;\nSELECT 2;\n"))
        ->toThrow(StagingSqlDeploymentException::class, 'must start with a top-level BEGIN');
});

it('rejects a duplicate top-level BEGIN wrapper', function () {
    $sql = <<<'SQL'
BEGIN;
BEGIN;
SELECT 1;
COMMIT;
SQL;

    expect(fn () => (new StagingSqlScriptParser)->stripOuterTransactionWrapper($sql))
        ->toThrow(StagingSqlDeploymentException::class, 'Nested or unexpected top-level transaction control');
});

it('rejects unsupported transaction-incompatible SQL', function () {
    $sql = <<<'SQL'
BEGIN;
CREATE INDEX CONCURRENTLY ix_demo ON public.demo (id);
COMMIT;
SQL;

    expect(fn () => (new StagingSqlScriptParser)->stripOuterTransactionWrapper($sql))
        ->toThrow(StagingSqlDeploymentException::class, 'Unsupported or transaction-incompatible');
});

it('rejects a wrong database target', function () {
    $gates = new StagingSqlDeploymentGateValidator;

    expect(fn () => $gates->assertTargetAllowed(new StagingSqlDeploymentTarget('127.0.0.1', 5432, 'calibration_db')))
        ->toThrow(StagingSqlDeploymentException::class, 'Production database is forbidden')
        ->and(fn () => $gates->assertTargetAllowed(new StagingSqlDeploymentTarget('127.0.0.1', 5432, 'postgres')))
        ->toThrow(StagingSqlDeploymentException::class, 'Maintenance database is forbidden')
        ->and(fn () => $gates->assertTargetAllowed(new StagingSqlDeploymentTarget('127.0.0.1', 5432, 'other_db')))
        ->toThrow(StagingSqlDeploymentException::class, 'database is not approved');
});

it('rejects a wrong host', function () {
    expect(fn () => (new StagingSqlDeploymentGateValidator)->assertTargetAllowed(
        new StagingSqlDeploymentTarget('10.0.0.5', 5432, 'calibration_staging_db')
    ))->toThrow(StagingSqlDeploymentException::class, 'host is not approved');
});

it('rejects missing backup approval', function () {
    $auth = new StagingSqlDeploymentAuthorization(
        operatorWriteApproved: true,
        backupBaselineVerified: false,
        freshSchemaInventoryVerified: true,
        freshTriggerFingerprintVerified: true,
        operatorConfirmedExecution: true,
        approvedBackupSha256: '',
    );

    expect(fn () => (new StagingSqlDeploymentGateValidator)->assertAuthorization($auth))
        ->toThrow(StagingSqlDeploymentException::class, 'Missing verified backup baseline');
});

it('rejects baseline trigger fingerprint drift', function () {
    $gates = new StagingSqlDeploymentGateValidator;

    expect(fn () => $gates->assertBaselineTriggerFingerprint(
        ApprovedStagingSqlArtifacts::APPROVED_BASELINE_TRIGGER_MD5,
        'ffffffffffffffffffffffffffffffff',
        ApprovedStagingSqlArtifacts::APPROVED_BASELINE_TRIGGER_LENGTH,
        ApprovedStagingSqlArtifacts::APPROVED_BASELINE_TRIGGER_LENGTH,
    ))->toThrow(StagingSqlDeploymentException::class, 'Baseline trigger fingerprint drift detected');
});

it('accepts matching baseline trigger fingerprint metadata', function () {
    (new StagingSqlDeploymentGateValidator)->assertBaselineTriggerFingerprint(
        ApprovedStagingSqlArtifacts::APPROVED_BASELINE_TRIGGER_MD5,
        ApprovedStagingSqlArtifacts::APPROVED_BASELINE_TRIGGER_MD5,
        ApprovedStagingSqlArtifacts::APPROVED_BASELINE_TRIGGER_LENGTH,
        ApprovedStagingSqlArtifacts::APPROVED_BASELINE_TRIGGER_LENGTH,
    );

    expect(true)->toBeTrue();
});

it('rejects missing write authorization', function () {
    expect(fn () => (new StagingSqlDeploymentGateValidator)->assertAuthorization(StagingSqlDeploymentAuthorization::denied()))
        ->toThrow(StagingSqlDeploymentException::class, 'Missing explicit write authorization');
});

it('models verification failure without committing', function () {
    $package = (new AtomicStagingSqlPackageBuilder(
        repositoryRoot: stagingSqlRepoRoot(),
        gitRevisionReader: new FixedGitRevisionReader(ApprovedStagingSqlArtifacts::APPROVED_HEAD),
    ))->build();

    $outcome = (new ContractStagingSqlExecutionSimulator)->simulateVerificationFailure(
        $package,
        StagingSqlDeploymentTarget::approvedLocalStaging(),
        stagingSqlApprovedAuth(),
    );

    expect($outcome->status)->toBe(StagingSqlExecutionOutcome::STATUS_VERIFICATION_FAILED)
        ->and($outcome->committed)->toBeFalse()
        ->and($outcome->openedPostgreSql)->toBeFalse();
});

it('models SQL error rollback without committing', function () {
    $package = (new AtomicStagingSqlPackageBuilder(
        repositoryRoot: stagingSqlRepoRoot(),
        gitRevisionReader: new FixedGitRevisionReader(ApprovedStagingSqlArtifacts::APPROVED_HEAD),
    ))->build();

    $outcome = (new ContractStagingSqlExecutionSimulator)->simulateSqlError(
        $package,
        StagingSqlDeploymentTarget::approvedLocalStaging(),
        stagingSqlApprovedAuth(),
    );

    expect($outcome->status)->toBe(StagingSqlExecutionOutcome::STATUS_ROLLED_BACK)
        ->and($outcome->committed)->toBeFalse()
        ->and($outcome->openedPostgreSql)->toBeFalse();
});

it('models ambiguous COMMIT without blind retry authorization', function () {
    $package = (new AtomicStagingSqlPackageBuilder(
        repositoryRoot: stagingSqlRepoRoot(),
        gitRevisionReader: new FixedGitRevisionReader(ApprovedStagingSqlArtifacts::APPROVED_HEAD),
    ))->build();

    $outcome = (new ContractStagingSqlExecutionSimulator)->simulateAmbiguousCommit(
        $package,
        StagingSqlDeploymentTarget::approvedLocalStaging(),
        stagingSqlApprovedAuth(),
    );

    expect($outcome->status)->toBe(StagingSqlExecutionOutcome::STATUS_AMBIGUOUS_COMMIT)
        ->and($outcome->committed)->toBeFalse()
        ->and($outcome->message)->toContain('no blind retry');
});

it('forbids default database connection fallback', function () {
    expect(fn () => (new StagingSqlDeploymentGateValidator)->assertNoDefaultConnectionFallback(true))
        ->toThrow(StagingSqlDeploymentException::class, 'Default database connection fallback is forbidden');
});

it('does not invoke a PostgreSQL connector in offline disabled mode', function () {
    $package = (new AtomicStagingSqlPackageBuilder(
        repositoryRoot: stagingSqlRepoRoot(),
        gitRevisionReader: new FixedGitRevisionReader(ApprovedStagingSqlArtifacts::APPROVED_HEAD),
    ))->build();

    $outcome = (new DisabledStagingSqlExecutionAdapter)->execute(
        $package,
        StagingSqlDeploymentTarget::approvedLocalStaging(),
        stagingSqlApprovedAuth(),
    );

    expect($outcome->status)->toBe(StagingSqlExecutionOutcome::STATUS_DISABLED)
        ->and($outcome->openedPostgreSql)->toBeFalse()
        ->and($outcome->committed)->toBeFalse()
        ->and(class_exists(PDO::class))->toBeTrue();

    // Guard: disabled adapter source must not construct PDO / DB facades.
    $source = file_get_contents(dirname(__DIR__, 2).'/app/StagingSqlDeployment/DisabledStagingSqlExecutionAdapter.php');
    expect($source)->not->toContain('PDO')
        ->and($source)->not->toContain('DB::')
        ->and($source)->not->toContain('pg_connect')
        ->and($source)->not->toContain('pgsql:');
});

it('builds the delta release with exactly one notification-history artifact', function () {
    $manifest = DeltaStagingSqlReleaseManifest::pending(stagingSqlRepoRoot());
    $approval = new DeltaStagingSqlReleaseApproval(
        releaseId: $manifest['release_id'],
        approvedHead: str_repeat('b', 40),
        checkpoint: 'delta-test-checkpoint',
        freshBackupSha256: str_repeat('c', 64),
        artifactSha256: $manifest['artifact']['source_sha256'],
        artifactBodySha256: $manifest['artifact']['body_sha256'],
    );
    $package = (new DeltaAtomicStagingSqlPackageBuilder(
        repositoryRoot: stagingSqlRepoRoot(),
        gitRevisionReader: new FixedGitRevisionReader($approval->approvedHead),
        approval: $approval,
    ))->build();

    expect(ApprovedStagingSqlArtifacts::ordered())->toHaveCount(3)
        ->and($package->artifacts)->toHaveCount(1)
        ->and($package->orderedRelativePaths())->toBe([
            DeltaStagingSqlReleaseManifest::ARTIFACT_PATH,
        ])
        ->and($package->orderedBodySql()[0])->toContain('calibration_due_notification_batches');

    (new DeltaStagingSqlDeploymentGateValidator)->assertPackageReady($package, $approval);
});

it('rejects replaying a historical artifact through the delta release', function () {
    $manifest = DeltaStagingSqlReleaseManifest::pending(stagingSqlRepoRoot());
    $approval = new DeltaStagingSqlReleaseApproval(
        releaseId: $manifest['release_id'],
        approvedHead: str_repeat('b', 40),
        checkpoint: 'delta-test-checkpoint',
        freshBackupSha256: str_repeat('c', 64),
        artifactSha256: ApprovedStagingSqlArtifacts::ordered()[0]['sha256'],
        artifactBodySha256: $manifest['artifact']['body_sha256'],
    );

    expect(fn () => (new DeltaAtomicStagingSqlPackageBuilder(
        repositoryRoot: stagingSqlRepoRoot(),
        gitRevisionReader: new FixedGitRevisionReader($approval->approvedHead),
        approval: $approval,
    ))->build())->toThrow(StagingSqlDeploymentException::class, 'Delta SQL artifact hash mismatch');
});

it('rejects an incomplete delta release approval before package construction', function () {
    $approval = new DeltaStagingSqlReleaseApproval(
        releaseId: DeltaStagingSqlReleaseManifest::RELEASE_ID,
        approvedHead: '',
        checkpoint: '',
        freshBackupSha256: '',
        artifactSha256: '',
        artifactBodySha256: '',
    );

    expect(fn () => $approval->assertComplete())
        ->toThrow(StagingSqlDeploymentException::class, 'Missing approved release HEAD');
});

it('rejects delta authorization with the wrong backup hash or database', function () {
    $manifest = DeltaStagingSqlReleaseManifest::pending(stagingSqlRepoRoot());
    $approval = new DeltaStagingSqlReleaseApproval(
        releaseId: $manifest['release_id'],
        approvedHead: str_repeat('b', 40),
        checkpoint: 'delta-test-checkpoint',
        freshBackupSha256: str_repeat('c', 64),
        artifactSha256: $manifest['artifact']['source_sha256'],
        artifactBodySha256: $manifest['artifact']['body_sha256'],
    );
    $authorization = new StagingSqlDeploymentAuthorization(
        operatorWriteApproved: true,
        backupBaselineVerified: true,
        freshSchemaInventoryVerified: true,
        freshTriggerFingerprintVerified: true,
        operatorConfirmedExecution: true,
        approvedBackupSha256: str_repeat('d', 64),
        approvedCheckpoint: $approval->checkpoint,
        approvedDatabase: 'calibration_db',
        approvedHost: '127.0.0.1',
        approvedPort: ApprovedStagingSqlArtifacts::APPROVED_PORT,
        approvedReleaseHead: $approval->approvedHead,
        approvedArtifactSha256: [$approval->artifactSha256],
    );

    expect(fn () => (new DeltaStagingSqlDeploymentGateValidator)->assertAuthorization($authorization, $approval))
        ->toThrow(StagingSqlDeploymentException::class, 'backup verification mismatch');
});

it('rejects a delta package when the resolved HEAD differs from approval', function () {
    $manifest = DeltaStagingSqlReleaseManifest::pending(stagingSqlRepoRoot());
    $approval = new DeltaStagingSqlReleaseApproval(
        releaseId: $manifest['release_id'],
        approvedHead: str_repeat('b', 40),
        checkpoint: 'delta-test-checkpoint',
        freshBackupSha256: str_repeat('c', 64),
        artifactSha256: $manifest['artifact']['source_sha256'],
        artifactBodySha256: $manifest['artifact']['body_sha256'],
    );

    expect(fn () => (new DeltaAtomicStagingSqlPackageBuilder(
        repositoryRoot: stagingSqlRepoRoot(),
        gitRevisionReader: new FixedGitRevisionReader(str_repeat('a', 40)),
        approval: $approval,
    ))->build())->toThrow(StagingSqlDeploymentException::class, 'Delta release HEAD');
});

it('rejects unapproved delta release identity and unsafe targets', function () {
    $approval = new DeltaStagingSqlReleaseApproval(
        releaseId: 'unapproved-release',
        approvedHead: str_repeat('b', 40),
        checkpoint: 'delta-test-checkpoint',
        freshBackupSha256: str_repeat('c', 64),
        artifactSha256: str_repeat('d', 64),
        artifactBodySha256: str_repeat('e', 64),
    );

    expect(fn () => $approval->assertComplete())
        ->toThrow(StagingSqlDeploymentException::class, 'release identifier')
        ->and(fn () => (new DeltaStagingSqlDeploymentGateValidator)->assertTargetAllowed(
            new StagingSqlDeploymentTarget('127.0.0.1', 5432, 'calibration_db'),
        ))->toThrow(StagingSqlDeploymentException::class, 'Production database is forbidden');
});

it('keeps the Laravel migration out of the executable migration directory', function () {
    expect(file_exists(stagingSqlRepoRoot().'/database/migrations/2026_11_06_000001_create_calibration_due_notification_history_tables.php'))->toBeFalse()
        ->and(file_exists(stagingSqlRepoRoot().'/database/sql/reference/2026_11_06_000001_create_calibration_due_notification_history_tables.php'))->toBeTrue();
});

it('builds the notification schema package from the approved canonical artifact', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();

    expect($package->artifactPath)->toBe(NotificationStagingSqlPackageBuilder::ARTIFACT_PATH)
        ->and($package->artifactSha256)->toBe(NotificationStagingSqlPackageBuilder::EXPECTED_ARTIFACT_SHA256)
        ->and($package->releaseIdentity)->toBe(
            NotificationStagingSqlPackageBuilder::RELEASE_ID_PREFIX.NotificationStagingSqlPackageBuilder::EXPECTED_ARTIFACT_SHA256
        )
        ->and($package->targetDatabase)->toBe('calibration_staging_db')
        ->and($package->hasOuterTransaction())->toBeTrue()
        ->and($package->requiredTables)->toContain('public.calibration');
});

it('rejects unknown or modified notification artifacts', function () {
    $builder = new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot());

    expect(fn () => $builder->build('database/sql/other.sql'))
        ->toThrow(StagingSqlDeploymentException::class, 'Unknown notification SQL artifact')
        ->and(fn () => (new NotificationStagingSqlPackageBuilder(
            stagingSqlRepoRoot(),
            str_repeat('a', 64),
        ))->build())
        ->toThrow(StagingSqlDeploymentException::class, 'checksum mismatch');
});

it('requires release, staging, backup, and operator evidence before deployment', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $incomplete = new NotificationStagingSqlDeploymentEvidence(
        releaseIdentity: $package->releaseIdentity,
        artifactSha256: $package->artifactSha256,
        databaseIdentity: 'calibration_db',
        host: '127.0.0.1',
        port: 5432,
        backupSha256: '',
        backupCreatedAt: '',
        recoveryDestination: '',
        backupVerified: false,
        operatorAuthorized: false,
    );

    expect(fn () => $incomplete->assertComplete($package))
        ->toThrow(StagingSqlDeploymentException::class, 'not approved staging');
});

it('rejects historical approval evidence for the notification package', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $evidence = new NotificationStagingSqlDeploymentEvidence(
        releaseIdentity: 'historical-release',
        artifactSha256: $package->artifactSha256,
        databaseIdentity: 'calibration_staging_db',
        host: '127.0.0.1',
        port: 5432,
        backupSha256: str_repeat('b', 64),
        backupCreatedAt: '2026-11-23T00:00:00Z',
        recoveryDestination: 'approved-staging-recovery',
        backupVerified: true,
        operatorAuthorized: true,
    );

    expect(fn () => $evidence->assertComplete($package))
        ->toThrow(StagingSqlDeploymentException::class, 'release identity');
});

it('requires trusted approval and schema preflight before notification deployment preparation', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $evidence = notificationDeploymentEvidence($package);
    $adapter = new NotificationStagingSqlDeploymentAdapter;

    expect(fn () => $adapter->preflight($package, $evidence, __FILE__, null, null))
        ->toThrow(StagingSqlDeploymentException::class, 'Trusted notification deployment authorization');
});

it('rejects fabricated approval even when field-shaped evidence is complete', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $evidence = notificationDeploymentEvidence($package);
    $backup = tempnam(sys_get_temp_dir(), 'notification_backup_');
    file_put_contents($backup, 'approved backup bytes');

    $trusted = new class implements NotificationStagingSqlTrustedApproval
    {
        public function assertApproved(
            NotificationStagingSqlPackage $package,
            NotificationStagingSqlDeploymentEvidence $evidence,
        ): void {
            throw new StagingSqlDeploymentException('Trusted approval rejected fabricated evidence.');
        }
    };
    $schema = new class implements NotificationStagingSqlSchemaPreflight
    {
        public function assertReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
        ): void {}
    };

    expect(fn () => (new NotificationStagingSqlDeploymentAdapter)->preflight(
        $package,
        $evidence,
        $backup,
        $trusted,
        $schema,
    ))->toThrow(StagingSqlDeploymentException::class, 'fabricated evidence');

    @unlink($backup);
});

it('verifies backup bytes and rejects missing or incorrect backups', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $backup = tempnam(sys_get_temp_dir(), 'notification_backup_');
    file_put_contents($backup, 'approved backup bytes');
    $verifier = new NotificationStagingSqlBackupVerifier;

    $checksum = hash_file('sha256', $backup);
    $verifier->assertFileMatches($backup, $checksum);

    expect(fn () => $verifier->assertFileMatches($backup, str_repeat('a', 64)))
        ->toThrow(StagingSqlDeploymentException::class, 'checksum mismatch')
        ->and(fn () => $verifier->assertFileMatches($backup.'.missing', $checksum))
        ->toThrow(StagingSqlDeploymentException::class, 'missing');

    @unlink($backup);
});

it('preflight never opens PostgreSQL and returns no execution capability', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $backup = tempnam(sys_get_temp_dir(), 'notification_backup_');
    file_put_contents($backup, 'approved backup bytes');
    $evidence = notificationDeploymentEvidence($package, hash_file('sha256', $backup));
    $trusted = new class implements NotificationStagingSqlTrustedApproval
    {
        public function assertApproved(
            NotificationStagingSqlPackage $package,
            NotificationStagingSqlDeploymentEvidence $evidence,
        ): void {}
    };
    $schema = new class implements NotificationStagingSqlSchemaPreflight
    {
        public function assertReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
        ): void {}
    };

    $result = (new NotificationStagingSqlDeploymentAdapter)->preflight(
        $package,
        $evidence,
        $backup,
        $trusted,
        $schema,
    );

    expect($result->targetDatabase)->toBe('calibration_staging_db')
        ->and($result->readyForSeparatelyAuthorizedExecution)->toBeTrue()
        ->and(method_exists(NotificationStagingSqlDeploymentAdapter::class, 'execute'))->toBeFalse();

    @unlink($backup);
});

it('rejects production or non-approved endpoint targets before schema preflight', function () {
    $source = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $evidence = notificationDeploymentEvidence($source);
    $backup = tempnam(sys_get_temp_dir(), 'notification_backup_');
    file_put_contents($backup, 'approved backup bytes');
    $trusted = new class implements NotificationStagingSqlTrustedApproval
    {
        public function assertApproved(
            NotificationStagingSqlPackage $package,
            NotificationStagingSqlDeploymentEvidence $evidence,
        ): void {}
    };
    $schema = new class implements NotificationStagingSqlSchemaPreflight
    {
        public function assertReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
        ): void {
            throw new StagingSqlDeploymentException('Schema preflight should not be reached.');
        }
    };

    $production = new NotificationStagingSqlPackage(
        artifactPath: $source->artifactPath,
        artifactSha256: $source->artifactSha256,
        releaseIdentity: $source->releaseIdentity,
        targetDatabase: 'calibration_db',
        targetHost: $source->targetHost,
        targetPort: $source->targetPort,
        sql: $source->sql,
        requiredTables: $source->requiredTables,
        expectedTable: $source->expectedTable,
    );

    expect(fn () => (new NotificationStagingSqlDeploymentAdapter)->preflight(
        $production,
        $evidence,
        $backup,
        $trusted,
        $schema,
    ))->toThrow(StagingSqlDeploymentException::class, 'Production database is forbidden');

    @unlink($backup);
});

it('fails closed when runtime schema preflight reports an existing table or missing prerequisite', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $backup = tempnam(sys_get_temp_dir(), 'notification_backup_');
    file_put_contents($backup, 'approved backup bytes');
    $evidence = notificationDeploymentEvidence($package, hash_file('sha256', $backup));
    $trusted = new class implements NotificationStagingSqlTrustedApproval
    {
        public function assertApproved(
            NotificationStagingSqlPackage $package,
            NotificationStagingSqlDeploymentEvidence $evidence,
        ): void {}
    };

    foreach (['already exists', 'required prerequisite is missing'] as $message) {
        $schema = new class($message) implements NotificationStagingSqlSchemaPreflight
        {
            public function __construct(private string $message) {}

            public function assertReady(
                NotificationStagingSqlPackage $package,
                StagingSqlDeploymentTarget $target,
            ): void {
                throw new StagingSqlDeploymentException($this->message);
            }
        };

        expect(fn () => (new NotificationStagingSqlDeploymentAdapter)->preflight(
            $package,
            $evidence,
            $backup,
            $trusted,
            $schema,
        ))->toThrow(StagingSqlDeploymentException::class, $message);
    }

    @unlink($backup);
});

it('accepts a complete isolated pre-deployment schema inspection', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $inspection = notificationSchemaInspection(notificationTableExists: false);

    (new NotificationStagingSqlPreDeploymentVerifier($inspection))->assertReady(
        $package,
        StagingSqlDeploymentTarget::approvedLocalStaging(),
    );

    expect(true)->toBeTrue();
});

it('requires the notification table and all schema objects after deployment', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $complete = notificationSchemaInspection(notificationTableExists: true);

    (new NotificationStagingSqlPostDeploymentVerifier($complete))->assertReady($package);

    $wrongPrimaryKeyName = new NotificationStagingSqlSchemaInspection(
        database: $complete->database,
        host: $complete->host,
        port: $complete->port,
        relations: $complete->relations,
        keyTypes: $complete->keyTypes,
        notificationTableExists: true,
        columns: $complete->columns,
        primaryKeys: ['wrong_primary_key'],
        foreignKeys: $complete->foreignKeys,
        checkConstraints: $complete->checkConstraints,
        uniqueConstraints: $complete->uniqueConstraints,
        indexes: $complete->indexes,
        identitySequenceExists: true,
        permissions: $complete->permissions,
        initialRowCount: 0,
        primaryKeyColumns: ['calibration_notification_id'],
    );

    expect(fn () => (new NotificationStagingSqlPostDeploymentVerifier($wrongPrimaryKeyName))->assertReady($package))
        ->toThrow(StagingSqlDeploymentException::class, 'primary key is missing');

    $missingPrimaryKey = new NotificationStagingSqlSchemaInspection(
        database: $complete->database,
        host: $complete->host,
        port: $complete->port,
        relations: $complete->relations,
        keyTypes: $complete->keyTypes,
        notificationTableExists: true,
        columns: $complete->columns,
        primaryKeys: [],
        foreignKeys: $complete->foreignKeys,
        checkConstraints: $complete->checkConstraints,
        uniqueConstraints: $complete->uniqueConstraints,
        indexes: $complete->indexes,
        identitySequenceExists: true,
        permissions: $complete->permissions,
        initialRowCount: 0,
        primaryKeyColumns: [],
    );

    expect(fn () => (new NotificationStagingSqlPostDeploymentVerifier($missingPrimaryKey))->assertReady($package))
        ->toThrow(StagingSqlDeploymentException::class, 'primary key is missing');

    $wrongPrimaryKeyColumn = new NotificationStagingSqlSchemaInspection(
        database: $complete->database,
        host: $complete->host,
        port: $complete->port,
        relations: $complete->relations,
        keyTypes: $complete->keyTypes,
        notificationTableExists: true,
        columns: $complete->columns,
        primaryKeys: ['calibration_notifications_pkey'],
        foreignKeys: $complete->foreignKeys,
        checkConstraints: $complete->checkConstraints,
        uniqueConstraints: $complete->uniqueConstraints,
        indexes: $complete->indexes,
        identitySequenceExists: true,
        permissions: $complete->permissions,
        initialRowCount: 0,
        primaryKeyColumns: ['recipient_user_id'],
    );

    expect(fn () => (new NotificationStagingSqlPostDeploymentVerifier($wrongPrimaryKeyColumn))->assertReady($package))
        ->toThrow(StagingSqlDeploymentException::class, 'primary key column mismatch');

    $missingForeignKey = notificationSchemaInspection(notificationTableExists: true);
    $missingForeignKey = new NotificationStagingSqlSchemaInspection(
        database: $missingForeignKey->database,
        host: $missingForeignKey->host,
        port: $missingForeignKey->port,
        relations: $missingForeignKey->relations,
        keyTypes: $missingForeignKey->keyTypes,
        notificationTableExists: true,
        columns: $missingForeignKey->columns,
        primaryKeys: $missingForeignKey->primaryKeys,
        foreignKeys: [],
        checkConstraints: $missingForeignKey->checkConstraints,
        uniqueConstraints: $missingForeignKey->uniqueConstraints,
        indexes: $missingForeignKey->indexes,
        identitySequenceExists: true,
        permissions: $missingForeignKey->permissions,
        initialRowCount: 0,
        primaryKeyColumns: $missingForeignKey->primaryKeyColumns,
    );

    expect(fn () => (new NotificationStagingSqlPostDeploymentVerifier($missingForeignKey))->assertReady($package))
        ->toThrow(StagingSqlDeploymentException::class, 'foreign key missing');

    $missingTable = notificationSchemaInspection(notificationTableExists: false);
    expect(fn () => (new NotificationStagingSqlPostDeploymentVerifier($missingTable))->assertReady($package))
        ->toThrow(StagingSqlDeploymentException::class, 'table is missing');

    $wrongOwner = notificationSchemaInspection(notificationTableExists: true);
    $wrongOwner = new NotificationStagingSqlSchemaInspection(
        database: $wrongOwner->database,
        host: $wrongOwner->host,
        port: $wrongOwner->port,
        relations: $wrongOwner->relations,
        keyTypes: $wrongOwner->keyTypes,
        notificationTableExists: true,
        columns: $wrongOwner->columns,
        primaryKeys: $wrongOwner->primaryKeys,
        foreignKeys: $wrongOwner->foreignKeys,
        checkConstraints: $wrongOwner->checkConstraints,
        uniqueConstraints: $wrongOwner->uniqueConstraints,
        indexes: $wrongOwner->indexes,
        identitySequenceExists: true,
        permissions: ['connect' => true, 'create_table' => true, 'create_index' => true, 'owner' => false],
        initialRowCount: 0,
        primaryKeyColumns: $wrongOwner->primaryKeyColumns,
    );

    expect(fn () => (new NotificationStagingSqlPostDeploymentVerifier($wrongOwner))->assertReady($package))
        ->toThrow(StagingSqlDeploymentException::class, 'ownership or row-count verification failed');
});

it('inspects only an explicitly validated staging connection', function () {
    $connection = notificationStagingConnectionMock();
    $reader = new class implements NotificationStagingSqlReadOnlyMetadataReader
    {
        public function inspect(Connection $connection): NotificationStagingSqlSchemaInspection
        {
            return notificationSchemaInspection(notificationTableExists: false);
        }
    };
    $inspector = new NotificationStagingSqlSchemaInspector(
        notificationSessionConnectionGuard(),
        $reader,
    );

    $inspection = $inspector->inspectPreDeployment($connection);

    expect($inspection->database)->toBe('calibration_staging_db')
        ->and($inspection->notificationTableExists)->toBeFalse();
});

it('rejects production identity and wrong endpoint without schema queries', function () {
    $reader = new class implements NotificationStagingSqlReadOnlyMetadataReader
    {
        public function inspect(Connection $connection): NotificationStagingSqlSchemaInspection
        {
            throw new RuntimeException('Reader must not be called.');
        }
    };

    expect(fn () => (new NotificationStagingSqlSchemaInspector(
        notificationSessionConnectionGuard('calibration_db'),
        $reader,
    ))->inspectPreDeployment(notificationStagingConnectionMock('calibration_db')))
        ->toThrow(RuntimeException::class)
        ->and(fn () => (new NotificationStagingSqlSchemaInspector(
            notificationSessionConnectionGuard(host: '10.0.0.5'),
            $reader,
        ))->inspectPreDeployment(notificationStagingConnectionMock(host: '10.0.0.5')))
        ->toThrow(StagingSqlDeploymentException::class, 'approved staging connection');
});

it('fails closed on inspector query failure and performs no write SQL', function () {
    $reader = new class implements NotificationStagingSqlReadOnlyMetadataReader
    {
        public function inspect(Connection $connection): NotificationStagingSqlSchemaInspection
        {
            throw new RuntimeException('catalog query failed');
        }
    };
    $source = file_get_contents(stagingSqlRepoRoot().'/app/StagingSqlDeployment/PostgreSqlNotificationStagingSqlMetadataReader.php');

    expect(fn () => (new NotificationStagingSqlSchemaInspector(
        notificationSessionConnectionGuard(),
        $reader,
    ))->inspectPostDeployment(notificationStagingConnectionMock()))
        ->toThrow(StagingSqlDeploymentException::class, 'inspection failed')
        ->and($source)->toContain('select ')
        ->and($source)->not->toMatch('/\b(insert\s+into|update\s+|delete\s+from|alter\s+table|create\s+table|drop\s+table)\b/i');
});

it('does not query during schema inspector construction', function () {
    $reader = new class implements NotificationStagingSqlReadOnlyMetadataReader
    {
        public function inspect(Connection $connection): NotificationStagingSqlSchemaInspection
        {
            throw new RuntimeException('not called during construction');
        }
    };

    $inspector = new NotificationStagingSqlSchemaInspector(
        notificationSessionConnectionGuard(),
        $reader,
    );

    expect($inspector)->toBeInstanceOf(NotificationStagingSqlSchemaInspector::class);
});

it('uses PostgreSQL current_user syntax and fully qualifies catalog relations', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('scalar')
        ->once()
        ->with('select current_database()')
        ->andReturn('calibration_staging_db');
    $connection->shouldReceive('getConfig')->with('host')->andReturn('127.0.0.1');
    $connection->shouldReceive('getConfig')->with('port')->andReturn(5432);
    $connection->shouldReceive('select')
        ->once()
        ->withArgs(fn (string $sql): bool => str_contains($sql, 'pg_catalog.pg_namespace')
            && str_contains($sql, "n.nspname || '.' || c.relname")
            && ! str_contains($sql, 'to_regclass'))
        ->andReturn([
            (object) ['relation' => 'public.users'],
            (object) ['relation' => 'public.instrument'],
            (object) ['relation' => 'public.instrument_type'],
            (object) ['relation' => 'public.calibration'],
            (object) ['relation' => null],
        ]);
    $connection->shouldReceive('select')
        ->once()
        ->withArgs(fn (string $sql): bool => str_contains($sql, 'information_schema.columns'))
        ->andReturn([
            (object) ['table_name' => 'users', 'column_name' => 'id', 'data_type' => 'bigint'],
            (object) ['table_name' => 'instrument', 'column_name' => 'instrument_id', 'data_type' => 'bigint'],
            (object) ['table_name' => 'instrument_type', 'column_name' => 'instrument_type_id', 'data_type' => 'bigint'],
            (object) ['table_name' => 'calibration', 'column_name' => 'calibration_id', 'data_type' => 'bigint'],
        ]);
    $connection->shouldReceive('selectOne')
        ->once()
        ->withArgs(fn (string $sql): bool => str_contains($sql, 'current_user')
            && ! str_contains($sql, 'current_user()'))
        ->andReturn((object) [
            'create_schema' => true,
            'can_connect' => true,
            'can_select' => true,
            'is_owner' => false,
        ]);

    $inspection = (new PostgreSqlNotificationStagingSqlMetadataReader)->inspect($connection);

    expect($inspection->relations)->toBe([
        'public.users',
        'public.instrument',
        'public.instrument_type',
        'public.calibration',
    ])
        ->and($inspection->notificationTableExists)->toBeFalse()
        ->and($inspection->permissions)->toBe([
            'connect' => true,
            'create_table' => true,
            'create_index' => true,
            'owner' => false,
        ]);
});

it('keeps the metadata reader read-only and removes invalid current_user calls', function () {
    $source = file_get_contents(stagingSqlRepoRoot().'/app/StagingSqlDeployment/PostgreSqlNotificationStagingSqlMetadataReader.php');

    expect($source)->toBeString()
        ->and($source)->not->toContain('current_user()')
        ->and($source)->not->toContain("'public.calibration_notifications'::regclass")
        ->and($source)->toContain('pg_catalog.pg_class')
        ->and($source)->toContain('pg_catalog.pg_namespace')
        ->and($source)->toContain('current_user')
        ->and($source)->not->toMatch('/\b(insert\s+into|update\s+|delete\s+from|alter\s+table|create\s+table|drop\s+table)\b/i');
});

it('builds an immutable notification execution plan from the exact artifact', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $plan = NotificationStagingSqlExecutionPlan::fromPackage($package);

    expect($plan->package)->toBe($package)
        ->and($plan->bodySql)->not->toMatch('/^\s*BEGIN\s*;/i')
        ->and($plan->bodySql)->not->toMatch('/COMMIT\s*;\s*$/i')
        ->and($plan->statements)->not->toBeEmpty()
        ->and($plan->statements[0])->toStartWith('DO');
});

it('rejects a notification execution plan with changed bytes or release identity', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $changed = new NotificationStagingSqlPackage(
        artifactPath: $package->artifactPath,
        artifactSha256: $package->artifactSha256,
        releaseIdentity: $package->releaseIdentity,
        targetDatabase: $package->targetDatabase,
        targetHost: $package->targetHost,
        targetPort: $package->targetPort,
        sql: $package->sql."\n",
        requiredTables: $package->requiredTables,
        expectedTable: $package->expectedTable,
    );
    $wrongRelease = new NotificationStagingSqlPackage(
        artifactPath: $package->artifactPath,
        artifactSha256: $package->artifactSha256,
        releaseIdentity: 'historical-release',
        targetDatabase: $package->targetDatabase,
        targetHost: $package->targetHost,
        targetPort: $package->targetPort,
        sql: $package->sql,
        requiredTables: $package->requiredTables,
        expectedTable: $package->expectedTable,
    );

    expect(fn () => NotificationStagingSqlExecutionPlan::fromPackage($changed))
        ->toThrow(StagingSqlDeploymentException::class, 'checksum mismatch')
        ->and(fn () => NotificationStagingSqlExecutionPlan::fromPackage($wrongRelease))
        ->toThrow(StagingSqlDeploymentException::class, 'release identity mismatch');
});

it('rejects nested, duplicated, and premature transaction control', function () {
    $sql = "BEGIN;\nSELECT 1;\nBEGIN;\nCOMMIT;\nCOMMIT;\n";
    $checksum = hash('sha256', $sql);
    $package = new NotificationStagingSqlPackage(
        artifactPath: NotificationStagingSqlPackageBuilder::ARTIFACT_PATH,
        artifactSha256: $checksum,
        releaseIdentity: NotificationStagingSqlPackageBuilder::RELEASE_ID_PREFIX.$checksum,
        targetDatabase: ApprovedStagingSqlArtifacts::APPROVED_DATABASE,
        targetHost: '127.0.0.1',
        targetPort: ApprovedStagingSqlArtifacts::APPROVED_PORT,
        sql: $sql,
        requiredTables: [],
        expectedTable: NotificationStagingSqlPackageBuilder::EXPECTED_TABLE,
    );

    expect(fn () => NotificationStagingSqlExecutionPlan::fromPackage($package))
        ->toThrow(StagingSqlDeploymentException::class);
});

it('executes the notification lifecycle only through injected fakes', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $plan = NotificationStagingSqlExecutionPlan::fromPackage($package);
    $backup = tempnam(sys_get_temp_dir(), 'notification_execution_backup_');
    file_put_contents($backup, 'approved backup bytes');
    $evidence = notificationDeploymentEvidence($package, hash_file('sha256', $backup));
    $sessionFactory = new FakeStagingSqlConnectionFactory;
    $claims = notificationApprovalClaims($package, $evidence);
    $verifier = new class implements NotificationStagingSqlExecutionVerifier
    {
        public int $preChecks = 0;

        public int $postChecks = 0;

        public function assertPreDeploymentReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
            StagingSqlSession $session,
        ): void {
            $this->preChecks++;
        }

        public function assertPostDeploymentReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
            StagingSqlSession $session,
        ): void {
            $this->postChecks++;
        }
    };
    $authority = notificationApprovalAuthority();
    $replayProtection = notificationApprovalReplayProtection();

    $outcome = (new NotificationStagingSqlExecutionExecutor($sessionFactory, $verifier, $replayProtection))
        ->execute($plan, $evidence, $backup, $claims, $authority);

    expect($outcome->status)->toBe(NotificationStagingSqlExecutionOutcome::STATUS_COMMITTED)
        ->and($sessionFactory->connectAttempts)->toBe(1)
        ->and($sessionFactory->session()->beginCount)->toBe(1)
        ->and($sessionFactory->session()->commitCount)->toBe(1)
        ->and($sessionFactory->session()->rollbackCount)->toBe(0)
        ->and($verifier->preChecks)->toBe(1)
        ->and($verifier->postChecks)->toBe(1)
        ->and(collect($sessionFactory->session()->executedStatements)->filter(
            static fn (string $statement): bool => in_array(strtoupper(trim($statement)), ['BEGIN;', 'COMMIT;'], true)
        ))->toHaveCount(0);

    @unlink($backup);
});

it('rolls back when pre- or post-deployment verification fails', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $plan = NotificationStagingSqlExecutionPlan::fromPackage($package);
    $backup = tempnam(sys_get_temp_dir(), 'notification_execution_backup_');
    file_put_contents($backup, 'approved backup bytes');
    $evidence = notificationDeploymentEvidence($package, hash_file('sha256', $backup));
    $claims = notificationApprovalClaims($package, $evidence);
    $authority = notificationApprovalAuthority();

    foreach ([
        NotificationStagingSqlExecutionOutcome::STATUS_PRE_DEPLOYMENT_FAILED => 'pre-deployment verifier failed',
        NotificationStagingSqlExecutionOutcome::STATUS_POST_DEPLOYMENT_FAILED => 'post-deployment verifier failed',
    ] as $expectedStatus => $message) {
        $factory = new FakeStagingSqlConnectionFactory;
        $replayProtection = notificationApprovalReplayProtection();
        $verifier = new class($message) implements NotificationStagingSqlExecutionVerifier
        {
            public function __construct(private string $message) {}

            public function assertPreDeploymentReady(
                NotificationStagingSqlPackage $package,
                StagingSqlDeploymentTarget $target,
                StagingSqlSession $session,
            ): void {
                if (str_starts_with($this->message, 'pre-')) {
                    throw new StagingSqlDeploymentException($this->message);
                }
            }

            public function assertPostDeploymentReady(
                NotificationStagingSqlPackage $package,
                StagingSqlDeploymentTarget $target,
                StagingSqlSession $session,
            ): void {
                if (str_starts_with($this->message, 'post-')) {
                    throw new StagingSqlDeploymentException($this->message);
                }
            }
        };
        $outcome = (new NotificationStagingSqlExecutionExecutor($factory, $verifier, $replayProtection))
            ->execute($plan, $evidence, $backup, $claims, $authority);

        expect($outcome->status)->toBe($expectedStatus)
            ->and($factory->session()->rollbackCount)->toBe(1)
            ->and($factory->session()->commitCount)->toBe(0);
    }

    @unlink($backup);
});

it('does not retry an ambiguous notification commit', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $plan = NotificationStagingSqlExecutionPlan::fromPackage($package);
    $backup = tempnam(sys_get_temp_dir(), 'notification_execution_backup_');
    file_put_contents($backup, 'approved backup bytes');
    $evidence = notificationDeploymentEvidence($package, hash_file('sha256', $backup));
    $claims = notificationApprovalClaims($package, $evidence);
    $factory = new FakeStagingSqlConnectionFactory;
    $factory->session()->ambiguousCommit = true;
    $authority = notificationApprovalAuthority();
    $replayProtection = notificationApprovalReplayProtection();
    $verifier = new class implements NotificationStagingSqlExecutionVerifier
    {
        public function assertPreDeploymentReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
            StagingSqlSession $session,
        ): void {}

        public function assertPostDeploymentReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
            StagingSqlSession $session,
        ): void {}
    };

    $outcome = (new NotificationStagingSqlExecutionExecutor($factory, $verifier, $replayProtection))
        ->execute($plan, $evidence, $backup, $claims, $authority);

    expect($outcome->status)->toBe(NotificationStagingSqlExecutionOutcome::STATUS_AMBIGUOUS_COMMIT)
        ->and($factory->connectAttempts)->toBe(1)
        ->and($factory->session()->rollbackCount)->toBe(0)
        ->and($outcome->message)->toContain('No automatic retry');

    @unlink($backup);
});

it('blocks before connection acquisition when trusted approval is unavailable', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $plan = NotificationStagingSqlExecutionPlan::fromPackage($package);
    $backup = tempnam(sys_get_temp_dir(), 'notification_execution_backup_');
    file_put_contents($backup, 'approved backup bytes');
    $evidence = notificationDeploymentEvidence($package, hash_file('sha256', $backup));
    $factory = new FakeStagingSqlConnectionFactory;
    $claims = notificationApprovalClaims($package, $evidence);
    $verifier = new class implements NotificationStagingSqlExecutionVerifier
    {
        public function assertPreDeploymentReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
            StagingSqlSession $session,
        ): void {}

        public function assertPostDeploymentReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
            StagingSqlSession $session,
        ): void {}
    };

    $outcome = (new NotificationStagingSqlExecutionExecutor($factory, $verifier, notificationApprovalReplayProtection()))
        ->execute($plan, $evidence, $backup, $claims, null);

    expect($outcome->status)->toBe(NotificationStagingSqlExecutionOutcome::STATUS_BLOCKED)
        ->and($factory->connectAttempts)->toBe(0);

    @unlink($backup);
});

it('validates approval claims separately from authentic provenance', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $evidence = notificationDeploymentEvidence($package);
    $claims = notificationApprovalClaims($package, $evidence);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $claims->assertMatches($package, $evidence, $now);

    $expired = new NotificationStagingSqlApprovalClaims(
        approvalId: $claims->approvalId,
        operatorIdentity: $claims->operatorIdentity,
        releaseIdentity: $claims->releaseIdentity,
        artifactSha256: $claims->artifactSha256,
        databaseIdentity: $claims->databaseIdentity,
        host: $claims->host,
        port: $claims->port,
        backupSha256: $claims->backupSha256,
        issuedAt: $now->modify('-2 hours'),
        expiresAt: $now->modify('-1 hour'),
        operation: $claims->operation,
    );

    expect(fn () => $expired->assertMatches($package, $evidence, $now))
        ->toThrow(StagingSqlDeploymentException::class, 'expired');
});

it('rejects wrong release, target, backup, operation, and malformed claims', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $evidence = notificationDeploymentEvidence($package);
    $valid = notificationApprovalClaims($package, $evidence);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    foreach ([
        'wrong-release' => ['releaseIdentity' => 'other-release'],
        'wrong-target' => ['databaseIdentity' => 'calibration_db'],
        'wrong-backup' => ['backupSha256' => str_repeat('a', 64)],
        'wrong-operation' => ['operation' => 'historical_deployment'],
        'malformed-id' => ['approvalId' => ''],
    ] as $label => $changes) {
        $values = [
            'approvalId' => $valid->approvalId,
            'operatorIdentity' => $valid->operatorIdentity,
            'releaseIdentity' => $valid->releaseIdentity,
            'artifactSha256' => $valid->artifactSha256,
            'databaseIdentity' => $valid->databaseIdentity,
            'host' => $valid->host,
            'port' => $valid->port,
            'backupSha256' => $valid->backupSha256,
            'issuedAt' => $valid->issuedAt,
            'expiresAt' => $valid->expiresAt,
            'operation' => $valid->operation,
        ];
        $values = array_replace($values, $changes);
        $invalid = new NotificationStagingSqlApprovalClaims(...$values);

        expect(fn () => $invalid->assertMatches($package, $evidence, $now))
            ->toThrow(StagingSqlDeploymentException::class);
    }
});

it('blocks execution when a trusted authority rejects the claims', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $plan = NotificationStagingSqlExecutionPlan::fromPackage($package);
    $backup = tempnam(sys_get_temp_dir(), 'notification_execution_backup_');
    file_put_contents($backup, 'approved backup bytes');
    $evidence = notificationDeploymentEvidence($package, hash_file('sha256', $backup));
    $authority = new class implements NotificationStagingSqlApprovalAuthority
    {
        public function assertAuthentic(NotificationStagingSqlApprovalClaims $claims): void
        {
            throw new StagingSqlDeploymentException('Approval provenance is not authentic.');
        }
    };
    $verifier = new class implements NotificationStagingSqlExecutionVerifier
    {
        public function assertPreDeploymentReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
            StagingSqlSession $session,
        ): void {}

        public function assertPostDeploymentReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
            StagingSqlSession $session,
        ): void {}
    };
    $factory = new FakeStagingSqlConnectionFactory;

    $outcome = (new NotificationStagingSqlExecutionExecutor($factory, $verifier, notificationApprovalReplayProtection()))
        ->execute($plan, $evidence, $backup, notificationApprovalClaims($package, $evidence), $authority);

    expect($outcome->status)->toBe(NotificationStagingSqlExecutionOutcome::STATUS_BLOCKED)
        ->and($factory->connectAttempts)->toBe(0);

    @unlink($backup);
});

it('leaves operator identity authenticity to the trusted authority', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $plan = NotificationStagingSqlExecutionPlan::fromPackage($package);
    $backup = tempnam(sys_get_temp_dir(), 'notification_execution_backup_');
    file_put_contents($backup, 'approved backup bytes');
    $evidence = notificationDeploymentEvidence($package, hash_file('sha256', $backup));
    $claims = notificationApprovalClaims($package, $evidence);
    $untrustedClaims = new NotificationStagingSqlApprovalClaims(
        approvalId: $claims->approvalId,
        operatorIdentity: 'untrusted-operator',
        releaseIdentity: $claims->releaseIdentity,
        artifactSha256: $claims->artifactSha256,
        databaseIdentity: $claims->databaseIdentity,
        host: $claims->host,
        port: $claims->port,
        backupSha256: $claims->backupSha256,
        issuedAt: $claims->issuedAt,
        expiresAt: $claims->expiresAt,
        operation: $claims->operation,
    );
    $authority = new class implements NotificationStagingSqlApprovalAuthority
    {
        public function assertAuthentic(NotificationStagingSqlApprovalClaims $claims): void
        {
            if ($claims->operatorIdentity !== 'trusted-release-operator') {
                throw new StagingSqlDeploymentException('Approval operator provenance is not trusted.');
            }
        }
    };
    $verifier = new class implements NotificationStagingSqlExecutionVerifier
    {
        public function assertPreDeploymentReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
            StagingSqlSession $session,
        ): void {}

        public function assertPostDeploymentReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
            StagingSqlSession $session,
        ): void {}
    };
    $factory = new FakeStagingSqlConnectionFactory;

    $outcome = (new NotificationStagingSqlExecutionExecutor($factory, $verifier, notificationApprovalReplayProtection()))
        ->execute($plan, $evidence, $backup, $untrustedClaims, $authority);

    expect($outcome->status)->toBe(NotificationStagingSqlExecutionOutcome::STATUS_BLOCKED)
        ->and($factory->connectAttempts)->toBe(0);

    @unlink($backup);
});

it('fails closed when durable replay reservation rejects a reused approval', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $plan = NotificationStagingSqlExecutionPlan::fromPackage($package);
    $backup = tempnam(sys_get_temp_dir(), 'notification_execution_backup_');
    file_put_contents($backup, 'approved backup bytes');
    $evidence = notificationDeploymentEvidence($package, hash_file('sha256', $backup));
    $replay = new class implements NotificationStagingSqlApprovalReplayProtection
    {
        public function reserve(NotificationStagingSqlApprovalClaims $claims): NotificationStagingSqlApprovalReservation
        {
            throw new StagingSqlDeploymentException('Approval has already been reserved or consumed.');
        }
    };
    $verifier = new class implements NotificationStagingSqlExecutionVerifier
    {
        public function assertPreDeploymentReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
            StagingSqlSession $session,
        ): void {}

        public function assertPostDeploymentReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
            StagingSqlSession $session,
        ): void {}
    };
    $factory = new FakeStagingSqlConnectionFactory;

    $outcome = (new NotificationStagingSqlExecutionExecutor($factory, $verifier, $replay))
        ->execute(
            $plan,
            $evidence,
            $backup,
            notificationApprovalClaims($package, $evidence),
            notificationApprovalAuthority(),
        );

    expect($outcome->status)->toBe(NotificationStagingSqlExecutionOutcome::STATUS_BLOCKED)
        ->and($factory->connectAttempts)->toBe(0);

    @unlink($backup);
});

it('marks approval consumption ambiguous when durable commit acknowledgement fails', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $plan = NotificationStagingSqlExecutionPlan::fromPackage($package);
    $backup = tempnam(sys_get_temp_dir(), 'notification_execution_backup_');
    file_put_contents($backup, 'approved backup bytes');
    $evidence = notificationDeploymentEvidence($package, hash_file('sha256', $backup));
    $reservation = new class implements NotificationStagingSqlApprovalReservation
    {
        public bool $ambiguous = false;

        public function markCommitted(): void
        {
            throw new StagingSqlDeploymentException('Replay store acknowledgement failed.');
        }

        public function releaseAfterRollback(): void {}

        public function markAmbiguous(): void
        {
            $this->ambiguous = true;
        }
    };
    $replay = new class($reservation) implements NotificationStagingSqlApprovalReplayProtection
    {
        public function __construct(private NotificationStagingSqlApprovalReservation $reservation) {}

        public function reserve(NotificationStagingSqlApprovalClaims $claims): NotificationStagingSqlApprovalReservation
        {
            return $this->reservation;
        }
    };
    $verifier = new class implements NotificationStagingSqlExecutionVerifier
    {
        public function assertPreDeploymentReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
            StagingSqlSession $session,
        ): void {}

        public function assertPostDeploymentReady(
            NotificationStagingSqlPackage $package,
            StagingSqlDeploymentTarget $target,
            StagingSqlSession $session,
        ): void {}
    };

    $outcome = (new NotificationStagingSqlExecutionExecutor(
        new FakeStagingSqlConnectionFactory,
        $verifier,
        $replay,
    ))->execute(
        $plan,
        $evidence,
        $backup,
        notificationApprovalClaims($package, $evidence),
        notificationApprovalAuthority(),
    );

    expect($outcome->status)->toBe(NotificationStagingSqlExecutionOutcome::STATUS_AMBIGUOUS_COMMIT)
        ->and($reservation->ambiguous)->toBeTrue();

    @unlink($backup);
});

it('verifies a canonical Ed25519 approval manifest with an injected trusted key', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $evidence = notificationDeploymentEvidence($package);
    $claims = notificationApprovalClaims($package, $evidence);
    $manifestData = notificationSignedManifest($claims);
    $manifest = (new NotificationStagingSqlApprovalManifestReader)->read($manifestData['path']);
    $provider = new class($manifestData['publicKey']) implements NotificationStagingSqlTrustedVerificationKeyProvider
    {
        public function __construct(private string $publicKey) {}

        public function publicKeyFor(string $keyId): ?string
        {
            return $keyId === 'test-key-1' ? $this->publicKey : null;
        }
    };

    (new NotificationStagingSqlEd25519ApprovalAuthority($manifest, $provider))
        ->assertAuthentic($claims);

    expect($manifest->signedBytes)->toBe(NotificationStagingSqlApprovalManifest::canonicalSignedBytes(
        $claims,
        NotificationStagingSqlApprovalManifest::ALGORITHM,
        'test-key-1',
    ));
    @unlink($manifestData['path']);
});

it('rejects invalid or unknown Ed25519 approval signatures', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $evidence = notificationDeploymentEvidence($package);
    $claims = notificationApprovalClaims($package, $evidence);
    $manifestData = notificationSignedManifest($claims, str_repeat('0', SODIUM_CRYPTO_SIGN_BYTES));
    $manifest = (new NotificationStagingSqlApprovalManifestReader)->read($manifestData['path']);
    $trustedProvider = new class($manifestData['publicKey']) implements NotificationStagingSqlTrustedVerificationKeyProvider
    {
        public function __construct(private string $publicKey) {}

        public function publicKeyFor(string $keyId): ?string
        {
            return $this->publicKey;
        }
    };

    expect(fn () => (new NotificationStagingSqlEd25519ApprovalAuthority($manifest, $trustedProvider))
        ->assertAuthentic($claims))
        ->toThrow(StagingSqlDeploymentException::class);

    $unknownProvider = new class implements NotificationStagingSqlTrustedVerificationKeyProvider
    {
        public function publicKeyFor(string $keyId): ?string
        {
            return null;
        }
    };

    expect(fn () => (new NotificationStagingSqlEd25519ApprovalAuthority($manifest, $unknownProvider))
        ->assertAuthentic($claims))
        ->toThrow(StagingSqlDeploymentException::class);

    @unlink($manifestData['path']);
});

it('rejects noncanonical, duplicate, unexpected, unsupported, or oversized manifests', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $evidence = notificationDeploymentEvidence($package);
    $claims = notificationApprovalClaims($package, $evidence);
    $manifestData = notificationSignedManifest($claims);
    $canonical = file_get_contents($manifestData['path']);
    expect($canonical)->toBeString();

    $invalidDocuments = [
        $canonical."\n",
        str_replace('"algorithm":"Ed25519","key_id"', '"algorithm":"Ed25519","algorithm":"Ed25519","key_id"', $canonical),
        str_replace('"signature":"', '"unexpected":"x","signature":"', $canonical),
        str_replace('"algorithm":"Ed25519"', '"algorithm":"RSA"', $canonical),
        str_repeat('x', NotificationStagingSqlApprovalManifestReader::MAX_MANIFEST_BYTES + 1),
    ];

    foreach ($invalidDocuments as $contents) {
        $path = stagingSqlWriteTempArtifact($contents);
        expect(fn () => (new NotificationStagingSqlApprovalManifestReader)->read($path))
            ->toThrow(StagingSqlDeploymentException::class);
        @unlink($path);
    }

    @unlink($manifestData['path']);
});

it('rejects expired, future-dated, and mismatched approval claims', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $evidence = notificationDeploymentEvidence($package);
    $base = notificationApprovalClaims($package, $evidence);
    $cases = [
        new NotificationStagingSqlApprovalClaims(
            approvalId: $base->approvalId,
            operatorIdentity: $base->operatorIdentity,
            releaseIdentity: $base->releaseIdentity,
            artifactSha256: $base->artifactSha256,
            databaseIdentity: $base->databaseIdentity,
            host: $base->host,
            port: $base->port,
            backupSha256: $base->backupSha256,
            issuedAt: new DateTimeImmutable('now - 10 minutes', new DateTimeZone('UTC')),
            expiresAt: new DateTimeImmutable('now - 5 minutes', new DateTimeZone('UTC')),
            operation: $base->operation,
        ),
        new NotificationStagingSqlApprovalClaims(
            approvalId: $base->approvalId,
            operatorIdentity: $base->operatorIdentity,
            releaseIdentity: $base->releaseIdentity,
            artifactSha256: $base->artifactSha256,
            databaseIdentity: $base->databaseIdentity,
            host: $base->host,
            port: $base->port,
            backupSha256: $base->backupSha256,
            issuedAt: new DateTimeImmutable('now + 5 minutes', new DateTimeZone('UTC')),
            expiresAt: new DateTimeImmutable('now + 10 minutes', new DateTimeZone('UTC')),
            operation: $base->operation,
        ),
        new NotificationStagingSqlApprovalClaims(
            approvalId: $base->approvalId,
            operatorIdentity: $base->operatorIdentity,
            releaseIdentity: 'different-release',
            artifactSha256: $base->artifactSha256,
            databaseIdentity: $base->databaseIdentity,
            host: $base->host,
            port: $base->port,
            backupSha256: $base->backupSha256,
            issuedAt: $base->issuedAt,
            expiresAt: $base->expiresAt,
            operation: $base->operation,
        ),
    ];

    foreach ($cases as $claims) {
        $manifestData = notificationSignedManifest($claims);
        $manifest = (new NotificationStagingSqlApprovalManifestReader)->read($manifestData['path']);
        $provider = new class($manifestData['publicKey']) implements NotificationStagingSqlTrustedVerificationKeyProvider
        {
            public function __construct(private string $publicKey) {}

            public function publicKeyFor(string $keyId): ?string
            {
                return $this->publicKey;
            }
        };

        $claimsToVerify = $claims->releaseIdentity === 'different-release' ? $base : $claims;

        expect(fn () => (new NotificationStagingSqlEd25519ApprovalAuthority($manifest, $provider))
            ->assertAuthentic($claimsToVerify))
            ->toThrow(StagingSqlDeploymentException::class);
        @unlink($manifestData['path']);
    }
});

it('rejects signed claims that are not bound to the approved release or target', function () {
    $package = (new NotificationStagingSqlPackageBuilder(stagingSqlRepoRoot()))->build();
    $evidence = notificationDeploymentEvidence($package);
    $base = notificationApprovalClaims($package, $evidence);
    $variants = [
        ['releaseIdentity' => 'other-release'],
        ['artifactSha256' => str_repeat('a', 64)],
        ['databaseIdentity' => 'calibration_db'],
        ['host' => '10.0.0.1'],
        ['backupSha256' => str_repeat('c', 64)],
        ['operation' => 'other_operation'],
    ];

    foreach ($variants as $changes) {
        $values = [
            'approvalId' => $base->approvalId,
            'operatorIdentity' => $base->operatorIdentity,
            'releaseIdentity' => $changes['releaseIdentity'] ?? $base->releaseIdentity,
            'artifactSha256' => $changes['artifactSha256'] ?? $base->artifactSha256,
            'databaseIdentity' => $changes['databaseIdentity'] ?? $base->databaseIdentity,
            'host' => $changes['host'] ?? $base->host,
            'port' => $base->port,
            'backupSha256' => $changes['backupSha256'] ?? $base->backupSha256,
            'issuedAt' => $base->issuedAt,
            'expiresAt' => $base->expiresAt,
            'operation' => $changes['operation'] ?? $base->operation,
        ];
        $signedClaims = new NotificationStagingSqlApprovalClaims(...$values);
        $manifestData = notificationSignedManifest($signedClaims);
        $manifest = (new NotificationStagingSqlApprovalManifestReader)->read($manifestData['path']);
        $provider = new class($manifestData['publicKey']) implements NotificationStagingSqlTrustedVerificationKeyProvider
        {
            public function __construct(private string $publicKey) {}

            public function publicKeyFor(string $keyId): ?string
            {
                return $this->publicKey;
            }
        };

        expect(fn () => (new NotificationStagingSqlEd25519ApprovalAuthority($manifest, $provider))
            ->assertAuthentic($base))
            ->toThrow(StagingSqlDeploymentException::class);
        @unlink($manifestData['path']);
    }
});

function notificationDeploymentEvidence(
    NotificationStagingSqlPackage $package,
    ?string $backupSha256 = null,
): NotificationStagingSqlDeploymentEvidence {
    return new NotificationStagingSqlDeploymentEvidence(
        releaseIdentity: $package->releaseIdentity,
        artifactSha256: $package->artifactSha256,
        databaseIdentity: 'calibration_staging_db',
        host: '127.0.0.1',
        port: 5432,
        backupSha256: $backupSha256 ?? str_repeat('b', 64),
        backupCreatedAt: '2026-11-24T00:00:00Z',
        recoveryDestination: 'approved-staging-recovery',
        backupVerified: true,
        operatorAuthorized: true,
    );
}

function notificationApprovalClaims(
    NotificationStagingSqlPackage $package,
    NotificationStagingSqlDeploymentEvidence $evidence,
): NotificationStagingSqlApprovalClaims {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    return new NotificationStagingSqlApprovalClaims(
        approvalId: 'approval-test-001',
        operatorIdentity: 'trusted-release-operator',
        releaseIdentity: $package->releaseIdentity,
        artifactSha256: $package->artifactSha256,
        databaseIdentity: $package->targetDatabase,
        host: $package->targetHost,
        port: $package->targetPort,
        backupSha256: strtolower($evidence->backupSha256),
        issuedAt: $now->modify('-5 minutes'),
        expiresAt: $now->modify('+5 minutes'),
        operation: NotificationStagingSqlApprovalClaims::OPERATION_INSTALL_SCHEMA,
    );
}

/**
 * @return array{path: string, publicKey: string}
 */
function notificationSignedManifest(
    NotificationStagingSqlApprovalClaims $claims,
    ?string $signatureBytes = null,
): array {
    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $signedBytes = NotificationStagingSqlApprovalManifest::canonicalSignedBytes(
        $claims,
        NotificationStagingSqlApprovalManifest::ALGORITHM,
        'test-key-1',
    );
    $signature = sodium_bin2base64(
        $signatureBytes ?? sodium_crypto_sign_detached($signedBytes, $secretKey),
        SODIUM_BASE64_VARIANT_ORIGINAL,
    );
    $path = stagingSqlWriteTempArtifact(NotificationStagingSqlApprovalManifest::canonicalDocument(
        $claims,
        NotificationStagingSqlApprovalManifest::ALGORITHM,
        'test-key-1',
        $signature,
    ));

    return [
        'path' => $path,
        'publicKey' => $publicKey,
    ];
}

function notificationApprovalAuthority(): NotificationStagingSqlApprovalAuthority
{
    return new class implements NotificationStagingSqlApprovalAuthority
    {
        public function assertAuthentic(NotificationStagingSqlApprovalClaims $claims): void {}
    };
}

function notificationApprovalReplayProtection(): NotificationStagingSqlApprovalReplayProtection
{
    $reservation = new class implements NotificationStagingSqlApprovalReservation
    {
        public function markCommitted(): void {}

        public function releaseAfterRollback(): void {}

        public function markAmbiguous(): void {}
    };

    return new class($reservation) implements NotificationStagingSqlApprovalReplayProtection
    {
        public function __construct(private NotificationStagingSqlApprovalReservation $reservation) {}

        public function reserve(NotificationStagingSqlApprovalClaims $claims): NotificationStagingSqlApprovalReservation
        {
            return $this->reservation;
        }
    };
}

function notificationSessionConnectionGuard(
    string $database = 'calibration_staging_db',
    string $host = '127.0.0.1',
    int $port = 5432,
): CalibrationSessionConnection {
    return new CalibrationSessionConnection(
        Mockery::mock(DatabaseManager::class),
        new Repository([
            'database.connections.pgsql_staging' => [
                'driver' => 'pgsql',
                'database' => $database,
                'url' => null,
                'host' => $host,
                'port' => $port,
            ],
        ]),
    );
}

function notificationStagingConnectionMock(
    string $database = 'calibration_staging_db',
    string $host = '127.0.0.1',
    int $port = 5432,
): Connection {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getName')->andReturn('pgsql_staging');
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('getConfig')->with('host')->andReturn($host);
    $connection->shouldReceive('getConfig')->with('port')->andReturn($port);
    $connection->shouldReceive('getConfig')->with('url')->andReturn(null);
    $connection->shouldReceive('getConfig')->with('database')->andReturn($database);
    $connection->shouldReceive('scalar')->with('select current_database()')->andReturn($database);

    return $connection;
}

function notificationSchemaInspection(bool $notificationTableExists): NotificationStagingSqlSchemaInspection
{
    $columns = [
        'calibration_notification_id' => ['data_type' => 'bigint', 'is_nullable' => false],
        'recipient_user_id' => ['data_type' => 'bigint', 'is_nullable' => false],
        'instrument_id' => ['data_type' => 'bigint', 'is_nullable' => true],
        'instrument_type_id' => ['data_type' => 'bigint', 'is_nullable' => true],
        'source_calibration_id' => ['data_type' => 'bigint', 'is_nullable' => true],
        'event_type' => ['data_type' => 'character varying', 'is_nullable' => false],
        'event_key' => ['data_type' => 'character varying', 'is_nullable' => false],
        'occurrence_revision' => ['data_type' => 'smallint', 'is_nullable' => false],
        'instrument_name_snapshot' => ['data_type' => 'text', 'is_nullable' => false],
        'instrument_type_snapshot' => ['data_type' => 'text', 'is_nullable' => false],
        'serial_number_snapshot' => ['data_type' => 'text', 'is_nullable' => true],
        'due_date_snapshot' => ['data_type' => 'date', 'is_nullable' => false],
        'read_at' => ['data_type' => 'timestamp with time zone', 'is_nullable' => true],
        'resolved_at' => ['data_type' => 'timestamp with time zone', 'is_nullable' => true],
        'resolution_calibration_id' => ['data_type' => 'bigint', 'is_nullable' => true],
        'created_at' => ['data_type' => 'timestamp with time zone', 'is_nullable' => false],
        'updated_at' => ['data_type' => 'timestamp with time zone', 'is_nullable' => false],
    ];

    return new NotificationStagingSqlSchemaInspection(
        database: 'calibration_staging_db',
        host: '127.0.0.1',
        port: 5432,
        relations: [
            'public.users',
            'public.instrument',
            'public.instrument_type',
            'public.calibration',
        ],
        keyTypes: [
            'public.users.id' => 'bigint',
            'public.instrument.instrument_id' => 'bigint',
            'public.instrument_type.instrument_type_id' => 'bigint',
            'public.calibration.calibration_id' => 'bigint',
        ],
        notificationTableExists: $notificationTableExists,
        columns: $columns,
        primaryKeys: ['calibration_notifications_pkey'],
        foreignKeys: [
            'fk_calibration_notifications_recipient',
            'fk_calibration_notifications_instrument',
            'fk_calibration_notifications_instrument_type',
            'fk_calibration_notifications_source_calibration',
            'fk_calibration_notifications_resolution_calibration',
        ],
        checkConstraints: [
            'chk_calibration_notifications_event_type',
            'chk_calibration_notifications_event_key',
            'chk_calibration_notifications_occurrence_revision',
            'chk_calibration_notifications_instrument_name',
            'chk_calibration_notifications_type_name',
        ],
        uniqueConstraints: ['uq_calibration_notifications_recipient_event'],
        indexes: [
            'ix_calibration_notifications_recipient_active_read',
            'ix_calibration_notifications_recipient_created',
            'ix_calibration_notifications_pair_event',
            'ix_calibration_notifications_source_calibration',
            'ix_calibration_notifications_recipient_event_revision',
        ],
        identitySequenceExists: true,
        permissions: ['connect' => true, 'create_table' => true, 'create_index' => true, 'owner' => true],
        initialRowCount: 0,
        primaryKeyColumns: ['calibration_notification_id'],
    );
}
