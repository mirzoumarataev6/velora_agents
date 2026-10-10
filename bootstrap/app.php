<?php

use App\Http\Middleware\CompressResponse;
use App\Http\Middleware\RestrictToLocalhost;
use App\Http\Middleware\ServeBuiltAssetsToRemoteClients;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        // Implicit route-model binding (e.g. a {image} parameter typed as
        // ProductImage) throws ModelNotFoundException straight out of the
        // router, before any controller code runs — so a missing/already-
        // deleted row's own controller never gets a chance to answer with
        // something softer. Laravel's own default JSON rendering for it is
        // "No query results for model [App\Models\ProductImage] 13"
        // verbatim, which is exactly the raw, class-name-leaking text an
        // owner isn't supposed to see.
        //
        // This has to be registered for NotFoundHttpException, NOT
        // ModelNotFoundException itself: the base Handler's
        // prepareException() unconditionally rewraps every
        // ModelNotFoundException into `new NotFoundHttpException($e
        // ->getMessage(), $e)` — carrying the raw message forward as the
        // HTTP exception's OWN message — before any renderable callback
        // ever runs, so a callback typed to the original exception class
        // is never invoked at all. Checking getPrevious() recovers the
        // "was this actually a missing model" distinction from a plain
        // 404 that used abort(404) directly (left alone, its message is
        // already empty/safe).
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (($request->is('api/*') || $request->is('webhooks/*') || $request->expectsJson())
                && $e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json(['message' => __('common.error_not_found')], 404);
            }
        });
    })->create();
