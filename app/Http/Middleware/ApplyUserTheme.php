<?php

namespace App\Http\Middleware;

use App\Enums\Theme;
use Closure;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Swaps the panel's colours for the signed-in user's theme (runs after the panel has registered its defaults). */
class ApplyUserTheme
{
    public function handle(Request $request, Closure $next): Response
    {
        // Always registered, so a long-lived worker never carries one user's colours to the next.
        FilamentColor::register(($request->user()?->theme() ?? Theme::DEFAULT)->colors());

        return $next($request);
    }
}
