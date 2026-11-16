<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Until the first account exists, every page leads to the setup wizard;
 * afterwards the wizard sends people on to TPMS (or its sign-in page).
 */
class EnsureSetupComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $onSetup = $request->routeIs('filament.app.setup');

        if (static::isComplete()) {
            return $onSetup ? redirect()->to(Filament::getUrl()) : $next($request);
        }

        return $onSetup ? $next($request) : redirect()->route('filament.app.setup');
    }

    /** Checked on every request (one indexed query), so a full reset takes effect at once on every worker. */
    public static function isComplete(): bool
    {
        return User::query()->exists();
    }
}
