<?php

use App\StagingSqlDeployment\ApprovedStagingSqlArtifacts;
use App\StagingSqlDeployment\AtomicStagingSqlPackageBuilder;
use App\StagingSqlDeployment\ContractStagingSqlExecutionSimulator;
use App\StagingSqlDeployment\DeltaAtomicStagingSqlPackageBuilder;
use App\StagingSqlDeployment\DeltaStagingSqlDeploymentGateValidator;
use App\StagingSqlDeployment\DeltaStagingSqlReleaseApproval;
use App\StagingSqlDeployment\DeltaStagingSqlReleaseManifest;
use App\StagingSqlDeployment\DisabledStagingSqlExecutionAdapter;
use App\StagingSqlDeployment\FixedGitRevisionReader;
use App\StagingSqlDeployment\StagingSqlDeploymentAuthorization;
use App\StagingSqlDeployment\StagingSqlDeploymentException;
use App\StagingSqlDeployment\StagingSqlDeploymentGateValidator;
use App\StagingSqlDeployment\StagingSqlDeploymentTarget;
use App\StagingSqlDeployment\StagingSqlExecutionOutcome;
use App\StagingSqlDeployment\StagingSqlScriptParser;

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
