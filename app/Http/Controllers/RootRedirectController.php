<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;

class RootRedirectController extends Controller
{
    public function __invoke(): RedirectResponse
    {

        session([SetLocale::ROOT_BOUNCE_KEY => true]);

        return redirect('/'.session('locale', SetLocale::DEFAULT_LOCALE));
    }
}
