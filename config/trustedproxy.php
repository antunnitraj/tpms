<?php

/*
 * Reverse proxies whose X-Forwarded-* headers are believed (client IP, https).
 * Empty (the default) when the app is published directly, as docker-compose.yml
 * does. Behind a proxy, set TRUSTED_PROXIES to its address (e.g. 172.18.0.1 or
 * 192.168.1.10), a comma-separated list, or * for any. HTTPS links don't depend
 * on it: an https APP_URL is enough (see UseAppUrlScheme).
 */

$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    'proxies' => match ($proxies) {
        '' => null,
        '*' => '*',
        default => array_values(array_filter(array_map('trim', explode(',', $proxies)))),
    },
];
