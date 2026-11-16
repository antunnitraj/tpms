<?php

use App\Http\Middleware\EnsureSetupComplete;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxies are only trusted when configured (TRUSTED_PROXIES, see config/trustedproxy.php):
        // otherwise anyone could pick their own IP address with an X-Forwarded-For header.
        $middleware->redirectGuestsTo(fn () => route('filament.app.auth.login'));
        // The first-run wizard must win over "please log in".
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: EnsureSetupComplete::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
