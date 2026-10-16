<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;

/**
 * Persists a locale choice.
 *
 * Two callers, deliberately different:
 *   - The <form> in each switcher — the no-JS fallback, and what the browser
 *     submits if the i18n engine never boots. It expects a redirect back to the
 *     page the visitor was on, in the locale they just picked.
 *   - The landing/auth instant switch, which has already repainted itself from
 *     the embedded bundle and only needs the choice recorded. It sends
 *     Accept: application/json and wants no body.
 *
 * The dashboard no longer routes through here at all: it navigates with
 * ?lang=, which SetLocale applies to that same request. See that middleware.
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse|Response
    {
        $locale = $request->input('locale');

        if (! in_array($locale, SetLocale::SUPPORTED_LOCALES, true)) {
            $locale = SetLocale::DEFAULT_LOCALE;
        }

        // A switcher click is always a real pick, never the default fallback —
        // mark it explicit so a pre-login choice survives login()/register()
        // instead of being overwritten by, or merged into, the account's own
        // stored locale. See SetLocale::EXPLICIT_SESSION_KEY.
        session(['locale' => $locale, SetLocale::EXPLICIT_SESSION_KEY => true]);

        if ($request->user()) {
            $request->user()->forceFill(['locale' => $locale])->save();
        }

        // SetLocale ran for this request before the choice was made, so the app
        // is still in the previous locale. Re-apply it here: the redirect target
        // is built below from route defaults, and any flash message set between
        // here and the response must be translated in the locale just chosen.
        App::setLocale($locale);
        URL::defaults(['locale' => $locale]);

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return redirect($this->backTo($request, $locale));
    }

    /**
     * Where to send the visitor back to.
     *
     * A plain back() would return them to the URL they came from — which on a
     * locale-prefixed page (/ru/login) still names the OLD locale, and that
     * prefix wins over the session in SetLocale. So the leading segment is
     * rewritten when it is one. Unprefixed pages (the dashboard) are returned
     * untouched and pick the locale up from the session/user record.
     */
    private function backTo(Request $request, string $locale): string
    {
        $parts = parse_url(url()->previous());

        $path = $parts['path'] ?? '/';
        $segments = explode('/', trim($path, '/'));

        if (in_array($segments[0], SetLocale::SUPPORTED_LOCALES, true)) {
            $segments[0] = $locale;
            $path = '/'.implode('/', $segments);
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
