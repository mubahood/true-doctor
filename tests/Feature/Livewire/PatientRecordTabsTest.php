<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Appointments\Index as AppointmentsIndex;
use App\Livewire\Patients\Show;
use App\Livewire\Patients\Tabs\Appointments;
use App\Livewire\Patients\Tabs\Billing;
use App\Livewire\Patients\Tabs\Clinical;
use App\Livewire\Patients\Tabs\Overview;
use App\Livewire\Patients\Tabs\Visits;
use App\Livewire\Visits\Index as VisitsIndex;
use App\Models\Appointment;
use App\Models\Visit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsPatientRecords;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The patient record as tabs: who sees which, that they load on first open,
 * that the deep link works, and what each says when there is nothing to say.
 */
class PatientRecordTabsTest extends TestCase
{
    use BuildsPatientRecords, InteractsWithTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Livewire::withoutLazyLoading();
    }

    /** A tab component on its own, as it is mounted inside the page. */
    private function tab(string $class, int $patientId)
    {
        Livewire::withoutLazyLoading();

        return Livewire::test($class, ['patientId' => $patientId]);
    }

    /** @return list<string> */
    private function tabsOf(string $role): array
    {
        $h = $this->hospital();
        $this->actingAsRole($role, $h);
        $p = $this->patientOf($h);

        return array_keys(Livewire::test(Show::class, ['patient' => $p])->instance()->tabs);
    }

    public function test_each_role_gets_only_the_tabs_it_may_open(): void
    {
        $always = ['overview', 'records', 'details'];

        $receptionist = $this->tabsOf('receptionist');
        $this->assertEqualsCanonicalizing([...$always, 'visits', 'billing', 'clinical', 'appointments'], $receptionist);

        $doctor = $this->tabsOf('doctor');
        $this->assertEqualsCanonicalizing([...$always, 'visits', 'billing', 'clinical', 'appointments'], $doctor);

        $nurse = $this->tabsOf('nurse');
        $this->assertNotContains('billing', $nurse);
        $this->assertContains('clinical', $nurse);

        // A pharmacist never sees Billing, nor the diary.
        $pharmacist = $this->tabsOf('pharmacist');
        $this->assertNotContains('billing', $pharmacist);
        $this->assertNotContains('appointments', $pharmacist);
        $this->assertContains('clinical', $pharmacist);

        // An accountant sees the money and nothing clinical.
        $accountant = $this->tabsOf('accountant');
        $this->assertContains('billing', $accountant);
        $this->assertNotContains('visits', $accountant);
        $this->assertNotContains('clinical', $accountant);
        $this->assertNotContains('appointments', $accountant);
    }

    public function test_a_tab_loads_when_first_opened_and_is_kept_after(): void
    {
        $h = $this->hospital();
        $this->actingAsRole('receptionist', $h);
        $p = $this->patientOf($h);

        $page = Livewire::test(Show::class, ['patient' => $p])
            ->assertSet('tab', 'overview')
            ->assertSeeHtml('id="ppanel-overview"')
            ->assertDontSeeHtml('id="ppanel-billing"')
            ->assertDontSeeHtml('id="ppanel-visits"');

        $page->call('setTab', 'billing')
            ->assertSet('tab', 'billing')
            ->assertSeeHtml('id="ppanel-billing"')
            ->assertDontSeeHtml('id="ppanel-visits"');

        // Back to the overview: billing stays mounted but hidden.
        $page->call('setTab', 'overview')->assertSeeHtml('id="ppanel-billing"');
    }

    public function test_the_tab_is_in_the_url_and_a_deep_link_lands_on_it(): void
    {
        $h = $this->hospital();
        $this->actingAsRole('accountant', $h);
        $p = $this->patientOf($h);

        Livewire::withQueryParams(['tab' => 'billing'])->test(Show::class, ['patient' => $p])
            ->assertSet('tab', 'billing')
            ->assertSeeHtml('id="ppanel-billing"')
            ->assertSee('Invoices');

        $this->get(route('admin.patients.show', [$p, 'tab' => 'billing']))->assertOk();
    }

    public function test_a_tab_you_may_not_have_falls_back_to_the_overview(): void
    {
        $h = $this->hospital();
        $this->actingAsRole('pharmacist', $h);
        $p = $this->patientOf($h);

        Livewire::withQueryParams(['tab' => 'billing'])->test(Show::class, ['patient' => $p])
            ->assertSet('tab', 'overview')
            ->assertDontSeeHtml('id="ppanel-billing"');

        Livewire::withQueryParams(['tab' => 'nonsense'])->test(Show::class, ['patient' => $p])
            ->assertSet('tab', 'overview');

        // The tab component is addressable on its own, so it refuses by itself too.
        $this->tab(Billing::class, $p->id)->assertForbidden();
    }

    public function test_an_owing_patient_shows_the_alert_the_figure_and_the_billing_link(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('receptionist', $h);
        $p = $this->patientOf($h, ['allergies' => ['Penicillin'], 'chronic_conditions' => ['Asthma']]);
        $this->invoiceFor($p, '45000.00', 12, $admin->id);

        Livewire::test(Show::class, ['patient' => $p])
            ->assertSee('Owes')
            ->assertSee('45,000')
            ->assertSee('oldest unpaid 12 days')
            ->assertSee('Penicillin')
            ->assertSee('Asthma')
            ->assertSee('Outstanding')
            ->assertSee('1 unpaid invoice')
            ->call('setTab', 'billing')
            ->assertSet('tab', 'billing');

        $this->tab(Billing::class, $p->id)
            ->assertSee('Debts')
            ->assertSee('0-30 days')
            ->assertSee('90+ days')
            ->assertSee('45,000');
    }

    public function test_a_role_without_billing_view_is_not_told_what_is_owed(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h);
        $this->invoiceFor($p, '45000.00', 3, $admin->id);

        $this->actingAsRole('pharmacist', $h);

        Livewire::test(Show::class, ['patient' => $p])
            ->assertDontSee('Owes')
            ->assertDontSee('Outstanding')
            ->assertDontSee('45,000')
            ->assertDontSee('Statement');
    }

    public function test_a_patient_with_nothing_has_sensible_empty_states(): void
    {
        $h = $this->hospital();
        $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h, ['allergies' => [], 'chronic_conditions' => []]);

        Livewire::test(Show::class, ['patient' => $p])
            ->assertSee('Nothing owed')
            ->assertSee('None yet')
            ->assertSee('Nothing booked');

        $this->tab(Overview::class, $p->id)
            ->assertSee('Nothing has happened on this record yet')
            ->assertSee('No visits yet')
            ->assertSee('Not on any medication');

        $this->tab(Visits::class, $p->id)->assertSee('No visits yet');
        $this->tab(Billing::class, $p->id)
            ->assertSee('No invoices yet')
            ->assertSee('No payments yet')
            ->assertSee('This patient owes nothing');
        $this->tab(Clinical::class, $p->id)
            ->assertSee('No diagnosis has been recorded')
            ->assertSee('Not on any medication')
            ->assertSee('No vitals have been recorded')
            ->assertSee('Never admitted');
        $this->tab(Appointments::class, $p->id)
            ->assertSee('No appointments are booked')
            ->assertSee('No past appointments');
    }

    public function test_the_visits_tab_lists_visits_with_a_serial_and_what_was_billed(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h);
        $invoice = $this->invoiceFor($p, '30000.00', null, $admin->id);
        $this->pay($invoice, '10000.00', $admin->id);

        $this->tab(Visits::class, $p->id)
            ->assertSee($invoice->visit->visit_no)
            ->assertSee('30,000')
            ->assertSee('20,000')
            ->assertSeeHtml('class="tb-serial mono">1<')
            ->assertSeeHtml(route('admin.visits.show', $invoice->visit->uuid));
    }

    public function test_the_billing_tab_lists_invoices_payments_and_the_unpaid_list(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h);
        $invoice = $this->invoiceFor($p, '30000.00', 45, $admin->id);
        $payment = $this->pay($invoice, '10000.00', $admin->id);

        $this->tab(Billing::class, $p->id)
            ->assertSee($invoice->invoice_no)
            ->assertSeeHtml(route('admin.invoices.pdf', $invoice->uuid))
            ->assertSeeHtml(route('admin.payments.receipt', $payment->uuid))
            ->assertSee('Partially paid')
            ->assertSee('31-60 days')
            ->assertSee('45 days');
    }

    public function test_the_clinical_tab_hides_admissions_for_a_practice_without_inpatient_care(): void
    {
        $h = $this->hospital();
        $this->actingAsRole('doctor', $h);
        $p = $this->patientOf($h);

        $this->tab(Clinical::class, $p->id)->assertSee('Admissions');

        $h->forceFill(['settings' => array_merge($h->settings ?? [], ['practice' => ['sector' => 'dental_clinic']])])->save();
        app()->forgetInstance(\App\Support\HospitalSettings::class);
        $this->withHospital($h);

        $this->tab(Clinical::class, $p->id)
            ->assertDontSee('Admissions')
            ->assertSee('Diagnoses');
    }

    public function test_a_tab_will_not_open_another_hospitals_patient(): void
    {
        $a = $this->hospital();
        $b = $this->hospital();
        $patientB = $this->patientOf($b);
        $this->actingAsRole('hospital_admin', $a);

        $this->expectException(ModelNotFoundException::class);
        $this->tab(Overview::class, $patientB->id);
    }

    public function test_the_header_offers_a_new_visit_or_the_open_one(): void
    {
        $h = $this->hospital();
        $this->actingAsRole('receptionist', $h);
        $p = $this->patientOf($h);

        Livewire::test(Show::class, ['patient' => $p])
            ->assertSee('New visit')
            ->assertSee('Book appointment')
            ->assertSee('Statement')
            ->assertSeeHtml(route('admin.patients.statement', $p))
            ->assertSeeHtml(route('admin.visits.index', ['for' => $p->uuid]));

        $visit = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id]);

        Livewire::test(Show::class, ['patient' => $p])
            ->assertSee('Open visit '.$visit->visit_no)
            ->assertDontSee('New visit');

        // A nurse may not open visits or book.
        $this->actingAsRole('nurse', $h);
        Livewire::test(Show::class, ['patient' => $p])->assertDontSee('Book appointment');
    }

    public function test_new_visit_and_book_appointment_open_their_dialogs_for_this_patient(): void
    {
        $h = $this->hospital();
        $this->actingAsRole('receptionist', $h);
        $p = $this->patientOf($h);

        Livewire::withQueryParams(['for' => $p->uuid])->test(VisitsIndex::class)
            ->assertSet('showForm', true)
            ->assertSet('patient_id', $p->id);

        Livewire::withQueryParams(['for' => $p->uuid])->test(AppointmentsIndex::class)
            ->assertSet('showForm', true)
            ->assertSet('patient_id', $p->id);
    }

    public function test_without_the_hint_the_dialogs_stay_shut(): void
    {
        $this->actingAsRole('receptionist', $this->hospital());

        Livewire::test(VisitsIndex::class)->assertSet('showForm', false);
        Livewire::test(AppointmentsIndex::class)->assertSet('showForm', false);
    }

    public function test_the_appointments_tab_separates_upcoming_from_past(): void
    {
        $h = $this->hospital();
        $admin = $this->actingAsRole('hospital_admin', $h);
        $p = $this->patientOf($h);
        Appointment::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id, 'doctor_user_id' => $admin->id,
            'scheduled_at' => now()->addDays(4), 'ends_at' => now()->addDays(4)->addMinutes(30), 'reason' => 'Review of results']);
        Appointment::factory()->create(['hospital_id' => $h->id, 'patient_id' => $p->id, 'doctor_user_id' => $admin->id,
            'scheduled_at' => now()->subDays(20), 'ends_at' => now()->subDays(20)->addMinutes(30), 'status' => 'completed', 'reason' => 'First consultation']);

        $this->tab(Appointments::class, $p->id)
            ->assertSee('Review of results')
            ->assertSee('First consultation')
            ->assertSee('Completed')
            ->assertSee('Scheduled');
    }
}
