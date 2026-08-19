<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gzip HTML responses when the client asks for it.
 *
 * Why this exists: `php artisan serve` (PHP's built-in server, which the ngrok
 * tunnel fronts) does no content encoding at all, and public/.htaccess's
 * mod_deflate rules only apply when Apache serves the app. The landing page is
 * ~175 KB uncompressed — mostly the embedded three-locale i18n bundle, which is
 * JSON and compresses roughly 5:1 — so without this the tunnel spends seconds
 * pushing bytes that gzip removes almost entirely.
 *
 * Deliberately narrow: HTML only, non-streamed, only when the client advertises
 * gzip and nothing has already set a Content-Encoding. If Apache serves the app
 * instead, mod_deflate sees the Content-Encoding we set and leaves the body
 * alone rather than double-encoding it.
 */
class CompressResponse
{
    /**
     * Below this size gzip's own header/footer overhead and the CPU cost stop
     * being worth it.
     */
    private const MIN_BYTES = 1024;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldCompress($request, $response)) {
            return $response;
        }

        $content = $response->getContent();

        if ($content === false || strlen($content) < self::MIN_BYTES) {
            return $response;
        }

        $compressed = gzencode($content, 6);

        // gzencode() returns false on failure, and on incompressible input the
        // result can be larger than the original — send the original then.
        if ($compressed === false || strlen($compressed) >= strlen($content)) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));

        // Shared caches must not hand a gzipped body to a client that can't
        // decode it.
        $response->headers->set('Vary', trim($response->headers->get('Vary', '').', Accept-Encoding', ', '));

        return $response;
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if ($response->headers->has('Content-Encoding')) {
            return false;
        }

        if (! str_contains(strtolower($request->headers->get('Accept-Encoding', '')), 'gzip')) {
            return false;
        }

        if (! function_exists('gzencode')) {
            return false;
        }

        return str_contains(strtolower($response->headers->get('Content-Type', '')), 'text/html');
    }
}
