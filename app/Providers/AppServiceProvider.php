<?php

namespace App\Providers;

use App\TestColumns\PostgresTestColumnWriter;
use App\TestColumns\PostgresTestPointValueWriter;
use App\TestColumns\PostgresTestTableVersionWriter;
use App\TestColumns\SqliteTestColumnWriter;
use App\TestColumns\SqliteTestPointValueWriter;
use App\TestColumns\SqliteTestTableVersionWriter;
use App\TestColumns\TestColumnWriter;
use App\TestColumns\TestPointValueWriter;
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
            app()->isProduction(),
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
