<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behind an HTTPS proxy that isn't in TRUSTED_PROXIES (a Cloudflare Tunnel, say), requests arrive as
 * plain HTTP, so every link would point to http:// and the browser would block sign-in. When APP_URL is
 * https, requests for its host are treated as HTTPS. Other hosts (e.g. the LAN address) are left alone.
 */
class UseAppUrlScheme
{
    public function handle(Request $request, Closure $next): Response
    {
        $appUrl = parse_url((string) config('app.url'));

        if (! $request->isSecure()
            && ($appUrl['scheme'] ?? null) === 'https'
            && strcasecmp($request->getHost(), $appUrl['host'] ?? '') === 0) {
            $request->server->set('HTTPS', 'on');
        }

        return $next($request);
    }
}
