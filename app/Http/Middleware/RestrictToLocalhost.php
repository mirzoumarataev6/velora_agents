<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
