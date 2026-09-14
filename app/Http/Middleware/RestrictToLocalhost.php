<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for endpoints meant to be reachable only from processes on this same
 * machine (the Instagram polling microservice, today).
 *
 * Deliberately reads $request->server('REMOTE_ADDR') rather than $request->ip():
 * bootstrap/app.php calls trustProxies(at: '*') so the app works behind the
 * ngrok tunnel, which means ->ip() honors an X-Forwarded-For header from ANY
 * client — anyone who can reach this endpoint at all could claim to be
 * 127.0.0.1 through it. REMOTE_ADDR is the raw TCP peer address, set by the
 * connection itself; it cannot be spoofed by a header.
 */
class RestrictToLocalhost
{
    protected const ALLOWED_IPS = ['127.0.0.1', '::1'];

    public function handle(Request $request, Closure $next): Response
    {
        $remoteAddr = (string) $request->server('REMOTE_ADDR');

        if (! in_array($remoteAddr, self::ALLOWED_IPS, true)) {
            abort(403, 'This endpoint only accepts connections from localhost.');
        }

        return $next($request);
    }
}
