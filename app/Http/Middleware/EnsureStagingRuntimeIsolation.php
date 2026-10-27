<?php

namespace App\Http\Middleware;

use App\StagingRuntime\StagingRuntimeIsolation;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Fail-closed staging browser runtime gate.
 *
 * Runs only when STAGING_RUNTIME_ISOLATION is enabled by the staging launcher.
 * Validates resolved config without opening PostgreSQL. Must execute before
 * StartSession, Authenticate, CSRF, RBAC, and route-model binding.
 */
final class EnsureStagingRuntimeIsolation
{
    public function __construct(
        private readonly StagingRuntimeIsolation $isolation,
        private readonly Repository $config,
        private readonly Application $app,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isolation->enabled($this->config)) {
            return $next($request);
        }

        try {
            $this->isolation->assertSafe(
                $this->config,
                $this->app->configurationIsCached(),
                $this->app->getCachedConfigPath(),
            );
        } catch (Throwable $exception) {
            abort(503, $exception->getMessage());
        }

        return $next($request);
    }
}
