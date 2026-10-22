<?php

namespace App\Providers;

use App\CalibrationSessions\CalibrationNumberAllocator;
use App\CalibrationSessions\PostgresCalibrationNumberAllocator;
use App\Database\DestructiveCommandGuard;
use App\TestColumns\PostgresTestColumnWriter;
use App\TestColumns\PostgresTestPointValueWriter;
use App\TestColumns\PostgresTestProfileLifecycle;
use App\TestColumns\PostgresTestTableVersionWriter;
use App\TestColumns\SqliteTestColumnWriter;
use App\TestColumns\SqliteTestPointValueWriter;
use App\TestColumns\SqliteTestProfileLifecycle;
use App\TestColumns\SqliteTestTableVersionWriter;
use App\TestColumns\TestColumnWriter;
use App\TestColumns\TestPointValueWriter;
use App\TestColumns\TestProfileLifecycle;
use App\TestColumns\TestTableVersionGuard;
use App\TestColumns\TestTableVersioning;
use App\TestColumns\TestTableVersionWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CalibrationNumberAllocator::class, PostgresCalibrationNumberAllocator::class);

        $this->app->singleton(TestTableVersioning::class);

        $this->app->bind(TestColumnWriter::class, function (): TestColumnWriter {
            return DB::connection()->getDriverName() === 'pgsql'
                ? new PostgresTestColumnWriter
                : new SqliteTestColumnWriter($this->app->make(TestTableVersioning::class));
        });

        $this->app->bind(TestPointValueWriter::class, function (): TestPointValueWriter {
            return DB::connection()->getDriverName() === 'pgsql'
                ? new PostgresTestPointValueWriter
                : new SqliteTestPointValueWriter($this->app->make(TestTableVersioning::class));
        });

        $this->app->bind(TestTableVersionWriter::class, function (): TestTableVersionWriter {
            $guard = $this->app->make(TestTableVersionGuard::class);

            return DB::connection()->getDriverName() === 'pgsql'
                ? new PostgresTestTableVersionWriter($guard)
                : new SqliteTestTableVersionWriter($guard);
        });

        $this->app->bind(TestProfileLifecycle::class, function (): TestProfileLifecycle {
            return DB::connection()->getDriverName() === 'pgsql'
                ? new PostgresTestProfileLifecycle
                : new SqliteTestProfileLifecycle;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            DestructiveCommandGuard::shouldProhibitConfiguredConnections(
                $this->app->make('config'),
                app()->isProduction(),
                app()->environment('testing'),
            ),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
