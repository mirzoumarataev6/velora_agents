<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class ServeBuiltAssetsToRemoteClients
{
    protected const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    public function handle(Request $request, Closure $next): Response
    {
        if (Vite::isRunningHot() && ! in_array($request->getHost(), self::LOOPBACK_HOSTS, true)) {

            Vite::useHotFile(storage_path('framework/vite-hot-ignored-for-remote-clients'));
        }

        return $next($request);
    }
}
