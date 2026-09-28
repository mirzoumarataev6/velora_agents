<?php

namespace App\Http\Middleware;

use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * Inertia compares this against the version the browser already has and
     * forces a hard reload when they differ, so a deploy cannot leave someone
     * on a stale JS bundle making requests against new props.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every Inertia response.
     *
     * Kept deliberately small. Anything page-specific belongs in that page's
     * controller — every byte here rides along with EVERY response, including
     * the partial reloads Inertia makes on a filter change.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'auth' => [
                // A closure, so the query is only run for a response that
                // actually reads it. Guests (the auth pages) never touch it.
                'user' => fn () => $request->user() === null ? null : [
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'initials' => $request->user()->initials(),
                    'theme' => $request->user()->theme,
                ],
            ],

            /*
             * Translations, for the CURRENT locale only.
             *
             * The Blade product embeds every locale on every page and swaps
             * [data-i18n] nodes in place, because a full server round trip to
             * change language was too slow to feel like a toggle. Inertia
             * removes the reason for that machinery: switching locale is a
             * visit that re-renders props and patches the component tree,
             * with no document reload and no second copy of the strings.
             *
             * So this ships one locale instead of three, and the bespoke
             * engine in partials/i18n-bundle retires with the last Blade page.
             */
            'locale' => [
                'current' => app()->getLocale(),
                'supported' => SetLocale::SUPPORTED_LOCALES,
            ],
            'translations' => fn () => $this->translations(),

            /*
             * One-shot messages. `status` is what the existing controllers
             * already flash, so nothing server-side had to change to support
             * this; `errors` comes from the parent's share().
             *
             * `greeting` is the arrival toast — "Добро пожаловать, Надя" on a
             * sign-up, "С возвращением" on a sign-in. It is spent HERE, by the
             * response that carries it, which is what makes it appear exactly
             * once.
             *
             * A page may claim it first: the agents screen turns the sign-up
             * greeting into a banner above its checklist instead (see
             * AgentController@index), and because a controller runs before
             * these closures are resolved, this then finds nothing left and
             * correctly stays quiet. One greeting per arrival, either way.
             */
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'greeting' => fn () => $this->greeting($request),
            ],

            /*
             * The one-time welcome, by name.
             *
             * Reading it SPENDS it — `markWelcomed()` is idempotent and stamps
             * the account — so the modal cannot reappear on the next page. That
             * is a side effect in a shared prop, which is unusual and
             * deliberate: the stamp has to happen on the response that shows
             * the welcome, and nothing else knows which response that is.
             */
            'welcome' => fn () => $this->welcome($request),

            // Named routes, so a Vue component can call route() the way a Blade
            // view does instead of being handed a URL for every link.
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }

    /**
     * The arrival greeting owed to this response, translated — or null.
     */
    private function greeting(Request $request): ?string
    {
        $kind = $request->session()->pull(AuthController::GREETING_SESSION_KEY);

        $key = match ($kind) {
            AuthController::GREETING_WELCOME => 'onboarding.welcome_greeting',
            AuthController::GREETING_BACK => 'onboarding.back',
            default => null,
        };

        return $key === null ? null : __($key, ['name' => $request->user()?->name]);
    }

    /**
     * The owner's name, if they have never been welcomed — and stamp them.
     */
    private function welcome(Request $request): ?string
    {
        $user = $request->user();

        if ($user === null || ! $user->awaitsWelcome()) {
            return null;
        }

        $user->markWelcomed();

        return $user->name;
    }

    /**
     * Every translation namespace, in the active locale.
     *
     * Loaded wholesale rather than per-page: the thirteen files total a few
     * hundred lines, which is far smaller than the three-locale bundle the
     * Blade pages already embed, and picking namespaces per page is exactly
     * the kind of bookkeeping that goes wrong silently when a component is
     * moved to another screen.
     *
     * @return array<string, mixed>
     */
    private function translations(): array
    {
        $locale = app()->getLocale();
        $messages = [];

        foreach (glob(lang_path($locale.'/*.php')) as $file) {
            $messages[basename($file, '.php')] = require $file;
        }

        return $messages;
    }
}
