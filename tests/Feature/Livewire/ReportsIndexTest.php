<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Reports\Index;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Services\ReportService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Counts ReportService calls so the 60 s section cache is testable. */
class CountingReportService extends ReportService
{
    public static int $revenueCalls = 0;

    public function revenue(Carbon $from, Carbon $to): array
    {
        self::$revenueCalls++;

        return parent::revenue($from, $to);
    }
}

class ReportsIndexTest extends TestCase
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
        $u = User::factory()->create(['hospital_id' => $hospital->id, 'role' => $role]);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($hospital->id);

        return $u;
    }

    public function test_it_defaults_to_the_current_month(): void
    {
        $this->staff('hospital_admin');

        Livewire::test(Index::class)
            ->assertOk()
            ->assertSet('from', Carbon::now()->startOfMonth()->toDateString())
            ->assertSet('to', Carbon::now()->endOfMonth()->toDateString())
            ->assertSee('Bed occupancy')
            ->assertSee('Doctor activity');
    }

    public function test_the_range_is_url_bound_and_live(): void
    {
        $this->staff('hospital_admin');

        Livewire::withQueryParams(['from' => '2026-01-01', 'to' => '2026-01-31'])
            ->test(Index::class)
            ->assertSet('from', '2026-01-01')
            ->assertSet('to', '2026-01-31')
            ->set('from', '2026-02-01')
            ->assertSet('from', '2026-02-01');
    }

    public function test_an_unparsable_range_falls_back_to_the_current_month(): void
    {
        $this->staff('hospital_admin');

        Livewire::withQueryParams(['from' => 'not-a-date', 'to' => ''])
            ->test(Index::class)
            ->assertOk()
            ->assertSet('from', Carbon::now()->startOfMonth()->toDateString())
            ->assertSet('to', Carbon::now()->endOfMonth()->toDateString());
    }

    public function test_a_nurse_cannot_view_reports(): void
    {
        $this->staff('nurse');

        Livewire::test(Index::class)->assertForbidden();
        $this->get('/admin/reports')->assertForbidden();
    }

    public function test_reports_never_count_another_tenants_patients(): void
    {
        $mine = Hospital::factory()->create();
        $other = Hospital::factory()->create();
        Patient::factory()->count(3)->create(['hospital_id' => $other->id]);
        Patient::factory()->create(['hospital_id' => $mine->id]);

        $this->staff('hospital_admin', $mine);

        Livewire::test(Index::class)->assertOk()->assertSee('Patients');

        $this->assertSame(1, app(ReportService::class)->demographics()['total']);
    }

    public function test_sections_are_cached_per_range(): void
    {
        $this->staff('hospital_admin');
        CountingReportService::$revenueCalls = 0;
        app()->instance(ReportService::class, new CountingReportService);

        $c = Livewire::test(Index::class);
        $this->assertSame(1, CountingReportService::$revenueCalls);

        // Re-rendering the same range must not recompute.
        $c->call('$refresh');
        $this->assertSame(1, CountingReportService::$revenueCalls);

        // A new range is a new cache key.
        $c->set('from', Carbon::now()->subMonth()->startOfMonth()->toDateString());
        $this->assertSame(2, CountingReportService::$revenueCalls);
    }
}
