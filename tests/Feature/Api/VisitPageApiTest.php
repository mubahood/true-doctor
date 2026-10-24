<?php

namespace Tests\Feature\Api;

use App\Enums\DentalTreatmentStatus;
use App\Enums\Weekday;
use App\Models\DentalChartEntry;
use App\Models\Department;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\OrderItem;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Services\DentalCaseSheetService;
use App\Services\DentalService;
use App\Services\FollowUpService;
use App\Services\PrescriptionService;
use App\Support\CurrentHospital;
use App\Support\Practice\VisitForm;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The visit page on the phone (docs/api/visit-page.md): the visit report and
 * the prescription as the web prints them, the visit form every signed-in
 * user is told about, how one visit's page is laid out, the free times for a
 * review booked from it, the medicines a dosage is written from, and the
 * tooth picker's writes as the web makes them.
 */
class VisitPageApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $dental;

    private User $dentist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->dental = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'dental_clinic']]]);
        app(CurrentHospital::class)->set($this->dental->id);
        $this->dentist = $this->staff('doctor', $this->dental);
    }

    private function staff(string $role, Hospital $hospital): User
    {
        $user = User::factory()->create(['hospital_id' => $hospital->id, 'role' => $role]);
        $user->syncSpatieRole();

        return $user;
    }

    private function visit(Hospital $hospital, array $attributes = []): Visit
    {
        $patient = Patient::factory()->create(['hospital_id' => $hospital->id, 'sex' => 'female', 'dob' => now()->subYears(30)->toDateString()]);

        return Visit::factory()->create(['hospital_id' => $hospital->id, 'patient_id' => $patient->id, 'status' => 'ongoing'] + $attributes);
    }

    private function sits(User $doctor, Carbon $day, Hospital $hospital): void
    {
        DoctorSchedule::query()->create([
            'hospital_id' => $hospital->id, 'user_id' => $doctor->id, 'weekday' => Weekday::from($day->dayOfWeek),
            'start_time' => '08:00:00', 'end_time' => '12:00:00', 'slot_minutes' => 15, 'is_active' => true,
        ]);
    }

    // ── The documents ──────────────────────────────────────────────────

    public function test_the_visit_report_is_the_webs_document_behind_the_webs_policy(): void
    {
        $visit = $this->visit($this->dental, ['doctor_user_id' => $this->dentist->id]);

        $this->get("/api/v1/visits/{$visit->uuid}/report", ['Accept' => 'application/json'])->assertStatus(401);

        // A dental clinic hands the patient the summary: the same file the web prints.
        Sanctum::actingAs($this->dentist);
        $response = $this->get("/api/v1/visits/{$visit->uuid}/report")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('visit-summary-'.$visit->visit_no, (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('content-disposition'));

        // The dental clinic's desk was given the report on its own (visits.report).
        Sanctum::actingAs($this->staff('receptionist', $this->dental));
        $this->get("/api/v1/visits/{$visit->uuid}/report")->assertOk()->assertHeader('content-type', 'application/pdf');

        // A general hospital prints the full record, and its desk may not print it.
        $general = Hospital::factory()->create();
        app(CurrentHospital::class)->set($general->id);
        $theirs = $this->visit($general);
        Sanctum::actingAs($this->staff('doctor', $general));
        $full = $this->get("/api/v1/visits/{$theirs->uuid}/report")->assertOk();
        $this->assertStringContainsString('visit-report-'.$theirs->visit_no, (string) $full->headers->get('content-disposition'));
        Sanctum::actingAs($this->staff('receptionist', $general));
        $this->getJson("/api/v1/visits/{$theirs->uuid}/report")->assertForbidden()->assertJsonPath('code', 'forbidden');

        // Another hospital's visit is not found.
        $this->getJson("/api/v1/visits/{$visit->uuid}/report")->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_the_prescription_prints_for_whoever_reads_the_clinical_record(): void
    {
        $visit = $this->visit($this->dental, ['doctor_user_id' => $this->dentist->id]);
        $item = StockItem::factory()->create(['hospital_id' => $this->dental->id, 'name' => 'Amoxicillin 500mg']);
        app(PrescriptionService::class)->writeDosage($visit, [['stock_item_id' => $item->id, 'dose' => '1 capsule', 'frequency' => 'Three times a day', 'days' => 5, 'note' => null]], $this->dentist->id);

        Sanctum::actingAs($this->dentist);
        $response = $this->get("/api/v1/visits/{$visit->uuid}/prescription")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('prescription-', (string) $response->headers->get('content-disposition'));

        // The desk prints the report, never the clinical record's prescription.
        Sanctum::actingAs($this->staff('receptionist', $this->dental));
        $this->getJson("/api/v1/visits/{$visit->uuid}/prescription")->assertForbidden();

        $other = Hospital::factory()->create();
        Sanctum::actingAs($this->staff('doctor', $other));
        $this->getJson("/api/v1/visits/{$visit->uuid}/prescription")->assertNotFound();
    }

    // ── The visit form, for everyone ───────────────────────────────────

    public function test_every_signed_in_user_is_told_how_a_visit_is_written_here(): void
    {
        foreach (['doctor', 'nurse', 'receptionist'] as $role) {
            Sanctum::actingAs($this->staff($role, $this->dental));
            $form = $this->getJson('/api/v1/meta')->assertOk()->json('data.visit_form');

            $this->assertSame(['blood_pressure'], $form['vitals'], $role);
            $this->assertSame('Blood pressure', $form['vitals_title']);
            $this->assertFalse($form['shows_bmi']);
            $this->assertSame('simple', $form['dental_notes']);
            $this->assertFalse($form['asks_reason']);
            $this->assertTrue($form['dental_clinic']);
            $this->assertTrue($form['dosage']);
            $this->assertSame(['summary', 'chart', 'plan', 'prescription', 'orders', 'bill', 'payments', 'history'], $form['sections']);
        }

        // One source: the web's own VisitForm, and the same block in bootstrap.
        $this->assertSame(VisitForm::of($this->dental)->toArray(), $form);
        $this->assertSame($form, $this->getJson('/api/v1/bootstrap')->assertOk()->json('data.meta.visit_form'));

        // A general hospital records every vital and goes from the summary to the orders.
        $general = Hospital::factory()->create();
        Sanctum::actingAs($this->staff('nurse', $general));
        $form = $this->getJson('/api/v1/meta')->assertOk()->json('data.visit_form');
        $this->assertSame(array_keys(VisitForm::VITALS), $form['vitals']);
        $this->assertTrue($form['shows_bmi']);
        $this->assertTrue($form['asks_reason']);
        $this->assertFalse($form['dental_clinic']);
        $this->assertSame(['summary', 'orders', 'bill', 'payments', 'history'], $form['sections']);

        // A clinic's own choice reaches everyone, not only the admin who made it.
        $this->dental->forceFill(['settings' => ['practice' => ['sector' => 'dental_clinic'], 'clinical' => ['vitals' => ['blood_pressure', 'pulse'], 'dental_notes' => 'detailed']]])->save();
        Sanctum::actingAs($this->staff('nurse', $this->dental));
        $form = $this->getJson('/api/v1/meta')->assertOk()->json('data.visit_form');
        $this->assertSame(['blood_pressure', 'pulse'], $form['vitals']);
        $this->assertSame('Vital signs', $form['vitals_title']);
        $this->assertSame('detailed', $form['dental_notes']);
    }

    public function test_one_visit_says_how_its_page_is_laid_out_and_a_list_does_not(): void
    {
        Sanctum::actingAs($this->dentist);
        $simple = $this->visit($this->dental);
        $this->getJson("/api/v1/visits/{$simple->uuid}")->assertOk()
            ->assertJsonPath('data.layout', ['notes' => 'simple', 'chart' => null]);
        $this->assertArrayNotHasKey('layout', $this->getJson('/api/v1/visits')->assertOk()->json('data.0'));

        // A visit already written on the case sheet stays on it, whatever the clinic chose since.
        $detailed = $this->visit($this->dental);
        app(DentalCaseSheetService::class)->addDiagnosis($detailed, 'gingivitis', null, null, $this->dentist);
        $this->getJson("/api/v1/visits/{$detailed->uuid}")->assertOk()->assertJsonPath('data.layout.notes', 'case_sheet');
        $this->postJson("/api/v1/visits/{$detailed->uuid}/vitals", ['blood_pressure' => '120/80'])->assertOk()->assertJsonPath('data.layout.notes', 'case_sheet');

        // A general hospital that does dentistry: the general note, the chart on a press...
        $general = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'general_hospital']]]);
        app(CurrentHospital::class)->set($general->id);
        $doctor = $this->staff('doctor', $general);
        Sanctum::actingAs($doctor);
        $malaria = $this->visit($general, ['department_id' => Department::factory()->create(['hospital_id' => $general->id, 'name' => 'Outpatient'])->id]);
        $this->getJson("/api/v1/visits/{$malaria->uuid}")->assertOk()->assertJsonPath('data.layout', ['notes' => 'general', 'chart' => 'press']);
        // ...on its own once the mouth is charted, and the case sheet in a dental department.
        app(DentalService::class)->record($malaria->patient, [['tooth' => 11, 'finding' => 'caries', 'surfaces' => 'M']], null, $doctor);
        $this->getJson("/api/v1/visits/{$malaria->uuid}")->assertOk()->assertJsonPath('data.layout.chart', 'open');
        $chair = $this->visit($general, ['department_id' => Department::factory()->create(['hospital_id' => $general->id, 'name' => 'Dental'])->id]);
        $this->getJson("/api/v1/visits/{$chair->uuid}")->assertOk()->assertJsonPath('data.layout', ['notes' => 'case_sheet', 'chart' => null]);

        // A practice with no dentistry has no chart at all.
        $clinic = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'medical_clinic']]]);
        app(CurrentHospital::class)->set($clinic->id);
        Sanctum::actingAs($this->staff('doctor', $clinic));
        $this->getJson('/api/v1/visits/'.$this->visit($clinic)->uuid)->assertOk()->assertJsonPath('data.layout', ['notes' => 'general', 'chart' => null]);
    }

    // ── The review ─────────────────────────────────────────────────────

    public function test_the_review_times_are_the_case_sheets_on_a_dental_visit(): void
    {
        $visit = $this->visit($this->dental, ['doctor_user_id' => $this->dentist->id, 'created_at' => now()]);
        $day = Carbon::today()->addDays(9);
        $this->sits($this->dentist, $day, $this->dental);
        Sanctum::actingAs($this->dentist);

        // No day asked: the first day from the suggestion (a week, nothing done yet) the dentist is free.
        $times = $this->getJson("/api/v1/visits/{$visit->uuid}/review/times")->assertOk()
            ->assertJsonPath('data.kind', 'dental')
            ->assertJsonPath('data.date', $day->toDateString())
            ->assertJsonPath('data.minutes', DentalCaseSheetService::REVIEW_MINUTES)
            ->assertJsonPath('data.doctor_user_id', $this->dentist->id)
            ->assertJsonPath('data.doctor', $this->dentist->name)
            ->assertJsonPath('data.note', null)
            ->json('data.times');
        // 08:00 to 12:00 in half hours on the 15-minute grid.
        $this->assertSame('08:00', $times[0]);
        $this->assertSame('11:30', end($times));

        // The time offered is a time that books, through the case sheet.
        $this->postJson("/api/v1/visits/{$visit->uuid}/dental-case-sheet/review", ['date' => $day->toDateString(), 'time' => '08:00'])->assertCreated();
        $this->assertNotContains('08:00', $this->getJson("/api/v1/visits/{$visit->uuid}/review/times?date=".$day->toDateString())->json('data.times'));

        // A day the dentist does not sit says so in words.
        $this->getJson("/api/v1/visits/{$visit->uuid}/review/times?date=".$day->copy()->addDay()->toDateString())->assertOk()
            ->assertJsonPath('data.times', [])
            ->assertJsonPath('data.note', fn ($note) => str_contains((string) $note, 'has no availability'));

        // A visit with no dentist yet books with whoever is booking.
        $unassigned = $this->visit($this->dental, ['created_at' => now()]);
        $this->getJson("/api/v1/visits/{$unassigned->uuid}/review/times?date=".$day->toDateString())->assertOk()
            ->assertJsonPath('data.doctor_user_id', $this->dentist->id);
    }

    public function test_the_review_times_are_book_a_reviews_on_a_general_visit(): void
    {
        $general = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'medical_clinic']]]);
        app(CurrentHospital::class)->set($general->id);
        $doctor = $this->staff('doctor', $general);
        $visit = $this->visit($general, ['doctor_user_id' => $doctor->id, 'created_at' => now()]);
        $suggested = Carbon::parse(app(FollowUpService::class)->suggestion($visit)['date']);
        $this->sits($doctor, $suggested, $general);
        Sanctum::actingAs($this->staff('receptionist', $general));

        $times = $this->getJson("/api/v1/visits/{$visit->uuid}/review/times")->assertOk()
            ->assertJsonPath('data.kind', 'general')
            ->assertJsonPath('data.date', $suggested->toDateString())
            ->assertJsonPath('data.minutes', FollowUpService::REVIEW_MINUTES)
            ->json('data.times');
        $this->assertSame('11:45', end($times), 'a quarter-hour review fits the last slot');

        // No doctor on the visit: the web's own sentence, and no times.
        $nobody = $this->visit($general, ['created_at' => now()]);
        $this->getJson("/api/v1/visits/{$nobody->uuid}/review/times")->assertOk()
            ->assertJsonPath('data.times', [])
            ->assertJsonPath('data.note', 'This visit has no doctor yet. Book the review from Appointments instead.');

        // A day in the past is refused against the field.
        $this->getJson("/api/v1/visits/{$visit->uuid}/review/times?date=".Carbon::yesterday()->toDateString())
            ->assertStatus(422)->assertJsonPath('errors.date.0', 'Choose today or a later day.');

        // Whoever cannot book a review is not shown the times, and another clinic's visit is not found.
        Sanctum::actingAs($this->staff('nurse', $general));
        $this->getJson("/api/v1/visits/{$visit->uuid}/review/times")->assertForbidden();
        Sanctum::actingAs($this->dentist);
        $this->getJson("/api/v1/visits/{$visit->uuid}/review/times")->assertNotFound();
    }

    public function test_booking_the_review_twice_with_one_key_books_it_once(): void
    {
        $general = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'medical_clinic']]]);
        app(CurrentHospital::class)->set($general->id);
        $doctor = $this->staff('doctor', $general);
        $visit = $this->visit($general, ['doctor_user_id' => $doctor->id, 'created_at' => now()]);
        $day = Carbon::today()->addDays(3);
        $this->sits($doctor, $day, $general);
        Sanctum::actingAs($this->staff('receptionist', $general));

        $body = ['date' => $day->toDateString(), 'time' => '09:00'];
        $first = $this->postJson("/api/v1/visits/{$visit->uuid}/review", $body, ['Idempotency-Key' => 'review-1'])->assertCreated();
        $again = $this->postJson("/api/v1/visits/{$visit->uuid}/review", $body, ['Idempotency-Key' => 'review-1'])->assertCreated();
        $this->assertSame($first->json('data.uuid'), $again->json('data.uuid'));
        $this->assertSame(1, \App\Models\Appointment::query()->where('origin_visit_id', $visit->id)->count());

        $this->postJson("/api/v1/visits/{$visit->uuid}/review", ['date' => $day->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors(['time'], 'errors');
    }

    // ── The dosage's medicines ─────────────────────────────────────────

    public function test_the_medicines_are_every_active_item_even_out_of_stock(): void
    {
        StockItem::factory()->create(['hospital_id' => $this->dental->id, 'name' => 'Paracetamol 500mg', 'sku' => 'PCM-500', 'unit' => 'tablets', 'current_quantity' => '0', 'is_active' => true]);
        StockItem::factory()->create(['hospital_id' => $this->dental->id, 'name' => 'Amoxicillin 500mg', 'unit' => 'capsules', 'current_quantity' => '40.00', 'is_active' => true]);
        StockItem::factory()->create(['hospital_id' => $this->dental->id, 'name' => 'Paracetamol syrup', 'is_active' => false]);
        $elsewhere = Hospital::factory()->create();
        StockItem::factory()->create(['hospital_id' => $elsewhere->id, 'name' => 'Paracetamol 1g', 'is_active' => true]);

        Sanctum::actingAs($this->dentist);
        $rows = $this->getJson('/api/v1/dosage/medicines?q=para')->assertOk()->json('data');
        $this->assertSame(['Paracetamol 500mg'], array_column($rows, 'name'), 'not the inactive one, not another clinic\'s');
        $this->assertFalse($rows[0]['in_stock']);
        $this->assertSame('Out of stock', $rows[0]['meta']);
        $this->assertIsInt($rows[0]['id']);

        $this->assertSame('Paracetamol 500mg', $this->getJson('/api/v1/dosage/medicines?q=PCM')->json('data.0.name'), 'by the code on the label too');
        $all = $this->getJson('/api/v1/dosage/medicines')->assertOk()->json('data');
        $this->assertSame(['Amoxicillin 500mg', 'Paracetamol 500mg'], array_column($all, 'name'));
        $this->assertSame('40 capsules in stock', $all[0]['meta']);

        // What the picker offers is what the dosage accepts.
        $visit = $this->visit($this->dental);
        $this->postJson("/api/v1/visits/{$visit->uuid}/dosage", ['items' => [['stock_item_id' => $rows[0]['id'], 'dose' => '1 tablet', 'frequency' => 'Twice a day', 'days' => 3]]])->assertCreated();

        Sanctum::actingAs($this->staff('nurse', $this->dental));
        $this->getJson('/api/v1/dosage/medicines')->assertForbidden();
    }

    // ── The case sheet and the tooth picker ────────────────────────────

    public function test_the_case_sheet_says_whether_pregnancy_is_asked(): void
    {
        $this->dental->forceFill(['settings' => ['practice' => ['sector' => 'dental_clinic'], 'clinical' => ['dental_notes' => 'detailed']]])->save();
        Sanctum::actingAs($this->dentist);

        $woman = $this->visit($this->dental);
        $this->getJson("/api/v1/visits/{$woman->uuid}/dental-case-sheet")->assertOk()->assertJsonPath('data.asks_pregnancy', true);

        $man = $this->visit($this->dental);
        $man->patient->forceFill(['sex' => 'male'])->save();
        $this->getJson("/api/v1/visits/{$man->uuid}/dental-case-sheet")->assertOk()->assertJsonPath('data.asks_pregnancy', false);
    }

    public function test_a_tooth_set_from_the_visit_is_charted_on_it_online_and_through_sync(): void
    {
        $visit = $this->visit($this->dental, ['doctor_user_id' => $this->dentist->id]);
        Sanctum::actingAs($this->dentist);

        $this->postJson("/api/v1/patients/{$visit->patient->uuid}/dental/findings", [
            'visit_uuid' => $visit->uuid,
            'findings' => [['uuid' => (string) Str::uuid(), 'tooth' => 36, 'finding' => 'mobility', 'mobility' => 0]],
        ])->assertCreated();
        $entry = DentalChartEntry::query()->sole();
        $this->assertSame($visit->id, $entry->visit_id);
        $this->assertSame(0, $entry->mobility, 'grade 0 is a grade, as on the web');

        // Offline, through the outbox: the same finding on the same visit, once however often it is sent.
        $uuid = (string) Str::uuid();
        $handler = app(\App\Services\Sync\Handlers\DentalChartHandler::class);
        $op = new \App\Models\SyncOperation(['device_id' => null, 'operation_id' => (string) Str::uuid()]);
        $payload = ['uuid' => $uuid, 'patient_uuid' => $visit->patient->uuid, 'visit_uuid' => $visit->uuid, 'tooth' => 11, 'finding' => 'note', 'notes' => 'Chipped edge'];
        $handler->apply('create', $payload, $op, $this->dentist);
        $handler->apply('create', $payload, $op, $this->dentist);
        $this->assertSame($visit->id, DentalChartEntry::query()->where('uuid', $uuid)->sole()->visit_id);

        // A note without its words is refused against the field the form shows.
        $this->postJson("/api/v1/patients/{$visit->patient->uuid}/dental/findings", ['visit_uuid' => $visit->uuid, 'findings' => [['tooth' => 21, 'finding' => 'note']]])
            ->assertStatus(422)->assertJsonValidationErrors(['findings.0.notes'], 'errors');
    }

    public function test_a_procedure_done_from_the_visit_marks_its_tooth_by_the_price_list_once(): void
    {
        $visit = $this->visit($this->dental, ['doctor_user_id' => $this->dentist->id]);
        $extraction = Service::factory()->create(['hospital_id' => $this->dental->id, 'name' => 'Tooth extraction', 'price' => '50000', 'marks_tooth_as' => 'missing']);
        Sanctum::actingAs($this->dentist);

        $item = $this->postJson("/api/v1/patients/{$visit->patient->uuid}/dental/plan", ['service_id' => $extraction->id, 'tooth' => 48, 'visit_uuid' => $visit->uuid])
            ->assertCreated()->json('data.uuid');

        $body = ['status' => DentalTreatmentStatus::Done->value, 'visit_uuid' => $visit->uuid];
        $this->postJson("/api/v1/dental/plan/{$item}/status", $body, ['Idempotency-Key' => 'done-48'])->assertOk()->assertJsonPath('data.billed', true);
        $this->postJson("/api/v1/dental/plan/{$item}/status", $body, ['Idempotency-Key' => 'done-48'])->assertOk();

        $chart = collect($this->getJson("/api/v1/patients/{$visit->patient->uuid}/dental")->assertOk()->json('data.chart'))->keyBy('tooth');
        $this->assertSame('missing', $chart[48]['finding'], 'Marks the tooth as: an extraction leaves it missing');
        $this->assertSame(1, DentalChartEntry::query()->where('tooth', 48)->where('visit_id', $visit->id)->count());
        $this->assertSame(1, OrderItem::query()->where('service_id', $extraction->id)->count(), 'billed once');

        // The visit's case sheet lists it under Treatment done today.
        $this->getJson("/api/v1/visits/{$visit->uuid}/dental-case-sheet")->assertOk()
            ->assertJsonPath('data.done_today.0.procedure', 'Tooth extraction')
            ->assertJsonPath('data.done_today.0.tooth', 48);
    }
}
