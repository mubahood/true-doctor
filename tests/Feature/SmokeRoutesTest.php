<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Every GET page in the back office must render — no 500s, no views deleted out
 * from under a route, no route name pointing at a removed controller. The route
 * table is walked dynamically, so a screen added tomorrow is covered today.
 *
 * Records come from DemoSeeder (the same data the dev environment uses), which
 * doubles as a guarantee that the demo tenant reaches every module: a demo
 * hospital with an empty module, or without a subscription, fails here.
 */
class SmokeRoutesTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    /** Routes that answer with a file or a redirect rather than a rendered page. */
    private const NON_PAGE_ROUTES = [
        'admin.logout',
        'admin.patients.id-card',
        'admin.invoices.pdf',
        'admin.payments.receipt',
        'admin.lab-orders.pdf',
        'admin.radiology-orders.pdf',
        'admin.admissions.summary',
        'admin.patients.documents.download',
        'admin.patients.treatments.photo',
        'admin.orders.attachments.download',
        // The same private store, reached through the lab and radiology benches.
        'admin.lab-orders.attachments.download',
        'admin.radiology-orders.attachments.download',
    ];

    /** @var array<string, class-string> route parameter => model */
    private const PARAMETER_MODELS = [
        'patient' => \App\Models\Patient::class,
        'visit' => \App\Models\Visit::class,
        'appointment' => \App\Models\Appointment::class,
        'invoice' => \App\Models\Invoice::class,
        'stock' => \App\Models\StockItem::class,
        'admission' => \App\Models\Admission::class,
        'insuranceClaim' => \App\Models\InsuranceClaim::class,
        'insuranceProvider' => \App\Models\InsuranceProvider::class,
        // Cards are bound by uuid, not by a model: a card number, even a
        // surrogate for one, never appears in a URL (docs/cards.md).
        'uuid' => \App\Models\PatientCard::class,
        'financialYear' => \App\Models\FinancialYear::class,
        'labOrder' => \App\Models\LabOrder::class,
        'radiologyOrder' => \App\Models\RadiologyOrder::class,
        'dispensation' => \App\Models\Dispensation::class,
        'treatment' => \App\Models\TreatmentRecord::class,
        'ward' => \App\Models\Ward::class,
        'bed' => \App\Models\Bed::class,
        'department' => \App\Models\Department::class,
        'room' => \App\Models\Room::class,
        'service' => \App\Models\Service::class,
        'user' => \App\Models\User::class,
        'hospital' => Hospital::class,
        'plan' => Plan::class,
        'subscription' => Subscription::class,
    ];

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();

        // DemoSeeder is dev-only by design; the smoke walk needs its data.
        $this->app->detectEnvironment(fn () => 'local');

        $this->seedRbac();
        $this->seed(PlanSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->hospital = Hospital::where('slug', 'general-hospital-a')->firstOrFail();
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    /** @return list<RouteInstance> */
    private function pageRoutes(string $prefix): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteInstance $r) => in_array('GET', $r->methods(), true))
            ->filter(fn (RouteInstance $r) => str_starts_with((string) $r->getName(), $prefix))
            ->reject(fn (RouteInstance $r) => in_array($r->getName(), self::NON_PAGE_ROUTES, true))
            ->values()
            ->all();
    }

    /** Bind every route parameter to a seeded record, or return null. */
    private function parametersFor(RouteInstance $route): ?array
    {
        $values = [];

        foreach ($route->parameterNames() as $name) {
            $class = self::PARAMETER_MODELS[$name] ?? null;
            $record = $class ? $class::query()->first() : null;

            if ($record === null) {
                return null;
            }

            $values[$name] = $record->getRouteKey();
        }

        return $values;
    }

    public function test_every_admin_page_renders_for_a_hospital_admin(): void
    {
        $admin = User::where('email', 'admin.a@test.com')->firstOrFail();
        $this->actingAs($admin);
        app(CurrentHospital::class)->set($this->hospital->id);

        $failures = [];
        $unbound = [];

        foreach ($this->pageRoutes('admin.') as $route) {
            $parameters = $this->parametersFor($route);
            if ($parameters === null) {
                $unbound[] = $route->getName();

                continue;
            }

            $status = $this->get(route($route->getName(), $parameters))->getStatusCode();

            // 403 is legitimate (a permission this role lacks). 5xx never is, and
            // 404 on a record that exists means a broken route-model binding.
            if ($status >= 500 || $status === 404) {
                $failures[] = $route->getName().' => '.$status;
            }
        }

        $this->assertSame([], $failures, "Admin pages that did not render:\n".implode("\n", $failures));
        $this->assertSame([], $unbound, "Admin routes with no seeded record to bind:\n".implode("\n", $unbound));
    }

    public function test_every_super_admin_page_renders(): void
    {
        $this->actingAsSuperAdmin();

        $failures = [];
        foreach ($this->pageRoutes('super.') as $route) {
            $parameters = $this->parametersFor($route);
            if ($parameters === null) {
                continue;
            }

            $status = $this->get(route($route->getName(), $parameters))->getStatusCode();
            if ($status >= 500 || $status === 404) {
                $failures[] = $route->getName().' => '.$status;
            }
        }

        $this->assertSame([], $failures, "Super-admin pages that did not render:\n".implode("\n", $failures));
    }

    public function test_public_and_auth_pages_render(): void
    {
        foreach (['home', 'features', 'pricing', 'security', 'contact', 'privacy', 'terms', 'admin.login', 'register'] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_the_demo_tenant_is_fully_onboarded(): void
    {
        // A demo tenant that fails setup is held in the wizard and can show nothing.
        $this->assertTrue(
            app(\App\Support\OnboardingStatus::class)->isComplete($this->hospital),
            'the demo hospital does not satisfy the onboarding requirements',
        );
    }

    public function test_the_demo_tenant_is_subscribed_and_every_module_has_data(): void
    {
        // Without an access-granting subscription EnsureSubscribed locks the
        // demo tenant out of its own data — the seeder must provide one.
        $this->assertNotNull($this->hospital->activeSubscription(), 'demo hospital has no active subscription');

        foreach ([
            \App\Models\Patient::class,
            \App\Models\Appointment::class,
            \App\Models\Visit::class,
            \App\Models\Invoice::class,
            \App\Models\Payment::class,
            \App\Models\LabOrder::class,
            \App\Models\RadiologyOrder::class,
            \App\Models\Dispensation::class,
            \App\Models\Prescription::class,
            \App\Models\DoseItemRecord::class,
            \App\Models\Admission::class,
            \App\Models\InsuranceClaim::class,
            \App\Models\PatientCard::class,
            \App\Models\TreatmentRecord::class,
            \App\Models\FinancialYear::class,
            \App\Models\StockMovement::class,
        ] as $model) {
            $this->assertGreaterThan(0, $model::count(), class_basename($model).' has no demo data');
        }
    }

    public function test_every_asset_referenced_by_a_view_exists_on_disk(): void
    {
        $missing = [];

        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('views')) as $file) {
            preg_match_all("/asset\\('([^']+)'\\)/", \Illuminate\Support\Facades\File::get($file->getPathname()), $matches);

            foreach ($matches[1] as $path) {
                if (! file_exists(public_path($path))) {
                    $missing[] = $file->getRelativePathname().' → '.$path;
                }
            }
        }

        $this->assertSame([], $missing, "Views reference assets that are not in public/:\n".implode("\n", $missing));
    }

    public function test_every_dialog_is_a_centred_modal(): void
    {
        $views = \Illuminate\Support\Facades\File::allFiles(resource_path('views'));
        $drawers = [];
        $dialogs = 0;

        foreach ($views as $file) {
            $html = \Illuminate\Support\Facades\File::get($file->getPathname());

            // The right-hand drawer was retired: every form opens in a centred modal.
            foreach (['x-ui.slideover', 'tb-slideover', 'tdSlideover', 'tb-overlay'] as $retired) {
                if (str_contains($html, $retired)) {
                    $drawers[] = $file->getRelativePathname().' → '.$retired;
                }
            }

            $dialogs += substr_count($html, '<x-ui.modal');
        }

        $this->assertSame([], $drawers, "Retired slide-over markup still present:\n".implode("\n", $drawers));
        $this->assertGreaterThan(20, $dialogs, 'the modal component should be the only dialog in use');

        // The component itself must centre the dialog over the backdrop.
        $component = \Illuminate\Support\Facades\File::get(resource_path('views/components/ui/modal.blade.php'));
        $this->assertStringContainsString('tb-modal-backdrop', $component);
        $this->assertStringContainsString('x-trap.inert="open"', $component);
        $this->assertStringContainsString('requestClose()', $component);

        $css = \Illuminate\Support\Facades\File::get(resource_path('css/admin.css'));
        $this->assertMatchesRegularExpression('/\.tb-modal-backdrop\{[^}]*align-items:center/', $css);
        $this->assertMatchesRegularExpression('/\.tb-modal-backdrop\{[^}]*justify-content:center/', $css);
    }

    public function test_the_admin_shell_is_spa_correct(): void
    {
        $this->actingAs(User::where('email', 'admin.a@test.com')->firstOrFail());
        app(CurrentHospital::class)->set($this->hospital->id);

        $html = $this->get(route('admin.patients.index'))->assertOk()->getContent();

        // Server-rendered title (Livewire ->title() reaching the layout).
        $this->assertStringContainsString('<title>Patients · True-Doctor</title>', $html);
        // Persisted shell regions survive wire:navigate.
        foreach (['sidebar', 'toasts', 'footer'] as $region) {
            $this->assertStringContainsString('x-persist="'.$region.'"', $html);
        }
        // SPA links, and none of the code Phase 1 removed.
        $this->assertGreaterThan(10, substr_count($html, 'wire:navigate'));
        foreach (['window.location', 'chart.min.js', 'CX-DIAG', 'css/td-admin.css'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
    }
}
