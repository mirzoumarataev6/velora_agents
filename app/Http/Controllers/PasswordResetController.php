<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Password reset built on Laravel's Password broker. Mail delivery uses the
 * framework's stock ResetPassword notification and default markdown template —
 * branding the email is deliberately out of scope here.
 */
class PasswordResetController extends Controller
{
    public function request(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        // Always report success: telling an anonymous visitor whether an address
        // is registered is an account-enumeration leak. A genuine throttle is the
        // one case worth surfacing, since the user can act on it by waiting.
        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return back()->with('status', __('auth.forgot.sent'));
    }

    /**
     * The form behind the emailed reset link.
     *
     * The token is read by NAME off the route rather than taken as a method
     * argument, and that is load-bearing. This route lives inside the
     * `{locale}` prefix group, so it has two route parameters — and Laravel
     * binds scalar controller arguments POSITIONALLY, not by name. A
     * `string $token` parameter therefore received the LOCALE ("ru"), which
     * the form then posted back as the reset token, so every reset from an
     * emailed link failed with "invalid token".
     *
     * It went unnoticed because the page still rendered perfectly: nothing on
     * screen shows what is in a hidden field, and the tests asserted the page
     * rendered rather than what it carried.
     */
    public function reset(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $request->route('token'),
            'email' => $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    // Invalidate any "remember me" cookie issued before the reset:
                    // a password change should log out every other device.
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->route('login')->with('status', __('auth.reset.success'));
    }
}
