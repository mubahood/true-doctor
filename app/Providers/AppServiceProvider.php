<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The resolved tenant context for this request/process — one instance
        // per request, set early by App\Http\Middleware\ResolveHospital.
        $this->app->singleton(\App\Support\CurrentHospital::class);

        // Per-hospital billing config (currency/tax/fees) — resolved once per
        // request over CurrentHospital; nothing about money is hardcoded.
        $this->app->singleton(\App\Support\HospitalSettings::class);

        // Which currency to quote a public visitor in. Scoped, so the header,
        // every price on the page and the footer switch all read one answer —
        // and so the work behind it (a header read, or a cached IP lookup)
        // happens once per request rather than once per price.
        $this->app->scoped(\App\Support\VisitorRegion::class);

        // Payment gateway adapter (HMS_PLAN.md §16) — swap the binding to change
        // providers; the app only ever depends on the PaymentGateway interface.
        // Default: Flutterwave, used for patient invoice payments.
        $this->app->bind(
            \App\Services\Gateway\PaymentGateway::class,
            \App\Services\Gateway\FlutterwaveGateway::class,
        );

        // Subscription checkout uses Pesapal instead — a contextual binding,
        // so invoice payments (any hospital's own currency) are untouched
        // while subscriptions (always UGX via Pesapal) get their own adapter.
        // This only covers SubscriptionCheckoutService's OWN PaymentGateway
        // dependency: contextual bindings match whichever class the container
        // is directly building at that moment, so they do not reach through a
        // shared class's nested dependencies. GatewayPaymentService is shared
        // with the Flutterwave invoice flow, so PesapalPaymentController
        // builds its own GatewayPaymentService by hand instead of relying on
        // a (non-cascading) contextual binding here.
        $this->app->when(\App\Services\SubscriptionCheckoutService::class)
            ->needs(\App\Services\Gateway\PaymentGateway::class)
            ->give(\App\Services\Gateway\PesapalGateway::class);

        // Swappable SMS/USSD channel. Defaults to the log driver; bind a live
        // Africa's Talking driver here for production.
        $this->app->bind(
            \App\Services\Channels\VerificationChannel::class,
            \App\Services\Channels\LogChannel::class,
        );

        // Telescope is a dev-only dependency — register its providers only when
        // the package is actually installed (local), so production --no-dev
        // installs work without it.
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(\App\Providers\TelescopeServiceProvider::class);
        }
    }

    /**
     * Tables a device may pull, and which therefore carry a revision.
     *
     * The same list as the `sync_revision` migration. Kept short on purpose:
     * every entry is a stream a device holds a copy of, and the smallest
     * defensible copy of patient data is the one that is not there.
     *
     * @var list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private const SYNC_REVISIONED = [
        \App\Models\Patient::class,
        \App\Models\Visit::class,
        \App\Models\Admission::class,
        \App\Models\VitalRound::class,
        \App\Models\NursingNote::class,
        \App\Models\MedicationAdministration::class,
        \App\Models\LabOrderItem::class,
    ];

    public function boot(): void
    {
        // Cap indexed string columns at 191 chars so utf8mb4 indexes stay within
        // the 1000-byte key limit on older MySQL/MariaDB builds.
        Schema::defaultStringLength(191);

        // Offline sync: every write to a pullable table gets a revision, so a
        // device can ask "what has changed since?" and get an answer that
        // includes what the online panel did. Registered here rather than in
        // each model so a writer cannot forget (see SyncRevisionObserver).
        foreach (self::SYNC_REVISIONED as $model) {
            $model::observe(\App\Observers\SyncRevisionObserver::class);
        }

        // API JSON Resources don't add their own {"data": …} wrapper — the single
        // envelope comes from App\Support\ApiResponse, so resources stay flat.
        \Illuminate\Http\Resources\Json\JsonResource::withoutWrapping();

        // HMS_PLAN.md constraint C14: password policy enforced. Applies
        // everywhere Password::defaults() is used (reset, forced change, API).
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Use our themed pagination view for every ->links() call (admin + trade),
        // instead of the default unstyled Tailwind markup.
        Paginator::defaultView('pagination::simple-default');
        Paginator::defaultSimpleView('pagination::simple-default');

        // The 'api' rate limiter — every /api/* route via $middleware->throttleApi()
        // in bootstrap/app.php (HMS_PLAN.md §3.C). Per authenticated user when
        // signed in (Sanctum), else per IP.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Force the root URL so url() / route() helpers generate correct
        // paths when the app is served from a subdirectory (e.g. /onyx/).
        if ($root = config('app.url')) {
            URL::forceRootUrl($root);

            // Also force HTTPS scheme when APP_URL starts with https
            if (str_starts_with($root, 'https://')) {
                URL::forceScheme('https');
            }
        }

        // `$demo` on every public page: whether this installation has a
        // demonstration door to point at. A composer rather than a @php block
        // in the layout, because a child view's sections are buffered BEFORE
        // the layout runs — so anything the layout defines is undefined inside
        // the page that extends it.
        \Illuminate\Support\Facades\View::composer(
            ['layouts.marketing', 'marketing.*'],
            fn ($view) => $view->with([
                'demo' => \App\Http\Controllers\Auth\AuthenticatedSessionController::demoAvailable(),
                // Which currency to quote this reader in. Shared rather than
                // resolved per page so the header, the prices and the footer
                // switch can never disagree about it on the same render.
                'region' => app(\App\Support\VisitorRegion::class),
            ]),
        );

        // Where somebody already signed in is sent when they land on a
        // sign-in or sign-up form. Without this they go to Laravel's default
        // `/dashboard`, which this application does not have.
        \Illuminate\Auth\Middleware\RedirectIfAuthenticated::redirectUsing(
            fn () => route('admin.dashboard')
        );

        $this->fixLivewireSubdirectoryUrls();
        $this->registerLivewirePersistentMiddleware();

        // Behind a load balancer/CDN the real scheme + client IP come from
        // forwarded headers; trust only the proxies configured in app.trusted_proxies.
        if ($proxies = config('app.trusted_proxies')) {
            \Illuminate\Http\Middleware\TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', (string) $proxies)));
        }
    }

    /**
     * Route middleware that must re-run on every Livewire update request, not
     * only on the initial page load. Livewire's own default list covers
     * Authenticate/Authorize/SubstituteBindings; our access gates (admin,
     * super, subscribed, spatie permission/role) would otherwise be enforced
     * on the first GET only, leaving each component to re-check by hand.
     */
    private function registerLivewirePersistentMiddleware(): void
    {
        \Livewire\Livewire::addPersistentMiddleware([
            \App\Http\Middleware\IsAdmin::class,
            \App\Http\Middleware\IsSuperAdmin::class,
            \App\Http\Middleware\EnsureSubscribed::class,
            \Spatie\Permission\Middleware\PermissionMiddleware::class,
            \Spatie\Permission\Middleware\RoleMiddleware::class,
            \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    }

    /**
     * When the app is served from a subdirectory (APP_URL has a path, e.g.
     * /true-doctor), Livewire 3 emits BOTH its <script src> and its
     * data-update-uri root-relative (/livewire/livewire.js, /livewire/update).
     * Under the subdirectory those 404 at the web server before Laravel ever
     * sees them — the script 404 blanks the page (x-cloak never lifts), and the
     * update 404 makes every wire:click/model fail with Livewire's error
     * overlay showing the server's "Not Found" page.
     *
     * This deployment's request base path is empty (the front controller does
     * not put /true-doctor in SCRIPT_NAME), so URL generation does not restore
     * the prefix on its own. Fix both endpoints explicitly:
     *   - script asset  → livewire.asset_url config
     *   - update route  → registered under the base prefix, so the emitted
     *     data-update-uri carries it. The default (unprefixed) route stays
     *     registered too, so the POST matches whether or not the base is
     *     stripped before routing.
     */
    private function fixLivewireSubdirectoryUrls(): void
    {
        $base = rtrim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');

        if ($base === '') {
            return; // Served from the domain root — Livewire's defaults are correct.
        }

        config(['livewire.asset_url' => $base.'/livewire/livewire.js']);

        // The custom route MUST carry the web group (session, CSRF, ResolveHospital):
        // Livewire's default route adds it, setUpdateRoute() does not.
        \Livewire\Livewire::setUpdateRoute(
            fn ($handle) => \Illuminate\Support\Facades\Route::post($base.'/livewire/update', $handle)
                ->middleware('web')
        );
    }
}
