<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * @var list<string>
     */
    public const SUPPORTED_LOCALES = ['ru', 'tg', 'en'];

    // Tajik, not Russian: this is what a visitor who has never chosen a
    // language gets, everywhere that falls through to it (root redirect, an
    // unsupported locale segment, a session with no recorded choice). Russian
    // stays fully supported — it is simply no longer the fallback.
    public const DEFAULT_LOCALE = 'tg';

    /**
     * Query parameter the switcher uses to carry a locale on pages that have no
     * {locale} path segment to put it in — i.e. the whole dashboard.
     */
    public const QUERY_KEY = 'lang';

    /**
     * Set once a visitor has genuinely picked a language — the switcher, or a
     * /ru /en /tg landing link — as opposed to merely being shown one by a
     * fallback. AuthController trusts this to decide whether a pre-login
     * locale should overwrite an account's stored one, or be written onto a
     * brand-new account at all. See ROOT_BOUNCE_KEY for the one case below
     * that looks identical to a real pick but must not set this.
     */
    public const EXPLICIT_SESSION_KEY = 'locale_explicit';

    /**
     * Set by RootRedirectController immediately before it sends "/" on to a
     * remembered-or-default /{locale} landing page. That bounce is the app
     * choosing FOR the visitor, not the visitor choosing — but it lands on
     * exactly the route-segment branch below that a deliberate /ru /en /tg
     * visit also hits, so without this flag the bounce was indistinguishable
     * from a real pick. That let register() bake "ru" onto a brand-new
     * account for a guest who had touched nothing but "/". Consumed (and
     * cleared) on exactly the one request the bounce produces.
     */
    public const ROOT_BOUNCE_KEY = 'locale_root_bounce';

    public function handle(Request $request, Closure $next): Response
    {
        $routeLocale = $request->route('locale');

        if ($routeLocale !== null && ! in_array($routeLocale, self::SUPPORTED_LOCALES, true)) {
            $segments = $request->segments();
            $segments[0] = self::DEFAULT_LOCALE;

            return redirect('/'.implode('/', $segments));
        }

        $user = $request->user();
        $bounced = session()->pull(self::ROOT_BOUNCE_KEY, false);

        // The dashboard's URLs carry no locale prefix, so the switcher used to
        // POST the choice in the background and navigate in the same tick. The
        // two requests raced, the navigation usually won, and the page came
        // back in the OLD locale — the change only showed up on the click after
        // it. Reading the locale off THIS request instead makes one click
        // enough: it is applied before the response is rendered.
        //
        // The path stays authoritative when both are present, so a stale ?lang=
        // in a shared link can never contradict the /{locale}/ page it points at.
        $chosen = $routeLocale ?? $this->queryLocale($request);

        if ($chosen !== null) {
            session(['locale' => $chosen]);

            if (! $bounced) {
                session([self::EXPLICIT_SESSION_KEY => true]);
            }

            if ($user && $user->locale !== $chosen) {
                $user->forceFill(['locale' => $chosen])->save();
            }
        }

        $locale = $chosen ?? $user?->locale ?? session('locale', self::DEFAULT_LOCALE);

        App::setLocale($locale);
        URL::defaults(['locale' => $locale]);

        return $next($request);
    }

    /**
     * The ?lang= value, but only when it names a locale we actually serve —
     * anything else is ignored rather than falling back, so a junk value leaves
     * the visitor's existing locale alone instead of resetting it to Russian.
     */
    private function queryLocale(Request $request): ?string
    {
        $value = $request->query(self::QUERY_KEY);

        return is_string($value) && in_array($value, self::SUPPORTED_LOCALES, true)
            ? $value
            : null;
    }
}
