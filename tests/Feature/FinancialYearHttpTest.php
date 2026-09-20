<?php

namespace Tests\Feature;

use App\Livewire\FinancialYears\Index as FinancialYearsIndex;
use App\Livewire\FinancialYears\Show as FinancialYearShow;
use App\Models\FinancialYear;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinancialYearHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function user(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_accountant_creates_closes_and_reopens_a_period(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAs($this->user($h, 'accountant'));
        app(CurrentHospital::class)->set($h->id);

        // Periods are opened and transitioned through the Livewire index only.
        Livewire::test(FinancialYearsIndex::class)
            ->call('create')
            ->set('name', 'FY 2026')->set('starts_on', '2026-01-01')->set('ends_on', '2026-12-31')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $fy = FinancialYear::firstOrFail();

        Livewire::test(FinancialYearsIndex::class)->call('close', $fy->id)->assertDispatched('toast', type: 'success');
        $this->assertSame('closed', $fy->fresh()->status->value);

        Livewire::test(FinancialYearsIndex::class)->call('reopen', $fy->id)->assertDispatched('toast', type: 'success');
        $this->assertSame('open', $fy->fresh()->status->value);
    }

    /**
     * The report page carries the same two transitions through the shared
     * ManagesFinancialYears trait, so both surfaces behave identically.
     */
    public function test_the_report_page_closes_and_reopens_the_same_period(): void
    {
        $h = Hospital::factory()->create();
        $accountant = $this->user($h, 'accountant');
        $this->actingAs($accountant);
        app(CurrentHospital::class)->set($h->id);
        $fy = FinancialYear::create(['hospital_id' => $h->id, 'name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);

        Livewire::test(FinancialYearShow::class, ['financialYear' => $fy->id])
            ->call('close', $fy->id)
            ->assertDispatched('toast', type: 'success')
            ->assertSee('Closed');

        $fresh = $fy->fresh();
        $this->assertSame('closed', $fresh->status->value);
        $this->assertNotNull($fresh->closed_at);
        $this->assertSame($accountant->id, $fresh->closed_by);

        // Closing a closed period is a domain refusal, not an error page.
        Livewire::test(FinancialYearShow::class, ['financialYear' => $fy->id])
            ->call('close', $fy->id)
            ->assertDispatched('toast', type: 'error');
        $this->assertSame('closed', $fy->fresh()->status->value);

        Livewire::test(FinancialYearShow::class, ['financialYear' => $fy->id])
            ->call('reopen', $fy->id)
            ->assertDispatched('toast', type: 'success');

        $this->assertSame('open', $fy->fresh()->status->value);
        $this->assertNull($fy->fresh()->closed_at);
    }

    public function test_an_overlapping_period_is_refused_with_a_toast_not_an_error_page(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAs($this->user($h, 'accountant'));
        app(CurrentHospital::class)->set($h->id);
        FinancialYear::create(['hospital_id' => $h->id, 'name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);

        Livewire::test(FinancialYearsIndex::class)
            ->call('create')
            ->set('name', 'FY overlap')->set('starts_on', '2026-06-01')->set('ends_on', '2027-05-31')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'error')
            ->assertSet('showForm', true);

        $this->assertNull(FinancialYear::where('name', 'FY overlap')->first());
    }

    public function test_a_role_without_finance_manage_cannot_open_a_period(): void
    {
        $h = Hospital::factory()->create();
        $viewer = $this->user($h, 'accountant');
        $viewer->syncRoles([]);
        $viewer->givePermissionTo(['access-admin', 'finance.view']);
        $this->actingAs($viewer);
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(FinancialYearsIndex::class)->call('create')->assertForbidden();
    }

    public function test_a_role_without_finance_manage_cannot_close_from_the_report(): void
    {
        $h = Hospital::factory()->create();
        $viewer = $this->user($h, 'accountant');
        $viewer->syncRoles([]);
        $viewer->givePermissionTo(['access-admin', 'finance.view']);
        $this->actingAs($viewer);
        app(CurrentHospital::class)->set($h->id);
        $fy = FinancialYear::create(['hospital_id' => $h->id, 'name' => 'FY x', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);

        Livewire::test(FinancialYearShow::class, ['financialYear' => $fy->id])->assertOk()->call('close', $fy->id)->assertForbidden();
        $this->assertSame('open', $fy->fresh()->status->value);
    }

    public function test_report_page_renders(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $fy = FinancialYear::create(['hospital_id' => $h->id, 'name' => 'FY x', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);

        $accountant = $this->user($h, 'accountant');

        $this->actingAs($accountant)->get("/admin/financial-years/{$fy->id}")->assertOk()->assertSee('Payments received');

        Livewire::actingAs($accountant)->test(FinancialYearShow::class, ['financialYear' => $fy->id])
            ->assertOk()
            ->assertSet('yearId', $fy->id)
            ->assertSee('FY x')
            ->assertSee('Payments received')
            ->assertSee('Invoiced')
            ->assertSee('Outstanding');
    }

    public function test_doctor_cannot_access_finance(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAs($this->user($h, 'doctor'))->get('/admin/financial-years')->assertForbidden();
    }

    public function test_periods_are_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        app(CurrentHospital::class)->set($b->id);
        $fyB = FinancialYear::create(['hospital_id' => $b->id, 'name' => 'FY B', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);

        $this->actingAs($this->user($a, 'accountant'))->get("/admin/financial-years/{$fyB->id}")->assertNotFound();
    }

    public function test_another_tenants_period_cannot_be_mounted(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        app(CurrentHospital::class)->set($b->id);
        $fyB = FinancialYear::create(['hospital_id' => $b->id, 'name' => 'FY B', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);

        $this->actingAs($this->user($a, 'accountant'));
        app(CurrentHospital::class)->set($a->id);

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(FinancialYearShow::class, ['financialYear' => $fyB->id]);
    }

    /** A closed period blocks new postings — the report is where that is read. */
    public function test_a_closed_period_blocks_posting_inside_it(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAs($this->user($h, 'accountant'));
        app(CurrentHospital::class)->set($h->id);
        $fy = FinancialYear::create(['hospital_id' => $h->id, 'name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);

        Livewire::test(FinancialYearShow::class, ['financialYear' => $fy->id])->call('close', $fy->id);

        $this->expectException(\App\Exceptions\ClosedPeriodException::class);
        app(\App\Services\FinancialYearService::class)->assertPostingAllowed(\Illuminate\Support\Carbon::parse('2026-06-15'));
    }
}
