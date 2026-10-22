<?php

namespace App\Http\Controllers;

use App\Services\Teams\HomeDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeRedirectController extends Controller
{
    public function __invoke(Request $request, HomeDestination $destination): RedirectResponse
    {
        return redirect($destination->url($request->user()));
    }
}
