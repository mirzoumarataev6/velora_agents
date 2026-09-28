<?php

use App\Http\Middleware\CompressResponse;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RestrictToLocalhost;
use App\Http\Middleware\ServeBuiltAssetsToRemoteClients;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/webhooks.php',
        apiPrefix: 'webhooks',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // routes/internal.php: localhost-only helper endpoints (the
            // Instagram polling microservice today). 'api' middleware, not
            // 'web' — stateless, no CSRF — plus RestrictToLocalhost on top.
            Route::middleware(['api', RestrictToLocalhost::class])
                ->prefix('internal')
                ->group(__DIR__.'/../routes/internal.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The app always sits behind a reverse proxy/tunnel (ngrok in dev,
        // a load balancer in production) that terminates TLS and forwards
        // plain HTTP internally. Without this, route()/url() build http://
        // URLs even when the original request was https://, which breaks
        // anything that needs an absolute HTTPS URL — notably Telegram's
        // setWebhook, which rejects non-HTTPS webhook URLs outright.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [SetLocale::class]);

        // While `npm run dev` runs, @vite() points at 127.0.0.1:5173 — reachable
        // from this machine, not from a phone through the tunnel, which then got
        // pages with no stylesheet at all. Remote clients get the built bundle.
        $middleware->web(append: [ServeBuiltAssetsToRemoteClients::class]);

        // `php artisan serve` does no content encoding, and .htaccess's
        // mod_deflate rules only apply under Apache — so HTML went over the
        // tunnel uncompressed. Appended last so it wraps the finished response.
        $middleware->web(append: [CompressResponse::class]);

        // Inertia's middleware sets the asset version, shares the props every
        // page needs, and turns a redirect-after-PUT/DELETE into a 303 so the
        // browser re-issues it as a GET.
        //
        // Appended LAST, which makes it the innermost of the group — and that
        // position is load-bearing in both directions. On the way IN it must
        // run after SetLocale, or the translations it shares would be built in
        // the default locale rather than the resolved one. On the way OUT it
        // must run BEFORE CompressResponse, because Inertia SETS `Vary:
        // X-Inertia` rather than appending to it: running it last on the
        // response wiped the `Accept-Encoding` that CompressResponse had just
        // added, which would have let a proxy serve a gzipped body to a client
        // that never asked for one. Innermost, both are preserved.
        $middleware->web(append: [HandleInertiaRequests::class]);

        // SetLocale isn't in Laravel's default priority list, so without this
        // it can get sorted to run AFTER the 'auth' middleware on protected
        // routes — meaning a guest redirect to route('login') would fail to
        // resolve the {locale} route parameter. Force it to run first.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: SetLocale::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('webhooks/*') || $request->expectsJson(),
        );
    })->create();
