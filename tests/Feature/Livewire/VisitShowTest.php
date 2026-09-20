<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Visits\Panels\Charges;
use App\Livewire\Visits\Panels\Clinical;
use App\Livewire\Visits\Panels\Dispense;
use App\Livewire\Visits\Panels\LabOrders;
use App\Livewire\Visits\Panels\Prescriptions;
use App\Livewire\Visits\Panels\RadiologyOrders;
use App\Livewire\Visits\Panels\Vitals;
use App\Livewire\Visits\Show;
use App\Models\Hospital;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\RadiologyStudy;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Services\BillingService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The visit workspace (Visits\Show) and its seven lazy panels — the
 * page that replaced admin/visits/show.blade.php, its 12 classic POST
 * forms and six controllers. Render / RBAC per panel (house rule 18); the
 * behavioural assertions live with their domain suites.
 */
class VisitShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        Livewire::withoutLazyLoading();
    }

    private function staff(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function acting(Hospital $h, string $role): User
    {
        $u = $this->staff($h, $role);
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    private function visit(Hospital $h): Visit
    {
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Grace', 'last_name' => 'Nakato']);

        return Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
    }

    public function test_it_renders_the_workspace_for_a_permitted_role(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'doctor');
        $c = $this->visit($h);

        Livewire::test(Show::class, ['visit' => $c])
            ->assertOk()
            ->assertSee('Grace Nakato')
            ->assertSee($c->visit_no)
            ->assertSee('History');
    }

    public function test_the_full_page_route_renders_with_a_server_side_title(): void
    {
        $h = Hospital::factory()->create();
        $user = $this->staff($h, 'doctor');
        $c = $this->visit($h);

        $this->actingAs($user)
            ->get("/admin/visits/{$c->uuid}")
            ->assertOk()
            ->assertSee("<title>{$c->visit_no} · True-Doctor</title>", false)
            ->assertSee('Grace Nakato');
    }

    public function test_a_role_without_visits_view_is_forbidden(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'receptionist']);
        $u->syncRoles([]);
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(Show::class, ['visit' => $c])->assertForbidden();
    }

    /** Only the legal next moves are offered, straight from the enum. */
    /**
     * The page shows the gate, not a list of stages.
     *
     * A freshly opened visit has nothing holding it, so it offers the one move
     * it can make; a stage nobody may reach is never named.
     */
    public function test_the_page_shows_the_gate_rather_than_a_list_of_stages(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist');
        $c = $this->visit($h);

        Livewire::test(Show::class, ['visit' => $c])
            ->assertSee('Now at')
            ->assertSee('Ready for billing')
            ->assertSee('Cancel visit')
            ->assertDontSee('Ready for payment');
    }

    /** And when something is holding it, the page says what, in figures. */
    public function test_a_shut_gate_is_explained_on_the_page(): void
    {
        $h = Hospital::factory()->create();
        $this->acting($h, 'receptionist');
        $c = $this->visit($h);

        app(\App\Services\VisitService::class)->start($c);
        app(\App\Services\OrderService::class)->place(
            $c->fresh(), \App\Enums\OrderType::Procedure, 'Dressing',
        );

        Livewire::test(Show::class, ['visit' => $c->fresh()])
            ->assertSee('1 order is still open.')
            ->assertDontSee('Ready for billing');
    }

    // ── Panels: render for the role that owns them ─────────────

    /**
     * The panel shows what was measured; recording opens a dialog. So a nurse
     * gets the button and, on asking for it, the form — and a doctor, who holds
     * visits.view but not visits.vitals, gets neither.
     */
    public function test_the_vitals_panel_offers_recording_only_to_a_nurse(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);

        $this->acting($h, 'nurse');
        Livewire::test(Vitals::class, ['visitId' => $c->id])
            ->assertOk()
            ->assertSee('Record vitals')
            ->assertDontSee('Save vitals')      // the form is not on the page
            ->call('openForm')
            ->assertSet('showForm', true)
            ->assertSee('Save vitals');

        $this->acting($h, 'doctor');
        Livewire::test(Vitals::class, ['visitId' => $c->id])
            ->assertOk()
            ->assertDontSee('Record vitals')
            ->assertDontSee('Save vitals');
    }

    public function test_the_clinical_panel_is_read_only_without_diagnose(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);

        // The notes are the panel; writing them opens a dialog. A doctor is
        // offered it, a nurse is not.
        $this->acting($h, 'doctor');
        Livewire::test(Clinical::class, ['visitId' => $c->id])
            ->assertOk()
            ->assertSee('Write notes')
            ->assertDontSee('Save notes')
            ->call('openForm')
            ->assertSet('showForm', true)
            ->assertSee('Save notes');

        $this->acting($h, 'nurse');
        Livewire::test(Clinical::class, ['visitId' => $c->id])
            ->assertOk()
            ->assertDontSee('Write notes')
            ->assertDontSee('Save notes');
    }

    public function test_the_charges_panel_lists_lines_and_totals(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);
        $svc = Service::factory()->create(['hospital_id' => $h->id, 'name' => 'Visit fee', 'price' => '40.00']);
        app(BillingService::class)->orderService($c, $svc->id, 2);

        $this->acting($h, 'receptionist');
        Livewire::test(Charges::class, ['visitId' => $c->id])
            ->assertOk()
            ->assertSee('Visit fee')
            ->assertSee('Generate invoice')
            ->assertSee(\App\Support\HospitalSettings::money('80.00'));   // 2 × 40.00, taxed per hospital config
    }

    public function test_the_lab_panel_lists_the_catalogue_for_an_orderer(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);
        LabTest::factory()->create(['hospital_id' => $h->id, 'name' => 'Malaria RDT', 'price' => '5.00']);

        // The panel is a record of what has been ordered; the catalogue lives
        // in the ordering dialog, so it appears when that is opened.
        $this->acting($h, 'doctor');
        Livewire::test(LabOrders::class, ['visitId' => $c->id])
            ->assertOk()
            ->assertSee('Order tests')
            ->assertDontSee('Malaria RDT')
            ->call('openOrder')
            ->assertSet('showOrder', true)
            ->assertSee('Malaria RDT');
    }

    public function test_the_lab_panel_is_closed_without_lab_order(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);

        $this->acting($h, 'pharmacist');
        Livewire::test(LabOrders::class, ['visitId' => $c->id])->assertForbidden();
    }

    public function test_the_radiology_panel_lists_the_catalogue_for_an_orderer(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);
        RadiologyStudy::factory()->create(['hospital_id' => $h->id, 'name' => 'Chest X-ray', 'price' => '50.00']);

        $this->acting($h, 'doctor');
        Livewire::test(RadiologyOrders::class, ['visitId' => $c->id])
            ->assertOk()
            ->assertSee('Order imaging')
            ->assertDontSee('Chest X-ray')
            ->call('openOrder')
            ->assertSet('showOrder', true)
            ->assertSee('Chest X-ray');
    }

    public function test_the_radiology_panel_is_closed_without_radiology_order(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);

        $this->acting($h, 'pharmacist');
        Livewire::test(RadiologyOrders::class, ['visitId' => $c->id])->assertForbidden();
    }

    public function test_the_dispense_panel_starts_with_one_repeater_row(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);
        StockItem::factory()->create(['hospital_id' => $h->id]);

        // The panel lists what has been dispensed; the repeater lives in the
        // dialog and still starts with one row ready to fill.
        $this->acting($h, 'pharmacist');
        Livewire::test(Dispense::class, ['visitId' => $c->id])
            ->assertOk()
            ->assertCount('items', 1)
            ->assertSee('Dispense drugs')
            ->assertDontSee('Dispense &amp; bill', false)
            ->call('openForm')
            ->assertSet('showForm', true)
            ->assertSee('Dispense &amp; bill', false);
    }

    public function test_the_dispense_panel_is_closed_without_pharmacy_dispense(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);

        $this->acting($h, 'nurse');
        Livewire::test(Dispense::class, ['visitId' => $c->id])->assertForbidden();
    }

    public function test_the_prescriptions_panel_shows_the_writer_only_to_a_prescriber(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);

        $this->acting($h, 'doctor');
        Livewire::test(Prescriptions::class, ['visitId' => $c->id])
            ->assertOk()
            ->assertSee('Write prescription');

        // A nurse may read (and administer) but not write.
        $this->acting($h, 'nurse');
        Livewire::test(Prescriptions::class, ['visitId' => $c->id])
            ->assertOk()
            ->assertDontSee('Write prescription');
    }

    /** A charge raised by a sibling panel (lab, radiology, dispensing) wakes this one. */
    public function test_the_charges_panel_listens_for_visit_updates(): void
    {
        $h = Hospital::factory()->create();
        $c = $this->visit($h);

        $this->acting($h, 'receptionist');
        $component = Livewire::test(Charges::class, ['visitId' => $c->id])
            ->assertDontSee('Ordered off catalogue');

        // An off-catalogue line, exactly as LabService/DispensationService add one.
        app(BillingService::class)->addItem(
            app(\App\Services\OrderService::class)->place($c, \App\Enums\OrderType::Consultation, 'Ordered off catalogue'),
            ['name' => 'Ordered off catalogue', 'unit_price' => '7.00', 'quantity' => 1],
        );

        $component->dispatch('visit-updated')->assertSee('Ordered off catalogue');
    }
}
