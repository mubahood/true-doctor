<?php

namespace App\Http\Middleware;

use App\Support\Edition;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireEdition
{
    public function handle(Request $request, Closure $next, string $edition): Response
    {
        abort_unless(Edition::name() === $edition, 404);

        return $next($request);
    }
}
