<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const SUPPORTED_LOCALES = ['ru', 'tg', 'en'];

    public const DEFAULT_LOCALE = 'tg';

    public const QUERY_KEY = 'lang';

    public const EXPLICIT_SESSION_KEY = 'locale_explicit';

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

    private function queryLocale(Request $request): ?string
    {
        $value = $request->query(self::QUERY_KEY);

        return is_string($value) && in_array($value, self::SUPPORTED_LOCALES, true)
            ? $value
            : null;
    }
}
