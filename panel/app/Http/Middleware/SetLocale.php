<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/** Uses the user's own language, or the one picked on the login page, or the browser's. */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale
            ?? $request->session()->get('locale')
            ?? $request->getPreferredLanguage(array_keys(Locales::available()));

        if (Locales::isAvailable($locale)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
