<?php

namespace App\Console\Commands;

use App\StagingSqlDeployment\ApprovedStagingSqlArtifacts;
use App\StagingSqlDeployment\AtomicStagingSqlPackageBuilder;
use App\StagingSqlDeployment\LiveStagingSqlExecutionAdapter;
use App\StagingSqlDeployment\PdoStagingSqlConnectionFactory;
use App\StagingSqlDeployment\ProcessGitRevisionReader;
use App\StagingSqlDeployment\StagingSqlDeploymentAuthorization;
use App\StagingSqlDeployment\StagingSqlDeploymentException;
use App\StagingSqlDeployment\StagingSqlDeploymentTarget;
use App\StagingSqlDeployment\StagingSqlExecutionOutcome;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Controlled one-shot atomic staging SQL deployment for checkpoint 2C.2B-21Z-D.
 *
 * Does not run on boot, HTTP, or tests. Requires exact authorization options.
 * Does not bootstrap administrators, seed data, or touch production.
 */
#[Signature('staging:deploy-atomic-sql
    {--confirm-checkpoint= : Must be exactly 2C.2B-21Z-D}
    {--confirm-database= : Must be exactly calibration_staging_db}
    {--confirm-host= : Must be exactly 127.0.0.1}
    {--confirm-port= : Must be exactly 5432}
    {--confirm-head= : Must be exact approved release HEAD}
    {--confirm-backup-sha256= : Must be exact 21Y backup SHA-256}
    {--i-authorize-staging-write= : Must be exactly AUTHORIZED}')]
#[Description('Atomically apply the three approved staging SQL artifacts to calibration_staging_db.')]
class DeployStagingAtomicSqlCommand extends Command
{
    public function handle(): int
    {
        $factory = new PdoStagingSqlConnectionFactory;

        try {
            $this->assertOptionExact('confirm-checkpoint', StagingSqlDeploymentAuthorization::APPROVED_CHECKPOINT);
            $this->assertOptionExact('confirm-database', ApprovedStagingSqlArtifacts::APPROVED_DATABASE);
            $this->assertOptionExact('confirm-host', '127.0.0.1');
            $this->assertOptionExact('confirm-port', (string) ApprovedStagingSqlArtifacts::APPROVED_PORT);
            $this->assertOptionExact('confirm-head', ApprovedStagingSqlArtifacts::APPROVED_HEAD);
            $this->assertOptionExact('confirm-backup-sha256', ApprovedStagingSqlArtifacts::APPROVED_BACKUP_SHA256);
            $this->assertOptionExact('i-authorize-staging-write', 'AUTHORIZED');

            $staging = config('database.connections.pgsql_staging');
            if (! is_array($staging)) {
                throw new StagingSqlDeploymentException('pgsql_staging connection config is missing.');
            }

            $configuredDatabase = (string) ($staging['database'] ?? '');
            $configuredHost = strtolower((string) ($staging['host'] ?? ''));
            $configuredPort = (string) ($staging['port'] ?? '');
            if ($configuredDatabase !== ApprovedStagingSqlArtifacts::APPROVED_DATABASE
                || ! in_array($configuredHost, ['127.0.0.1', 'localhost'], true)
                || $configuredPort !== (string) ApprovedStagingSqlArtifacts::APPROVED_PORT
                || ($staging['url'] ?? null) !== null) {
                throw new StagingSqlDeploymentException('pgsql_staging config is not the approved staging endpoint.');
            }

            $username = (string) ($staging['username'] ?? '');
            $password = (string) ($staging['password'] ?? '');
            $factory->unsealForAuthorizedCheckpoint(
                StagingSqlDeploymentAuthorization::APPROVED_CHECKPOINT,
                $username,
                $password,
            );

            $package = (new AtomicStagingSqlPackageBuilder(
                repositoryRoot: base_path(),
                gitRevisionReader: new ProcessGitRevisionReader(base_path()),
            ))->build();

            $authorization = StagingSqlDeploymentAuthorization::scopedForApprovedRelease(
                ApprovedStagingSqlArtifacts::APPROVED_BACKUP_SHA256
            );
            $target = StagingSqlDeploymentTarget::approvedLocalStaging();
            $adapter = new LiveStagingSqlExecutionAdapter($factory);
            $outcome = $adapter->execute($package, $target, $authorization);

            $this->line('status: '.$outcome->status);
            $this->line('committed: '.($outcome->committed ? 'yes' : 'no'));
            $this->line('opened_postgresql: '.($outcome->openedPostgreSql ? 'yes' : 'no'));
            $this->line($outcome->message);

            if ($outcome->status === StagingSqlExecutionOutcome::STATUS_COMMITTED) {
                return self::SUCCESS;
            }

            if ($outcome->status === StagingSqlExecutionOutcome::STATUS_AMBIGUOUS_COMMIT) {
                return 3;
            }

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $factory->reseal();
        }
    }

    private function assertOptionExact(string $option, string $expected): void
    {
        $actual = (string) $this->option($option);
        if ($actual !== $expected) {
            throw new StagingSqlDeploymentException(
                "Authorization option [--{$option}] mismatch."
            );
        }
    }
}
