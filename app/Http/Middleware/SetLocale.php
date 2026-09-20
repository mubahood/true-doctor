<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the visitor's language. Priority: ?lang= (also remembered in the
 * session) → session → app default. Only the enabled languages are honoured.
 */
class SetLocale
{
    private const SUPPORTED = ['en', 'lg', 'sw'];

    public function handle(Request $request, Closure $next): Response
    {
        $hasSession = $request->hasSession();
        $lang = $request->query('lang')
            ?? ($hasSession ? $request->session()->get('locale') : null);

        if (in_array($lang, self::SUPPORTED, true)) {
            app()->setLocale($lang);
            if ($hasSession) {
                $request->session()->put('locale', $lang);
            }
        }

        return $next($request);
    }
}
