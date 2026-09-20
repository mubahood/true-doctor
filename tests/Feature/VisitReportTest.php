<?php

namespace Tests\Feature;

use App\Enums\BedStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\RadiologyStudy;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\BillingService;
use App\Services\LabService;
use App\Services\OrderService;
use App\Services\RadiologyService;
use App\Services\VisitReportService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The full visit report (docs/documents.md).
 *
 * This is the document a patient carries to another hospital, so the test is
 * written from the receiving clinician's side: they have none of this system,
 * and every question they would ask has to be answered by the page in their
 * hand. What is asserted is not "the template rendered" but "the allergy is on
 * it", "the abnormal result is on it", "the drug and its dose are on it".
 */
class VisitReportTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $doctor;

    private Patient $patient;

    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create(['name' => 'Kisenyi Referral Hospital']);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->doctor = User::factory()->create([
            'hospital_id' => $this->hospital->id,
            'role' => 'doctor',
            'name' => 'Dr Aisha Namara',
        ]);
        $this->doctor->syncSpatieRole();
        $this->actingAs($this->doctor);

        $this->patient = Patient::factory()->create([
            'hospital_id' => $this->hospital->id,
            'first_name' => 'Joseph',
            'last_name' => 'Okello',
            'dob' => now()->subYears(34)->toDateString(),
            'blood_type' => 'O+',
            'allergies' => 'Penicillin — anaphylaxis',
            'chronic_conditions' => 'Type 2 diabetes',
            'emergency_contact_name' => 'Grace Okello',
            'emergency_contact_phone' => '+256 772 000 111',
        ]);

        $this->visit = Visit::factory()->create([
            'hospital_id' => $this->hospital->id,
            'patient_id' => $this->patient->id,
            'doctor_user_id' => $this->doctor->id,
            'reason' => 'Fever and headache for three days',
            'complaints' => 'Night sweats, poor appetite',
            'diagnosis' => 'Uncomplicated malaria',
            'doctor_remarks' => 'Review in 48 hours if fever persists.',
            'temperature' => '38.60',
            'pulse' => 96,
            'blood_pressure' => '118/76',
            'spo2' => 98,
            'weight' => '71.50',
        ]);
    }

    /** The document as HTML — what the PDF is rendered from. */
    private function sheet(): string
    {
        $report = app(VisitReportService::class)->assemble($this->visit->fresh());

        return view('pdf.visit-report', ['report' => $report, 'hospital' => $this->hospital])->render();
    }

    private function service(string $price = '30000.00'): Service
    {
        return Service::factory()->create(['hospital_id' => $this->hospital->id, 'price' => $price]);
    }

    // ── Who this is, and what would harm them ────────────────────────────

    /**
     * The first thing on the page, because it is the first thing that changes
     * what the next clinician may safely do.
     */
    public function test_it_leads_with_the_allergy_and_the_blood_group(): void
    {
        $sheet = $this->sheet();

        $this->assertStringContainsString('Penicillin — anaphylaxis', $sheet);
        $this->assertStringContainsString('Type 2 diabetes', $sheet);
        $this->assertStringContainsString('O+', $sheet);

        // Before the presentation, not buried under it.
        $this->assertLessThan(
            strpos($sheet, 'Presentation'),
            strpos($sheet, 'Penicillin'),
            'the allergy is printed below the reason for the visit',
        );
    }

    /**
     * "None recorded" and a blank space are different claims, and only one of
     * them is safe to act on.
     */
    public function test_a_patient_with_no_allergies_says_so_rather_than_leaving_a_gap(): void
    {
        $this->patient->forceFill(['allergies' => null, 'chronic_conditions' => null])->save();

        $sheet = $this->sheet();

        $this->assertStringContainsString('None recorded', $sheet);
    }

    public function test_it_carries_the_identity_a_receiving_clinic_needs(): void
    {
        $sheet = $this->sheet();

        $this->assertStringContainsString('Joseph Okello', $sheet);
        $this->assertStringContainsString($this->patient->patient_no, $sheet);
        $this->assertStringContainsString($this->visit->visit_no, $sheet);
        $this->assertStringContainsString('Grace Okello', $sheet);
        $this->assertStringContainsString('Dr Aisha Namara', $sheet);
    }

    /** A report is a record: the age is the age they were, not the age they are. */
    public function test_the_age_is_the_age_at_the_time_of_the_visit(): void
    {
        $this->visit->forceFill(['created_at' => now()->subYears(4)])->save();

        $this->assertSame('30 yrs', app(VisitReportService::class)->assemble($this->visit->fresh())['age']);
    }

    public function test_an_infant_is_not_reported_as_zero_years_old(): void
    {
        $this->patient->forceFill(['dob' => now()->subMonths(7)->toDateString()])->save();

        $this->assertSame('7 mo', app(VisitReportService::class)->assemble($this->visit->fresh())['age']);
    }

    // ── Why they came, and what was found ────────────────────────────────

    public function test_it_carries_the_presentation_and_the_findings(): void
    {
        $sheet = $this->sheet();

        $this->assertStringContainsString('Fever and headache for three days', $sheet);
        $this->assertStringContainsString('Night sweats, poor appetite', $sheet);
        $this->assertStringContainsString('Uncomplicated malaria', $sheet);
        $this->assertStringContainsString('Review in 48 hours', $sheet);
    }

    public function test_only_the_vitals_that_were_taken_are_printed(): void
    {
        $vitals = app(VisitReportService::class)->vitals($this->visit);
        $labels = array_column($vitals, 'label');

        $this->assertContains('Temperature', $labels);
        $this->assertContains('Blood pressure', $labels);
        $this->assertNotContains('Height', $labels, 'a reading nobody took was printed as a row of nothing');

        // 38.60 reads as 38.6 — a trailing zero is noise on a chart.
        $this->assertSame('38.6 °C', $vitals[0]['value']);
    }

    public function test_a_visit_with_no_vitals_prints_no_vitals_section(): void
    {
        $bare = Visit::factory()->create([
            'hospital_id' => $this->hospital->id,
            'patient_id' => $this->patient->id,
        ]);

        $report = app(VisitReportService::class)->assemble($bare);

        $this->assertFalse($report['hasVitals']);
        $this->assertStringNotContainsString(
            'Vitals',
            view('pdf.visit-report', ['report' => $report, 'hospital' => $this->hospital])->render(),
        );
    }

    // ── What was done, and what came back ────────────────────────────────

    public function test_every_order_appears_with_its_items_and_its_report(): void
    {
        $order = app(OrderService::class)->place($this->visit, OrderType::Procedure, 'Wound dressing', [], $this->doctor->id);
        app(BillingService::class)->addServiceLine($order, $this->service('12000.00'), 1, $this->doctor->id);
        app(OrderService::class)->saveReport($order, 'Clean wound, no exudate. Dressed with saline gauze.');

        $sheet = $this->sheet();

        $this->assertStringContainsString('Wound dressing', $sheet);
        $this->assertStringContainsString('Clean wound, no exudate', $sheet);
    }

    /** A charge that was struck off is not something that happened. */
    public function test_a_cancelled_line_is_not_reported_as_work_done(): void
    {
        $order = app(OrderService::class)->place($this->visit, OrderType::Procedure, 'Dressing', [], $this->doctor->id);
        $line = app(BillingService::class)->addServiceLine($order, $this->service(), 1, $this->doctor->id);
        $name = $line->name;

        app(OrderService::class)->removeItem($line, $this->doctor->id);

        $this->assertStringNotContainsString($name, $this->sheet());
    }

    public function test_lab_results_are_printed_against_their_reference_ranges(): void
    {
        $test = LabTest::factory()->create([
            'hospital_id' => $this->hospital->id,
            'name' => 'Haemoglobin',
            'unit' => 'g/dL',
            'reference_range' => '13.0-17.0',
        ]);
        $order = app(LabService::class)->order($this->visit, [$test->id], null, $this->doctor->id);

        app(LabService::class)->recordResult($order->items()->firstOrFail(), [
            'result_value' => '8.1',
            'result_flag' => 'low',
        ], $this->doctor->id);

        $sheet = $this->sheet();

        $this->assertStringContainsString('Haemoglobin', $sheet);
        $this->assertStringContainsString('8.1', $sheet);
        $this->assertStringContainsString('13.0-17.0', $sheet);
        $this->assertStringContainsString('g/dL', $sheet);
        // An out-of-range figure is set apart — it is the thing being looked for.
        $this->assertStringContainsString('#b3261e', $sheet);
    }

    public function test_imaging_findings_and_impression_both_appear(): void
    {
        $study = RadiologyStudy::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Chest X-ray']);
        $order = app(RadiologyService::class)->order($this->visit, [$study->id], null, $this->doctor->id);
        app(RadiologyService::class)->recordReport(
            $order,
            'Patchy opacity in the right lower zone.',
            'Features suggest lobar pneumonia.',
            $this->doctor->id,
        );

        $sheet = $this->sheet();

        $this->assertStringContainsString('Chest X-ray', $sheet);
        $this->assertStringContainsString('Patchy opacity in the right lower zone.', $sheet);
        $this->assertStringContainsString('Features suggest lobar pneumonia.', $sheet);
    }

    // ── What they are taking ─────────────────────────────────────────────

    public function test_prescribed_drugs_appear_with_their_doses(): void
    {
        $prescription = $this->visit->prescriptions()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'hospital_id' => $this->hospital->id,
            'patient_id' => $this->patient->id,
            'prescribed_by' => $this->doctor->id,
            'notes' => 'Complete the full course.',
        ]);

        $prescription->doseItems()->create([
            'hospital_id' => $this->hospital->id,
            'drug_name' => 'Artemether/Lumefantrine 20/120mg',
            'dosage' => '4 tablets',
            'slots' => ['morning', 'evening'],
            'days' => 3,
            'start_date' => now()->toDateString(),
            'instructions' => 'Twice daily with food',
        ]);

        $sheet = $this->sheet();

        $this->assertStringContainsString('Artemether/Lumefantrine 20/120mg', $sheet);
        $this->assertStringContainsString('4 tablets', $sheet);
        $this->assertStringContainsString('Twice daily with food', $sheet);
        $this->assertStringContainsString('Complete the full course.', $sheet);
    }

    // ── The stay ─────────────────────────────────────────────────────────

    public function test_an_admission_is_reported_with_its_length_and_its_notes(): void
    {
        $ward = Ward::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Medical Ward A']);
        $bed = Bed::factory()->create([
            'hospital_id' => $this->hospital->id,
            'ward_id' => $ward->id,
            'status' => BedStatus::Available,
        ]);

        $admission = app(AdmissionService::class)->admit($this->patient, $bed, [
            'admitted_at' => now()->subDays(3)->toDateTimeString(),
            'admitting_doctor_id' => $this->doctor->id,
            'reason' => 'Severe dehydration',
            'title' => 'Admit for IV fluids',
            'visit_id' => $this->visit->id,
        ], $this->doctor->id);

        app(AdmissionService::class)->discharge(
            $admission,
            \App\Enums\AdmissionStatus::Discharged,
            'Rehydrated, tolerating oral fluids. Discharged stable.',
            $this->doctor->id,
        );

        $sheet = $this->sheet();

        $this->assertStringContainsString('Medical Ward A', $sheet);
        $this->assertStringContainsString('Severe dehydration', $sheet);
        $this->assertStringContainsString('Discharged stable.', $sheet);
    }

    // ── What it cost ─────────────────────────────────────────────────────

    /** The report must never quote a figure the bill panel would not. */
    public function test_an_uninvoiced_visit_shows_the_bill_as_it_stands(): void
    {
        $order = app(OrderService::class)->place($this->visit, OrderType::Consultation, 'Consultation', [], $this->doctor->id);
        app(BillingService::class)->addServiceLine($order, $this->service('30000.00'), 2, $this->doctor->id);

        $totals = app(VisitReportService::class)->assemble($this->visit->fresh())['totals'];
        $panel = app(BillingService::class)->totalsFor($this->visit->fresh()->load('orderItems'));

        $this->assertSame('60000.00', $totals['subtotal']);
        $this->assertSame($panel['due'], $totals['total']);
        $this->assertSame($panel['due'], $totals['balance']);
    }

    public function test_an_invoiced_visit_shows_the_invoices_own_figures(): void
    {
        $order = app(OrderService::class)->place($this->visit, OrderType::Consultation, 'Consultation', [], $this->doctor->id);
        app(BillingService::class)->addServiceLine($order, $this->service('50000.00'), 1, $this->doctor->id);

        $invoice = app(BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->doctor->id);
        app(BillingService::class)->recordPayment($invoice, PaymentMethod::Cash, '20000.00', [], $this->doctor->id);

        $report = app(VisitReportService::class)->assemble($this->visit->fresh());

        $this->assertSame('50000.00', $report['totals']['total']);
        $this->assertSame('20000.00', $report['totals']['paid']);
        $this->assertSame('30000.00', $report['totals']['balance']);
        $this->assertStringContainsString($invoice->invoice_no, $this->sheet());
    }

    // ── Files ────────────────────────────────────────────────────────────

    /**
     * A PDF cannot carry an X-ray image, so it says what exists and where to
     * ask for it rather than implying there was nothing.
     */
    public function test_it_says_what_files_are_held_that_it_cannot_reproduce(): void
    {
        \Illuminate\Support\Facades\Storage::fake('private');

        $order = app(OrderService::class)->place($this->visit, OrderType::Imaging, 'Chest film', [], $this->doctor->id);
        app(\App\Services\OrderAttachmentService::class)->store(
            $order,
            \Illuminate\Http\UploadedFile::fake()->image('film.jpg'),
            $this->doctor->id,
        );

        $sheet = $this->sheet();

        $this->assertStringContainsString('film.jpg', $sheet);
        $this->assertStringContainsString('not reproduced here', $sheet);
        $this->assertSame(1, app(VisitReportService::class)->assemble($this->visit->fresh())['attachments']);
    }

    // ── The route ────────────────────────────────────────────────────────

    public function test_the_report_opens_in_the_browser_rather_than_downloading(): void
    {
        $response = $this->get(route('admin.visits.report', $this->visit));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringStartsWith('inline', $disposition);
        $this->assertStringContainsString($this->visit->visit_no.'.pdf', $disposition);
    }

    public function test_another_hospitals_visit_cannot_be_printed(): void
    {
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        $theirs = Visit::factory()->create([
            'hospital_id' => $other->id,
            'patient_id' => Patient::factory()->create(['hospital_id' => $other->id])->id,
        ]);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->get(route('admin.visits.report', $theirs))->assertNotFound();
    }

    public function test_a_role_without_visit_access_cannot_print_one(): void
    {
        $pharmacist = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'pharmacist']);
        $pharmacist->syncRoles([]);
        $this->actingAs($pharmacist);

        $this->get(route('admin.visits.report', $this->visit))->assertForbidden();
    }

    /** A visit with nothing on it still produces a document, not an error. */
    public function test_an_empty_visit_still_prints(): void
    {
        $bare = Visit::factory()->create([
            'hospital_id' => $this->hospital->id,
            'patient_id' => Patient::factory()->create(['hospital_id' => $this->hospital->id])->id,
        ]);

        $this->get(route('admin.visits.report', $bare))->assertOk();
    }
}
