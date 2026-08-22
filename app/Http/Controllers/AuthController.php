<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    /**
     * Marks a session that began with a registration. Only such a session shows
     * the onboarding checklist; see register() for why this is session state
     * rather than a column on the user.
     */
    public const ONBOARDING_SESSION_KEY = 'onboarding_active';

    /**
     * The arrival greeting owed to the next page the owner actually looks at,
     * holding either self::GREETING_WELCOME or self::GREETING_BACK.
     *
     * Deliberately NOT flash data. The auth forms submit with fetch(), which
     * follows the redirect chain itself and throws the resulting HTML away
     * before navigating the browser to the same URL — so a flash would be aged
     * out by requests nobody ever sees, and the page the owner lands on would
     * get nothing. Ordinary session state instead, spent by the layout when it
     * renders a real document; see the layout for the other half.
     */
    public const GREETING_SESSION_KEY = 'greeting';

    public const GREETING_WELCOME = 'welcome';

    public const GREETING_BACK = 'back';

    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ], [
            'email.unique' => __('auth.validation.email_unique'),
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'locale' => session('locale'),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        // Onboarding belongs to the sign-up itself, not to the account. Marking
        // the SESSION rather than the user is what makes "a returning user
        // never sees the checklist, whatever their progress" true by
        // construction: signing in starts a session that was never marked, so
        // there is no state a half-finished setup could leave behind to make
        // the card come back later. Set after regenerate(), which would
        // otherwise be a chance to lose it.
        $request->session()->put(self::ONBOARDING_SESSION_KEY, true);

        // A sign-up gets the welcome greeting, never the "back" one — one key
        // holding one of two values makes that mutually exclusive by
        // construction rather than by agreement between two flags.
        $request->session()->put(self::GREETING_SESSION_KEY, self::GREETING_WELCOME);

        return redirect()->route('home');
    }

    public function login(Request $request): RedirectResponse
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

        // Explicit, even though a signing-in session should never carry this:
        // regenerate() rotates the session ID but KEEPS the data, so anything
        // already in the session survives into the authenticated one. Clearing
        // it here means the "never on login" rule does not depend on that.
        $request->session()->forget(self::ONBOARDING_SESSION_KEY);

        $request->session()->put(self::GREETING_SESSION_KEY, self::GREETING_BACK);

        return redirect()->intended(route('home'));
    }

    private function syncLocale(User $user): void
    {
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

    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        // Which of the two greetings this callback owes: a returning owner gets
        // "back", a first-time one the "welcome" toast — the same choice
        // register() and login() each make, so a Google sign-in is greeted
        // exactly like a form one.
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

        // This one endpoint is both "register" and "log in", so it has to make
        // the same choice both of the other two make — exactly one of the two
        // paths, never both and never neither.
        if ($returning) {
            $request->session()->forget(self::ONBOARDING_SESSION_KEY);
        } else {
            $request->session()->put(self::ONBOARDING_SESSION_KEY, true);
        }

        $request->session()->put(
            self::GREETING_SESSION_KEY,
            $returning ? self::GREETING_BACK : self::GREETING_WELCOME,
        );

        return redirect()->intended(route('home'));
    }
}
