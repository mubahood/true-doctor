<?php

use App\Enums\ApiErrorCode;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // HMS_PLAN.md §3.C — rate limiting on every API route. Laravel's
        // slimmed-down skeleton leaves the `api` group unthrottled unless
        // asked; the 'api' limiter itself is defined in AppServiceProvider.
        $middleware->throttleApi();

        // Field Mode is a browser on this same origin, signed in with the same
        // session as the rest of the panel. Without this the `api` group has no
        // StartSession at all, so `auth:sanctum` can only see a bearer token —
        // and a logged-in browser got 401 on every sync request while /admin
        // answered 200. Offline mode could not sync at all.
        //
        // Cookie over token deliberately: the credential stays HttpOnly and
        // unreadable by script, where a personal access token would have to
        // live in IndexedDB on a shared ward machine for its whole 30-day life.
        // Requests whose Origin is not one of `sanctum.stateful` are untouched,
        // so integration clients still authenticate by token.
        $middleware->statefulApi();

        // Flutterwave webhook is server-to-server (no session/CSRF token);
        // authenticity is verified via the verif-hash signature in the controller.
        $middleware->validateCsrfTokens(except: [
            'gateway/flutterwave/webhook',
        ]);

        $middleware->redirectGuestsTo(fn () => route('admin.login'));

        // Trusted proxies are configured from app.trusted_proxies in
        // AppServiceProvider::boot() (config is not bound yet at this point).

        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\ResolveHospital::class,
            \App\Http\Middleware\RequirePasswordChange::class,
        ]);
        $middleware->api(append: [
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\ResolveHospital::class,
        ]);

        // Tenancy is safety-critical (HMS_PLAN.md §2.1): resolve the current
        // hospital BEFORE route-model binding, so implicit bindings of tenant
        // models are already scoped. Appended middleware otherwise run *after*
        // SubstituteBindings, letting a bind resolve before the tenant is known
        // — a cross-tenant read (caught by PatientTenancyIsolationTest).
        $middleware->priority([
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authenticate::class,
            \App\Http\Middleware\ResolveHospital::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\IsAdmin::class,
            'super' => \App\Http\Middleware\IsSuperAdmin::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'subscribed' => \App\Http\Middleware\EnsureSubscribed::class,
            // A disabled account or a temporary password, on the API.
            'api.account' => \App\Http\Middleware\EnsureApiAccount::class,
            'onboarding' => \App\Http\Middleware\RequireOnboarding::class,
            'no-store' => \App\Http\Middleware\NoStoreResponses::class,
            // Records where a visitor came from and routes an ad click to
            // the page its sitelink named. Public pages only.
            'landing' => \App\Http\Middleware\HandleLandingTraffic::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // HMS_PLAN.md §3.D — one consistent JSON envelope for every API
        // error, never Laravel's default ad hoc shape per exception type.
        $wantsJson = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(function (ValidationException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                $e->getMessage(),
                $e->status,
                $e->errors(),
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(ApiErrorCode::Unauthenticated, $e->getMessage(), 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(ApiErrorCode::Forbidden, $e->getMessage(), 403);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return ApiResponse::error(ApiErrorCode::NotFound, 'Resource not found.', 404);
        });

        // Catches everything else with an HTTP status (404 route-not-found,
        // 429 throttled, abort(403, '...') as EnsureSubscribed uses, ...).
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            $status = $e->getStatusCode();
            $code = match (true) {
                $status === 403 => ApiErrorCode::Forbidden,
                $status === 404 => ApiErrorCode::NotFound,
                $status === 429 => ApiErrorCode::TooManyRequests,
                $status >= 500 => ApiErrorCode::ServerError,
                default => ApiErrorCode::ServerError,
            };

            return ApiResponse::error(
                $code,
                $e->getMessage() ?: 'An error occurred.',
                $status,
            );
        });

        $exceptions->render(function (\Throwable $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request) || $e instanceof HttpExceptionInterface) {
                return null;
            }

            $message = config('app.debug') ? $e->getMessage() : 'Something went wrong.';

            return ApiResponse::error(ApiErrorCode::ServerError, $message, 500);
        });
    })->create();
