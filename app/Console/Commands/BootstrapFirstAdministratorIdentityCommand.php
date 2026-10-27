<?php

namespace App\Console\Commands;

use App\Authorization\BootstrapFirstAdministratorIdentity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Staging-only first Administrator identity creation.
 *
 * Creates users + app_user + active map + Administrator role in one transaction.
 * Password is never accepted as a CLI argument; use hidden prompt or STDIN.
 * Does not print passwords or password hashes.
 */
#[Signature('staging:bootstrap-first-administrator-identity
    {--name= : Display name for Laravel users.name and app_user.full_name}
    {--username= : Domain app_user.username}
    {--email= : Email for Laravel users.email and app_user.email}
    {--confirm-database= : Must be exactly calibration_staging_db}
    {--confirm-checkpoint= : Must be exactly 2C.2B-21Z-F}
    {--password-stdin : Read password from STDIN (one line); never pass password as an argument}
    {--yes : Skip interactive confirmation of the staging target}')]
#[Description('Create the first Administrator identity on calibration_staging_db (users + app_user + map + role).')]
class BootstrapFirstAdministratorIdentityCommand extends Command
{
    public const REQUIRED_CHECKPOINT = '2C.2B-21Z-F';

    public function handle(BootstrapFirstAdministratorIdentity $bootstrap): int
    {
        $confirmDatabase = (string) $this->option('confirm-database');
        $confirmCheckpoint = (string) $this->option('confirm-checkpoint');

        if ($confirmCheckpoint !== self::REQUIRED_CHECKPOINT) {
            $this->error('Confirmation mismatch: --confirm-checkpoint must be exactly '.self::REQUIRED_CHECKPOINT.'.');

            return self::FAILURE;
        }

        if ($confirmDatabase !== BootstrapFirstAdministratorIdentity::REQUIRED_DATABASE) {
            $this->error(
                'Confirmation mismatch: --confirm-database must be exactly '
                .BootstrapFirstAdministratorIdentity::REQUIRED_DATABASE.'.',
            );

            return self::FAILURE;
        }

        $displayName = trim((string) ($this->option('name') ?: $this->ask('Display name')));
        $username = trim((string) ($this->option('username') ?: $this->ask('Username')));
        $email = trim((string) ($this->option('email') ?: $this->ask('Email')));

        if ($displayName === '' || $username === '' || $email === '') {
            $this->error('Display name, username, and email are required.');

            return self::FAILURE;
        }

        try {
            $password = $this->readPassword();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! (bool) $this->option('yes')) {
            $this->line('Target database: '.BootstrapFirstAdministratorIdentity::REQUIRED_DATABASE);
            $this->line('Checkpoint: '.self::REQUIRED_CHECKPOINT);
            $this->line('Display name: '.$displayName);
            $this->line('Username: '.$username);
            $this->line('Email: '.$email);
            $this->line('Password: [hidden]');

            if (! $this->confirm('Create the first Administrator identity on staging with these values?', false)) {
                $this->warn('Aborted without writes.');

                return self::FAILURE;
            }
        }

        try {
            $report = $bootstrap->run($displayName, $username, $email, $password, $confirmDatabase);
        } catch (Throwable $exception) {
            // Never echo exception payloads that might contain credentials.
            $this->error($this->safeMessage($exception));

            return self::FAILURE;
        } finally {
            unset($password);
        }

        $this->line('database: '.$report['database']);
        $this->line('connection: '.$report['connection']);
        $this->line('laravel_user_id: '.$report['laravel_user_id']);
        $this->line('app_user_id: '.$report['app_user_id']);
        $this->line('email: '.$report['email']);
        $this->line('username: '.$report['username']);
        $this->line('administrator_assigned: '.($report['administrator_assigned'] ? 'yes' : 'no'));
        $this->line('actor_resolved: '.($report['actor_resolved'] ? 'yes' : 'no'));
        $this->line($report['message']);

        return self::SUCCESS;
    }

    private function readPassword(): string
    {
        if ((bool) $this->option('password-stdin')) {
            $line = fgets(STDIN);
            if ($line === false) {
                throw new \RuntimeException('Failed to read password from STDIN.');
            }

            $password = rtrim($line, "\r\n");
            if ($password === '') {
                throw new \RuntimeException('Password from STDIN was empty.');
            }

            return $password;
        }

        $password = (string) $this->secret('Password (never logged)');
        $confirm = (string) $this->secret('Confirm password');

        if ($password === '' || $confirm === '') {
            throw new \RuntimeException('Password is required.');
        }

        if (! hash_equals($password, $confirm)) {
            throw new \RuntimeException('Password confirmation does not match.');
        }

        return $password;
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        // Defensive: strip anything that looks like a bcrypt/argon hash fragment.
        return preg_replace('/\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}/', '[hash-redacted]', $message)
            ?? 'Identity bootstrap failed.';
    }
}
