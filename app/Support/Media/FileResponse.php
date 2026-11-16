<?php

namespace App\Support\Media;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Serves a stored file so it can never run script on the app's own address:
 * only types a browser shows passively open in the browser, everything else
 * (HTML, SVG, XML, …) is downloaded, and the browser may not guess another type.
 */
class FileResponse
{
    private const INLINE = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
        'application/pdf', 'text/plain',
        'video/mp4', 'video/webm', 'video/quicktime', 'audio/mpeg', 'audio/mp4', 'audio/wav',
    ];

    public static function make(string $path, ?string $mime, string $name, bool $download, string $cacheControl): BinaryFileResponse
    {
        $mime = $mime ?: 'application/octet-stream';
        $inline = ! $download && in_array($mime, self::INLINE, true);

        $response = response()->file($path, [
            'Content-Type' => $mime,
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // Opened on its own, a file is an isolated page with no scripts. (Not PDFs:
        // browsers' PDF viewers don't run in a sandbox, and they don't run page scripts.)
        if ($mime !== 'application/pdf') {
            $response->headers->set('Content-Security-Policy', 'sandbox');
        }

        $name = str_replace(['/', '\\'], '-', $name);

        return $response->setContentDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $name,
            str_replace(['%', '/', '\\'], '_', Str::ascii($name)),
        );
    }
}
