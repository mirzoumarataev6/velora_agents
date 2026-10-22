<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse|Response
    {
        $locale = $request->input('locale');

        if (! in_array($locale, SetLocale::SUPPORTED_LOCALES, true)) {
            $locale = SetLocale::DEFAULT_LOCALE;
        }

        session(['locale' => $locale, SetLocale::EXPLICIT_SESSION_KEY => true]);

        if ($request->user()) {
            $request->user()->forceFill(['locale' => $locale])->save();
        }

        App::setLocale($locale);
        URL::defaults(['locale' => $locale]);

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return redirect($this->backTo($request, $locale));
    }

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
