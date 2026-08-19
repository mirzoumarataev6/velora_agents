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

    public const DEFAULT_LOCALE = 'ru';

    /**
     * Query parameter the switcher uses to carry a locale on pages that have no
     * {locale} path segment to put it in — i.e. the whole dashboard.
     */
    public const QUERY_KEY = 'lang';

    public function handle(Request $request, Closure $next): Response
    {
        $routeLocale = $request->route('locale');

        if ($routeLocale !== null && ! in_array($routeLocale, self::SUPPORTED_LOCALES, true)) {
            $segments = $request->segments();
            $segments[0] = self::DEFAULT_LOCALE;

            return redirect('/'.implode('/', $segments));
        }

        $user = $request->user();

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
