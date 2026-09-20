<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Livewire\Reports\Index;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\BillingService;
use App\Services\ReportService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The reports page, and the document it prints.
 *
 * GeneratedDocumentTest already holds this PDF to the two rules every printed
 * thing here obeys — it opens rather than downloads, and it carries the
 * hospital's own letterhead. What is held HERE is everything that is specific
 * to a report: that it covers the range it was asked for, that a URL somebody
 * typed by hand cannot break it, that the screen and the document agree on the
 * period, and that nobody without `reports.view` can read either.
 */
class ReportPdfTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create(['name' => 'Kisenyi Referral', 'currency' => 'UGX']);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $this->admin->syncSpatieRole();
    }

    private function url(array $query = []): string
    {
        return route('admin.reports.pdf', $query);
    }

    // ── Who may read it ──────────────────────────────────────────────────

    public function test_a_nurse_cannot_print_the_report(): void
    {
        // The screen is already closed to them; the PDF is the same figures,
        // so leaving the URL open would have handed the whole dashboard to
        // anybody who knew the path.
        $nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();

        $this->actingAs($nurse)->get($this->url())->assertForbidden();
    }

    public function test_a_stranger_is_sent_to_log_in(): void
    {
        $this->get($this->url())->assertRedirect();
    }

    public function test_an_admin_gets_a_pdf(): void
    {
        $response = $this->actingAs($this->admin)->get($this->url());

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    // ── The range ────────────────────────────────────────────────────────

    public function test_it_defaults_to_this_month(): void
    {
        $this->actingAs($this->admin);

        $disposition = (string) $this->get($this->url())->headers->get('content-disposition');

        $this->assertStringContainsString(Carbon::now()->startOfMonth()->toDateString(), $disposition);
        $this->assertStringContainsString(Carbon::now()->endOfMonth()->toDateString(), $disposition);
    }

    public function test_the_filename_says_the_hospital_and_the_range(): void
    {
        $this->actingAs($this->admin);

        $disposition = (string) $this->get($this->url(['from' => '2026-03-01', 'to' => '2026-03-31']))
            ->headers->get('content-disposition');

        $this->assertStringContainsString('kisenyi-referral-report-2026-03-01-to-2026-03-31.pdf', $disposition);
    }

    /** This URL gets shared, bookmarked and edited by hand. It may not 500. */
    public function test_a_mistyped_date_falls_back_instead_of_failing(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get($this->url(['from' => 'last tuesday-ish', 'to' => '']));

        $response->assertOk();
        $this->assertStringContainsString(
            Carbon::now()->startOfMonth()->toDateString(),
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_a_back_to_front_range_is_read_as_a_typo_not_as_nothing(): void
    {
        $this->actingAs($this->admin);

        $disposition = (string) $this->get($this->url(['from' => '2026-03-31', 'to' => '2026-03-01']))
            ->headers->get('content-disposition');

        $this->assertStringContainsString('2026-03-01-to-2026-03-31.pdf', $disposition);
    }

    // ── It covers what it says it covers ─────────────────────────────────

    /**
     * A payment outside the range must not reach the document.
     *
     * Asserted through the service the controller calls with the range the
     * controller derives, rather than by searching the PDF bytes: DomPDF's
     * output is compressed, so grepping it for a figure proves nothing either
     * way (the same reasoning GeneratedDocumentTest sets out).
     */
    public function test_the_range_excludes_payments_outside_it(): void
    {
        $this->actingAs($this->admin);

        $this->travelTo(Carbon::parse('2026-03-10 09:00'));
        $this->paidVisit('40000.00');

        $this->travelTo(Carbon::parse('2026-04-10 09:00'));
        $this->paidVisit('90000.00');

        $this->travelBack();

        $march = app(ReportService::class)->revenue(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $this->assertSame('40000.00', $march['total']);
        $this->assertSame(1, $march['count']);
    }

    /** Every section the template reads must exist with the keys it uses. */
    public function test_the_document_renders_with_real_data_in_every_section(): void
    {
        $this->actingAs($this->admin);

        $this->paidVisit('25000.00');
        $this->aStay();

        $this->get($this->url())->assertOk();
    }

    /** …and with nothing at all, which is the state a new hospital is in. */
    public function test_the_document_renders_for_a_hospital_with_no_data(): void
    {
        $this->actingAs($this->admin)->get($this->url())->assertOk();
    }

    // ── The screen and the document agree ────────────────────────────────

    public function test_the_print_link_carries_the_range_on_screen(): void
    {
        $this->actingAs($this->admin);
        app(CurrentHospital::class)->set($this->hospital->id);

        Livewire::test(Index::class)
            ->set('from', '2026-02-01')
            ->set('to', '2026-02-28')
            // Escaped, because that is how an href with two query parameters
            // is actually written into HTML.
            ->assertSeeHtml(e(route('admin.reports.pdf', ['from' => '2026-02-01', 'to' => '2026-02-28'])));
    }

    public function test_a_preset_sets_the_range_and_then_looks_chosen(): void
    {
        $this->actingAs($this->admin);
        app(CurrentHospital::class)->set($this->hospital->id);

        $component = Livewire::test(Index::class)->call('usePreset', 'today');

        $component
            ->assertSet('from', Carbon::now()->startOfDay()->toDateString())
            ->assertSet('to', Carbon::now()->endOfDay()->toDateString());

        $this->assertSame('today', $component->instance()->activePreset());
    }

    /**
     * Every preset in the list has to be usable.
     *
     * The buttons are generated from `presets()` and dispatch by key, so a key
     * with no arm in the match would silently fall through to this month and
     * the button would look like it did nothing.
     */
    public function test_every_preset_offered_produces_its_own_range(): void
    {
        $this->actingAs($this->admin);
        app(CurrentHospital::class)->set($this->hospital->id);

        $component = Livewire::test(Index::class);
        $seen = [];

        foreach (array_keys($component->instance()->presets()) as $key) {
            $component->call('usePreset', $key);

            $range = [$component->get('from'), $component->get('to')];
            $this->assertNotContains($range, $seen, "the '{$key}' preset repeats another preset's range");
            $seen[] = $range;

            // …and the button that produced it is the one that lights up.
            $this->assertSame($key, $component->instance()->activePreset());
        }
    }

    /** A hand-edited range belongs to no preset, and none may claim it. */
    public function test_an_arbitrary_range_lights_up_no_preset(): void
    {
        $this->actingAs($this->admin);
        app(CurrentHospital::class)->set($this->hospital->id);

        $component = Livewire::test(Index::class)->set('from', '2026-02-03')->set('to', '2026-02-19');

        $this->assertNull($component->instance()->activePreset());
    }

    // ── Outstanding ──────────────────────────────────────────────────────

    /**
     * The count beside the total has to count the same invoices as the total.
     *
     * An issued invoice paid down to nothing is not money owed. It was being
     * counted anyway, so a hospital that had settled everything read
     * "0 outstanding" beside "12 unpaid invoices".
     */
    public function test_outstanding_counts_only_invoices_with_a_balance(): void
    {
        $this->actingAs($this->admin);

        $this->paidVisit('30000.00');              // settled in full
        $unpaid = $this->invoiceFor('70000.00');   // never paid

        $outstanding = app(ReportService::class)->outstanding();

        $this->assertSame('70000.00', $outstanding['total']);
        $this->assertSame(1, $outstanding['count']);
        $this->assertSame('70000.00', $outstanding['buckets']['0-7 days']);
        $this->assertNotNull($unpaid->id);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function invoiceFor(string $price): \App\Models\Invoice
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $visit = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);
        $service = Service::factory()->create([
            'hospital_id' => $this->hospital->id,
            'price' => $price,
            'name' => 'Service '.uniqid(),
        ]);

        app(BillingService::class)->orderService($visit->fresh(), $service->id, 1);

        return app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', null);
    }

    private function paidVisit(string $price): void
    {
        $invoice = $this->invoiceFor($price);

        app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, $price, [], null);
    }

    private function aStay(): void
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Maternity']);
        $bed = Bed::factory()->create([
            'hospital_id' => $this->hospital->id,
            'ward_id' => $ward->id,
            'status' => \App\Enums\BedStatus::Available,
            'daily_charge' => '45000.00',
        ]);

        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        $admission = app(AdmissionService::class)->admit($patient, $bed, [
            'admitted_at' => Carbon::now()->subDays(3)->toDateTimeString(),
        ], $this->admin->id);

        // Three nights on the order, so the inpatient section has something
        // to read rather than only a row of zeroes.
        app(AdmissionService::class)->accrueNightlyCharges($admission->fresh(), Carbon::now(), $this->admin->id);
    }
}
