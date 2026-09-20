<?php

namespace Tests\Feature;

use App\Models\Bed;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\BillingService;
use App\Services\TreatmentService;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The rule the whole model rests on: everything a patient does belongs to a
 * visit. Not a convention — the database refuses anything else, and the
 * services that create clinical work open a visit rather than leave it loose.
 *
 * Patient IDENTITY is deliberately not covered by the rule: who someone is,
 * who their dependents are, what insurance they hold and what card they carry
 * all exist before their first visit and between visits, so binding them to
 * one would make a newly registered patient impossible to record.
 */
class EverythingBelongsToAVisitTest extends TestCase
{
    use RefreshDatabase;

    /** Every clinical or billable record carries a visit, and it cannot be null. */
    public const VISIT_BOUND = [
        'invoices', 'admissions', 'treatment_records', 'lab_orders',
        'radiology_orders', 'prescriptions', 'dispensations', 'orders',
        'visit_status_histories',
    ];

    private Hospital $hospital;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
        $this->patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    public function test_every_clinical_and_billable_table_is_bound_to_a_visit(): void
    {
        foreach (self::VISIT_BOUND as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'visit_id'),
                "{$table} must carry a visit_id — everything a patient does belongs to a visit",
            );
        }
    }

    /** The database is the backstop: a loose record cannot even be written. */
    public function test_the_database_refuses_a_record_with_no_visit(): void
    {
        $this->expectException(QueryException::class);

        \App\Models\Invoice::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'invoice_no' => 'INV-TEST-1',
            'patient_id' => $this->patient->id,
            'visit_id' => null,
            'subtotal' => '0.00', 'discount' => '0.00', 'tax' => '0.00', 'total' => '0.00',
            'status' => 'draft',
        ]);
    }

    /**
     * Care is never blocked for missing paperwork: an emergency admission with
     * no visit open gets one, rather than being refused.
     */
    public function test_admitting_a_patient_with_no_open_visit_opens_one(): void
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id]);
        $bed = Bed::factory()->create(['hospital_id' => $this->hospital->id, 'ward_id' => $ward->id]);

        $this->assertSame(0, Visit::count());

        $admission = app(AdmissionService::class)->admit($this->patient, $bed, ['reason' => 'Emergency']);

        $this->assertNotNull($admission->visit_id);
        $this->assertSame(1, Visit::count(), 'the admission opened the visit it belongs to');
        $this->assertSame($this->patient->id, $admission->visit->patient_id);
    }

    public function test_a_procedure_with_no_open_visit_opens_one(): void
    {
        $record = app(TreatmentService::class)->create($this->patient, ['procedure' => 'Wound dressing'], []);

        $this->assertNotNull($record->visit_id);
        $this->assertSame(1, Visit::count());
    }

    /** A patient already mid-visit does not collect a second one. */
    public function test_work_joins_the_visit_already_open_rather_than_starting_another(): void
    {
        $visit = app(VisitService::class)->open(['patient_id' => $this->patient->id]);

        app(TreatmentService::class)->create($this->patient, ['procedure' => 'Dressing'], []);
        app(TreatmentService::class)->create($this->patient, ['procedure' => 'Injection'], []);

        $this->assertSame(1, Visit::count(), 'one attendance, one visit');
        $this->assertSame(
            [$visit->id, $visit->id],
            \App\Models\TreatmentRecord::pluck('visit_id')->all(),
        );
    }

    /** A completed visit is closed: new work opens the next one. */
    public function test_a_completed_visit_is_not_reused(): void
    {
        $first = app(VisitService::class)->open(['patient_id' => $this->patient->id]);
        $first->update(['status' => \App\Enums\VisitStatus::Completed]);

        app(TreatmentService::class)->create($this->patient, ['procedure' => 'Follow-up dressing'], []);

        $this->assertSame(2, Visit::count());
        $this->assertNotSame($first->id, \App\Models\TreatmentRecord::first()->visit_id);
    }

    /** The bill is the visit's bill — billing takes a Visit, not a patient. */
    public function test_an_invoice_is_raised_against_a_visit(): void
    {
        $visit = app(VisitService::class)->open(['patient_id' => $this->patient->id]);
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '20000']);

        app(BillingService::class)->orderService($visit->fresh(), $service->id, 1);
        $invoice = app(BillingService::class)->generateInvoice($visit->fresh(), '0.00', null);

        $this->assertSame($visit->id, $invoice->visit_id);
    }

    /** Identity is not a visit artefact — a patient exists before their first visit. */
    public function test_patient_identity_records_are_not_forced_onto_a_visit(): void
    {
        $this->assertSame(0, Visit::count());

        foreach (['patient_insurances', 'patient_dependents', 'patient_cards'] as $table) {
            $this->assertFalse(
                Schema::hasColumn($table, 'visit_id'),
                "{$table} is who the patient IS, not something they did on a visit",
            );
        }

        // A document may optionally be tagged to the visit it arrived with.
        $this->assertTrue(Schema::hasColumn('patient_documents', 'visit_id'));
    }

    /**
     * Two tables reach a visit through their parent rather than directly, and
     * both are deliberate: an insurance claim reaches one through its invoice,
     * and a billing line reaches one through the order that raised it
     * (docs/orders.md). Nothing floats free either way.
     */
    public function test_billing_lines_reach_a_visit_through_their_order(): void
    {
        $this->assertFalse(
            Schema::hasColumn('order_items', 'visit_id'),
            'a line belongs to the work that raised it, not loose on the visit',
        );
        $this->assertTrue(Schema::hasColumn('order_items', 'order_id'));
        $this->assertTrue(Schema::hasColumn('orders', 'visit_id'));

        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => '20000']);
        $visit = app(VisitService::class)->open(['patient_id' => $this->patient->id]);
        app(BillingService::class)->orderService($visit->fresh(), $service->id, 1);

        $item = \App\Models\OrderItem::firstOrFail();
        $this->assertSame($visit->id, $item->order->visit_id);
        $this->assertSame(0, \App\Models\OrderItem::whereNull('order_id')->count());
    }

    /** The database refuses a line with no order, as it refuses a loose invoice. */
    public function test_the_database_refuses_a_billing_line_with_no_order(): void
    {
        $this->expectException(QueryException::class);

        \App\Models\OrderItem::create([
            'order_id' => null,
            'name' => 'Loose charge',
            'unit_price' => '1000.00',
            'quantity' => 1,
            'line_total' => '1000.00',
            'status' => 'ordered',
        ]);
    }

    /** Visit numbers say what they are. */
    public function test_visits_are_numbered_as_visits(): void
    {
        $visit = app(VisitService::class)->open(['patient_id' => $this->patient->id]);

        $this->assertStringStartsWith('V-', $visit->visit_no);
    }

    public function test_the_workspace_is_reachable_as_a_visit(): void
    {
        $doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        $doctor->syncSpatieRole();
        $visit = app(VisitService::class)->open(['patient_id' => $this->patient->id]);

        $this->actingAs($doctor)->get(route('admin.visits.show', $visit))->assertOk();
        $this->actingAs($doctor)->get(route('admin.visits.index'))->assertOk()->assertSee('Visits');
    }
}
