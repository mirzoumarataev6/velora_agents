<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function dismiss(Request $request): JsonResponse
    {
        $request->user()->dismissOnboarding();

        $request->session()->forget(AuthController::ONBOARDING_SESSION_KEY);

        return response()->json(['dismissed' => true]);
    }
}
