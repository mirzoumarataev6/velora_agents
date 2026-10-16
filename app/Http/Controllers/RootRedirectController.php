<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;

/**
 * Sends the bare "/" to the locale-prefixed landing page, honoring whatever
 * locale the visitor last used (session), else the default. An invokable
 * controller rather than a route closure so the route table stays cacheable
 * (`php artisan route:cache`).
 */
class RootRedirectController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        // This is the app choosing a locale FOR the visitor — their remembered
        // locale, or the hardcoded default — never the visitor choosing one.
        // Flag it so SetLocale doesn't mistake the /{locale} landing this sends
        // them to for an explicit pick; see SetLocale::ROOT_BOUNCE_KEY.
        session([SetLocale::ROOT_BOUNCE_KEY => true]);

        return redirect('/'.session('locale', SetLocale::DEFAULT_LOCALE));
    }
}
