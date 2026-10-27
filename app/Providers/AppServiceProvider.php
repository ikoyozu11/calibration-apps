<?php

namespace App\Providers;

use App\Authorization\ApplicationCapability;
use App\Authorization\LiveDatabaseIdentity;
use App\Authorization\ResolveApplicationRole;
use App\Authorization\ResolvesLiveDatabaseIdentity;
use App\Authorization\StagingAuthConnection;
use App\CalibrationSessions\CalibrationNumberAllocator;
use App\CalibrationSessions\PostgresCalibrationNumberAllocator;
use App\Database\DestructiveCommandGuard;
use App\Http\Middleware\EnsureApplicationRole;
use App\Models\User;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CalibrationNumberAllocator::class, PostgresCalibrationNumberAllocator::class);
        $this->app->singleton(ResolveApplicationRole::class);
        $this->app->singleton(ResolvesLiveDatabaseIdentity::class, LiveDatabaseIdentity::class);
        $this->app->singleton(StagingAuthConnection::class);

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
        $this->configureAuthorizationGates();

        Livewire::addPersistentMiddleware([
            EnsureApplicationRole::class,
        ]);
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

    /**
     * Register capability Gates from trusted role storage.
     */
    protected function configureAuthorizationGates(): void
    {
        $roles = $this->app->make(ResolveApplicationRole::class);

        foreach (ApplicationCapability::cases() as $capability) {
            Gate::define($capability->value, function (?User $user) use ($roles, $capability): bool {
                if ($user === null) {
                    return false;
                }

                return $roles->allows((int) $user->id, $capability);
            });
        }
    }
}
