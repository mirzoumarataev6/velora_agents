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
        return redirect('/'.session('locale', SetLocale::DEFAULT_LOCALE));
    }
}
