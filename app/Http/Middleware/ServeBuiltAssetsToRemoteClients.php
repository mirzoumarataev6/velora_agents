<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve the compiled /build bundle to every client that is not on this machine,
 * even while the Vite dev server is running.
 *
 * THE BUG THIS CLOSES. `composer run dev` starts Vite, which writes public/hot
 * containing http://127.0.0.1:5173. While that file exists, @vite() points every
 * page's CSS at it. A browser on the dev machine can reach it; a phone opening
 * the app through the ngrok tunnel cannot — 127.0.0.1 is the phone itself — so
 * it got the HTML with no stylesheet at all: /register rendered unstyled, with
 * an unsized SVG chevron filling the screen. The landing looked fine only
 * because it ships its own inline stylesheet rather than the bundle.
 *
 * A request addressed to a loopback host keeps hot reload. Anything else — the
 * tunnel domain, a LAN IP — is switched to the built manifest for this request.
 * The trade-off: a phone sees the last `npm run build`, not unsaved edits.
 */
class ServeBuiltAssetsToRemoteClients
{
    protected const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    public function handle(Request $request, Closure $next): Response
    {
        if (Vite::isRunningHot() && ! in_array($request->getHost(), self::LOOPBACK_HOSTS, true)) {
            // A path that never exists: Vite then reads the build manifest.
            Vite::useHotFile(storage_path('framework/vite-hot-ignored-for-remote-clients'));
        }

        return $next($request);
    }
}
