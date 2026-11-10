<?php

namespace App\Console\Commands;

use App\Authorization\CreateStagingTestUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('staging:create-test-user
    {--name= : Display name}
    {--username= : Unique app_user username}
    {--email= : Unique email address}
    {--role= : Engineer or Viewer}
    {--confirm-database= : Must be exactly calibration_staging_db}
    {--confirm-write= : Must be exactly AUTHORIZED}
    {--yes : Skip the final confirmation prompt}')]
#[Description('Create one Engineer or Viewer test identity on calibration_staging_db.')]
final class CreateStagingTestUserCommand extends Command
{
    public function handle(CreateStagingTestUser $creator): int
    {
        $displayName = trim((string) ($this->option('name') ?: $this->ask('Full name')));
        $username = trim((string) ($this->option('username') ?: $this->ask('Username')));
        $email = trim((string) ($this->option('email') ?: $this->ask('Email')));
        $role = trim((string) ($this->option('role') ?: $this->ask('Role (Engineer or Viewer)')));
        $confirmDatabase = (string) $this->option('confirm-database');
        $confirmWrite = (string) $this->option('confirm-write');

        if ($confirmDatabase !== CreateStagingTestUser::REQUIRED_DATABASE
            || $confirmWrite !== CreateStagingTestUser::WRITE_CONFIRMATION) {
            $this->error('Explicit staging database and write confirmations are required.');

            return self::FAILURE;
        }

        try {
            $password = (string) $this->secret('Password (never logged)');
            $confirmation = (string) $this->secret('Confirm password');

            if (! (bool) $this->option('yes')) {
                $this->line('Target database: '.CreateStagingTestUser::REQUIRED_DATABASE);
                $this->line('Name: '.$displayName);
                $this->line('Username: '.$username);
                $this->line('Email: '.$email);
                $this->line('Role: '.$role);

                if (! $this->confirm('Create this staging test user? This writes one account.', false)) {
                    $this->warn('Aborted without writes.');

                    return self::FAILURE;
                }
            }

            $report = $creator->run(
                $displayName,
                $username,
                $email,
                $role,
                $password,
                $confirmation,
                $confirmDatabase,
                $confirmWrite,
            );
        } catch (Throwable $exception) {
            $this->error($this->safeMessage($exception));

            return self::FAILURE;
        } finally {
            unset($password, $confirmation);
        }

        $this->line('database: '.$report['database']);
        $this->line('connection: '.$report['connection']);
        $this->line('laravel_user_id: '.$report['laravel_user_id']);
        $this->line('app_user_id: '.$report['app_user_id']);
        $this->line('email: '.$report['email']);
        $this->line('username: '.$report['username']);
        $this->line('role: '.$report['role']);
        $this->info($report['message']);

        return self::SUCCESS;
    }

    private function safeMessage(Throwable $exception): string
    {
        return preg_replace('/\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}/', '[hash-redacted]', $exception->getMessage())
            ?? 'Staging test user creation failed.';
    }
}
