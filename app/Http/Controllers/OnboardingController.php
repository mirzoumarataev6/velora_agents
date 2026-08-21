<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Skipping the first-run checklist.
 *
 * Like SettingsController, there is no {user} parameter here on purpose: the
 * action always applies to the signed-in account, so there is no id for a
 * request to tamper with. Everything else about onboarding is derived at
 * render time by OnboardingService and needs no endpoint at all.
 */
class OnboardingController extends Controller
{
    public function dismiss(Request $request): JsonResponse
    {
        $request->user()->dismissOnboarding();

        // Belt and braces: the stored stamp above already hides the card, but
        // dropping the session marker means the current session stops being a
        // registration session too, so nothing can bring the checklist back
        // before the next page load writes anything.
        $request->session()->forget(AuthController::ONBOARDING_SESSION_KEY);

        return response()->json(['dismissed' => true]);
    }
}
