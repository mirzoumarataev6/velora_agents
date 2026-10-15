<?php

namespace App\Http\Controllers;

use App\Services\Teams\HomeDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/home` is where every post-login redirect lands. It used to be a static
 * redirect to `/dashboard`, which requires analytics.view — a role with
 * crm.view and nothing else hit a 403 the instant it signed in. An
 * invokable controller rather than `Route::redirect()`, because the
 * destination now depends on which permission the signed-in user actually
 * holds; see HomeDestination for the ordered list.
 */
class HomeRedirectController extends Controller
{
    public function __invoke(Request $request, HomeDestination $destination): RedirectResponse
    {
        return redirect($destination->url($request->user()));
    }
}
