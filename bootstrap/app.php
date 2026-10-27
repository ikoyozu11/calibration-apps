<?php

use App\Http\Middleware\EnsureApplicationRole;
use App\Http\Middleware\EnsureStagingRuntimeIsolation;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'application.role' => EnsureApplicationRole::class,
        ]);

        // Staging isolation must run before session/auth/CSRF can touch a database.
        $middleware->prepend(EnsureStagingRuntimeIsolation::class);

        // Deny roleless users before route-model binding hits domain tables.
        $middleware->priority([
            EnsureStagingRuntimeIsolation::class,
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            PreventRequestForgery::class,
            Authenticate::class,
            EnsureApplicationRole::class,
            SubstituteBindings::class,
        ]);

        $middleware->redirectGuestsTo(fn (): string => route('login'));
        $middleware->redirectUsersTo(fn (): string => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
