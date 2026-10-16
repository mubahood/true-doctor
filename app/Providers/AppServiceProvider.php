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
        $this->app->bind(\App\Services\Backups\MakesArchive::class, \App\Services\Install\InstallationBackups::class);

        // The resolved tenant context for this request/process — one instance
        // per request, set early by App\Http\Middleware\ResolveHospital.
        $this->app->singleton(\App\Support\CurrentHospital::class);

        // Per-hospital billing config (currency/tax/fees) — resolved once per
        // request over CurrentHospital; nothing about money is hardcoded.
        $this->app->singleton(\App\Support\HospitalSettings::class);
        $this->app->scoped(\App\Support\Licensing\InstalledLicence::class);

        // The chart of accounts remembers each hospital's system accounts once
        // it has looked them up — per request or job, so a long-running worker
        // never posts into an account it read yesterday.
        $this->app->scoped(\App\Services\Finance\ChartOfAccounts::class);

        // Which currency to quote a public visitor in.
        //
        // Deliberately NOT scoped or singleton. It takes the Request in its
        // constructor, so a cached instance keeps answering from whichever
        // request happened to build it — correct under FPM, where the
        // container dies with the request, and wrong under anything that
        // reuses one (Octane, a queue worker, a test making two requests).
        // Resolving it fresh costs a cookie read and a header parse.
        $this->app->bind(\App\Support\VisitorRegion::class);

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
        if (\App\Support\Edition::isSaas() && $this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
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
        // Printables resolve files from a folder that exists on every hosting layout.
        self::pointPdfAtAFolderThatExists();

        // @canuse('ipd.view') … @endcanuse: allowed to, and part of this
        // practice — a dental clinic has the permission matrix of every
        // hospital but no beds to show. (Practice::allows)
        \Illuminate\Support\Facades\Blade::if('canuse', fn (string $permission): bool => auth()->user()?->can($permission) === true
            && \App\Support\Practice\Practice::current()->allows($permission));

        // Cap indexed string columns at 191 chars so utf8mb4 indexes stay within
        // the 1000-byte key limit on older MySQL/MariaDB builds.
        Schema::defaultStringLength(191);

        // The chart of accounts remembers the accounts it looked up — but a
        // chart first created inside a transaction that then rolled back (a
        // dispense that failed on its second item) no longer exists, and
        // posting to the ids it remembered would fail on every later attempt.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class, function (): void {
            if ($this->app->resolved(\App\Services\Finance\ChartOfAccounts::class)) {
                $this->app->make(\App\Services\Finance\ChartOfAccounts::class)->forget();
            }
        });

        // Offline sync: every write to a pullable table gets a revision, so a
        // device can ask "what has changed since?" and get an answer that
        // includes what the online panel did. Registered here rather than in
        // each model so a writer cannot forget (see SyncRevisionObserver).
        foreach (self::SYNC_REVISIONED as $model) {
            $model::observe(\App\Observers\SyncRevisionObserver::class);
        }

        // Data plane (docs/api/plane.md): a revision per reference section, and
        // a tombstone when a listed record is deleted or closed out of a
        // worklist. Observers, not schedules - a standalone cron may run only
        // every 19 minutes.
        \App\Support\ReferenceRevision::observe();
        foreach (array_keys(\App\Observers\TombstoneObserver::ENTITIES) as $model) {
            $model::observe(\App\Observers\TombstoneObserver::class);
        }

        // API JSON Resources don't add their own {"data": …} wrapper — the single
        // envelope comes from App\Support\ApiResponse, so resources stay flat.
        \Illuminate\Http\Resources\Json\JsonResource::withoutWrapping();

        // One password rule, everywhere Password::defaults() is used: sign-up,
        // reset, forced change, staff accounts, the API (App\Support\PasswordPolicy).
        Password::defaults(fn () => \App\Support\PasswordPolicy::rule());

        // Every web sign-in (the form, the second step, a "keep me signed in"
        // cookie) stamps the session, so "sign out everywhere" can end the
        // sessions that began before it (EnsureSessionIsCurrent).
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function (\Illuminate\Auth\Events\Login $event): void {
            if ($event->guard === 'web' && request()->hasSession()) {
                request()->session()->put('auth.at', now()->getTimestamp());
            }
        });

        // The breach check asks one outside service; a slow or unreachable one
        // must not hold a password change for the default 30 seconds.
        $this->app->singleton(\Illuminate\Contracts\Validation\UncompromisedVerifier::class, fn ($app) => new \Illuminate\Validation\NotPwnedVerifier($app[\Illuminate\Http\Client\Factory::class], 5));

        // Use our themed pagination view for every ->links() call (admin + trade),
        // instead of the default unstyled Tailwind markup.
        Paginator::defaultView('pagination::simple-default');
        Paginator::defaultSimpleView('pagination::simple-default');

        // The 'api' rate limiter — every /api/* route via $middleware->throttleApi()
        // in bootstrap/app.php (HMS_PLAN.md §3.C). Per authenticated user when
        // signed in (Sanctum), else per IP. 240 a minute: the app makes about
        // thirty requests when it signs in and prepares for offline, and its
        // sync shares the same budget — 60 would throttle a busy ward.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(240)->by($request->user()?->id ?: $request->ip());
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
                // A real inbox for every "email us" on the public pages, never a no-reply one.
                'supportEmail' => \App\Support\SupportContact::current()->email() ?: (string) config('mail.from.address'),
                'company' => array_filter((array) config('company')),
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

    /**
     * Where DomPDF resolves relative files from, and how it embeds fonts.
     *
     * The package falls back to `base_path('public')`. On a standalone
     * installation on cPanel the web root is a separate folder beside the
     * application (`~/app` next to `~/true_doctor_app`, see APP_PUBLIC_PATH), so
     * that folder does not exist, `realpath()` fails, and EVERY printable —
     * invoice, receipt, lab report, patient card — answered 500 "Cannot resolve
     * public path" (Divine Dental Clinic, 16 Oct 2026). `public_path()` is
     * already right for both layouts (the bootstrap points it at the real web
     * root), so say so once. Nothing printed relies on it for images (the logo is
     * embedded as a data URI, see DocumentBrand): it only has to exist.
     *
     * A method here, not a class of its own: a hotfix of one file must not
     * depend on an autoloader that was built before the new class existed.
     */
    public static function pointPdfAtAFolderThatExists(): void
    {
        // Embed only the letters a document uses: a one-page invoice was 1.1 MB
        // (the whole of two DejaVu fonts each time) and is about 20 KB this way —
        // the difference between a PDF that goes over WhatsApp on mobile data and
        // one that does not.
        config(['dompdf.options.enable_font_subsetting' => true]);

        $exists = static fn (mixed $path): bool => is_string($path) && $path !== '' && realpath($path) !== false;

        if ($exists(config('dompdf.public_path'))) {
            return;
        }

        foreach ([public_path(), base_path('public'), storage_path('app/public'), base_path()] as $candidate) {
            if ($exists($candidate)) {
                config(['dompdf.public_path' => realpath($candidate)]);

                return;
            }
        }
    }
}
