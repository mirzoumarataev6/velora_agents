<?php

use App\Http\Controllers\Internal\InstagramIncomingController;
use Illuminate\Support\Facades\Route;

// Localhost-only routes (see App\Http\Middleware\RestrictToLocalhost, applied
// in bootstrap/app.php's withRouting(then:) callback) for the app's own
// helper processes to call in — currently just the Instagram polling
// microservice. No CSRF (the 'api' middleware group carries none) and no
// auth: the IP restriction is the whole access control.
Route::post('instagram/incoming', [InstagramIncomingController::class, 'handle'])->name('internal.instagram.incoming');
