<?php

namespace App\Http\Middleware;

use App\Support\Edition;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = config('edition.installed_path');
        if (! is_string($path)) {
            abort(503, 'Installation identity is not configured.');
        }

        // Refuse an accidental change of APP_EDITION on an installed server.
        // A standalone database with a SaaS super-admin is an unscoped read.
        if (is_file($path)) {
            $state = json_decode((string) file_get_contents($path), true);
            if (! is_array($state) || ($state['edition'] ?? null) !== Edition::name()) {
                abort(503, 'Installation identity does not match APP_EDITION.');
            }

            return $next($request);
        }

        if (Edition::isSaas()) {
            return $next($request);
        }

        return $request->is('api/*')
            ? response()->json(['message' => 'Installation is not complete.'], 503)
            : redirect()->to(url('/install.php'));
    }
}
