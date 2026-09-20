<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Dashboard\Index;
use App\Livewire\Dashboard\Section;
use App\Models\Hospital;
use App\Models\StockItem;
use App\Models\User;
use App\Services\ReportService;
use App\Support\CurrentHospital;
use App\Support\Dashboard\DashboardService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Counts how often the dashboard actually asks the service for a figure, so the
 * "widgets are served from a short-TTL cache" guarantee is testable rather than
 * assumed.
 */
class CountingDashboardService extends DashboardService
{
    public static int $lowStockCalls = 0;

    public function lowStockCount(): int
    {
        self::$lowStockCalls++;

        return parent::lowStockCount();
    }
}

/**
 * Dashboard\Index (role router) and Dashboard\Section (the one lazy, polled,
 * cached widget host): render, laziness, per-role whitelisting, permission
 * gating, tenancy and the cache guarantee.
 */
class DashboardSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function staff(string $role, ?Hospital $hospital = null): User
    {
        $hospital ??= Hospital::factory()->create();
        $u = User::factory()->create(['role' => $role, 'hospital_id' => $hospital->id]);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($hospital->id);

        return $u;
    }

    /**
     * Render a section the way the browser eventually does — i.e. past the
     * skeleton. (Livewire resets withoutLazyLoading() between test instances,
     * so it is re-armed for every render.)
     */
    private function section(string $widget): \Livewire\Features\SupportTesting\Testable
    {
        Livewire::withoutLazyLoading();

        return Livewire::test(Section::class, ['widget' => $widget]);
    }

    public function test_the_page_picks_the_role_view_and_serves_skeletons_first(): void
    {
        $this->staff('pharmacist');

        // Quick actions are inline; the widgets arrive after paint as skeletons.
        $this->get('/admin')
            ->assertOk()
            ->assertSee('Quick actions')
            ->assertSee('tb-skeleton', false)
            ->assertDontSee('Stock value');
    }

    public function test_an_unknown_role_falls_back_to_the_permission_driven_view(): void
    {
        $u = $this->staff('doctor');
        $u->forceFill(['role' => 'chaplain'])->save();

        $this->assertSame('fallback', Index::roleViewFor($u->fresh()));
    }

    public function test_a_section_renders_its_widget(): void
    {
        $this->staff('pharmacist');

        $this->section('pharmacist.stats')
            ->assertOk()
            ->assertSee('Stock value')
            ->assertSee('Low stock items')
            ->assertDontSee('Revenue today');
    }

    public function test_a_widget_outside_the_viewers_role_view_is_forbidden(): void
    {
        $this->staff('doctor');

        $this->section('super.stats')->assertForbidden();
        $this->section('admin.side-stats')->assertForbidden();
    }

    public function test_a_widget_the_viewer_lacks_the_permission_for_is_forbidden(): void
    {
        $this->staff('hospital_admin');
        \Spatie\Permission\Models\Role::findByName('hospital_admin')->revokePermissionTo('pharmacy.view');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->section('admin.low-stock')->assertForbidden();
    }

    public function test_a_widget_never_shows_another_tenants_rows(): void
    {
        $mine = Hospital::factory()->create();
        $other = Hospital::factory()->create();
        StockItem::factory()->create(['hospital_id' => $other->id, 'name' => 'Foreign Paracetamol', 'reorder_level' => '999', 'current_quantity' => '1']);

        $this->staff('pharmacist', $mine);

        $this->section('pharmacist.low-stock')
            ->assertOk()
            ->assertDontSee('Foreign Paracetamol');
    }

    private function countingService(): void
    {
        CountingDashboardService::$lowStockCalls = 0;
        app()->instance(DashboardService::class, new CountingDashboardService(
            app(ReportService::class),
            app(CurrentHospital::class),
        ));
    }

    public function test_widget_data_is_served_from_cache_on_the_second_render(): void
    {
        $this->staff('pharmacist');
        $this->countingService();

        $this->section('pharmacist.stats')->assertOk();
        $this->assertSame(1, CountingDashboardService::$lowStockCalls);

        // Second render (a poll, a navigation back) must not touch the service.
        $this->section('pharmacist.stats')->assertOk();
        $this->assertSame(1, CountingDashboardService::$lowStockCalls);
    }

    public function test_the_cache_is_keyed_per_user_so_one_staffers_figures_never_leak(): void
    {
        $hospital = Hospital::factory()->create();
        $first = $this->staff('pharmacist', $hospital);
        $this->countingService();

        $this->section('pharmacist.stats')->assertOk();
        $this->assertSame(1, CountingDashboardService::$lowStockCalls);

        $second = $this->staff('pharmacist', $hospital);
        $this->assertNotSame($first->id, $second->id);

        $this->section('pharmacist.stats')->assertOk();
        $this->assertSame(2, CountingDashboardService::$lowStockCalls);
    }

    public function test_sections_poll_while_visible(): void
    {
        $this->staff('pharmacist');

        $this->section('pharmacist.stats')->assertSeeHtml('wire:poll.60s.visible');
    }
}
