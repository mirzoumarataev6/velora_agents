<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Services\Teams\HomeDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public const ONBOARDING_SESSION_KEY = 'onboarding_active';

    public const GREETING_SESSION_KEY = 'greeting';

    public const GREETING_WELCOME = 'welcome';

    public const GREETING_BACK = 'back';

    public function register(RegisterRequest $request, HomeDestination $destination): RedirectResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),

            'locale' => session(SetLocale::EXPLICIT_SESSION_KEY) ? session('locale') : SetLocale::DEFAULT_LOCALE,

            'terms_accepted_at' => now(),
            'terms_version' => RegisterRequest::TERMS_VERSION,
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        $request->session()->put(self::ONBOARDING_SESSION_KEY, true);

        $request->session()->put(self::GREETING_SESSION_KEY, self::GREETING_WELCOME);

        return $destination->redirectIntended($request, $user);
    }

    public function login(Request $request, HomeDestination $destination): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.validation.login_failed'),
            ]);
        }

        $request->session()->regenerate();
        $this->syncLocale(Auth::user());

        $request->session()->forget(self::ONBOARDING_SESSION_KEY);

        $request->session()->put(self::GREETING_SESSION_KEY, self::GREETING_BACK);

        return $destination->redirectIntended($request, Auth::user());
    }

    private function syncLocale(User $user): void
    {

        if (session(SetLocale::EXPLICIT_SESSION_KEY)) {
            $user->forceFill(['locale' => session('locale')])->save();

            return;
        }

        if ($user->locale) {
            session(['locale' => $user->locale]);
        } else {
            $user->forceFill(['locale' => session('locale')])->save();
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    public function redirectToGoogle(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback(Request $request, HomeDestination $destination): RedirectResponse
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        $returning = $user !== null;

        if ($user) {
            if (! $user->google_id) {
                $user->forceFill(['google_id' => $googleUser->getId()])->save();
            }
        } else {
            $user = User::create([
                'name' => $googleUser->getName() ?: $googleUser->getNickname(),
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'password' => Hash::make(Str::random(32)),
            ]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();
        $this->syncLocale($user);

        if ($returning) {
            $request->session()->forget(self::ONBOARDING_SESSION_KEY);
        } else {
            $request->session()->put(self::ONBOARDING_SESSION_KEY, true);
        }

        $request->session()->put(
            self::GREETING_SESSION_KEY,
            $returning ? self::GREETING_BACK : self::GREETING_WELCOME,
        );

        return $destination->redirectIntended($request, $user);
    }
}
