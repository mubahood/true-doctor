<?php

namespace Tests\Feature;

use App\Enums\DentalTreatmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PatientSex;
use App\Enums\Weekday;
use App\Livewire\Visits\Panels\CaseSheetSection;
use App\Livewire\Visits\Panels\DentalDiagnoses;
use App\Livewire\Visits\Panels\DentalFollowUp;
use App\Livewire\Visits\Panels\DentalXrays;
use App\Models\Appointment;
use App\Models\CaseSheetEntry;
use App\Models\DentalXray;
use App\Models\Department;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\Order;
use App\Models\Patient;
use App\Models\PatientHistoryEntry;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitDiagnosis;
use App\Services\CaseSheetService;
use App\Services\DentalCaseSheetService;
use App\Services\DentalService;
use App\Services\VisitReportService;
use App\Services\VisitService;
use App\Support\Clinical\PatientAlerts;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dental case sheet (docs/dental.md, "The case sheet") and the visit
 * report's small fixes: versioned sections, the patient's own history with
 * "Still correct", diagnoses per tooth that mark the chart, X-rays at the
 * chair, the review, the printed sheet, the app, roles and tenancy.
 */
class DentalCaseSheetTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $dentist;

    private Patient $patient;

    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Livewire::withoutLazyLoading();

        // The detailed case sheet: a dental clinic's choice since simple notes became its default (DentalNotesTest).
        $this->hospital = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'dental_clinic'], 'clinical' => ['dental_notes' => 'detailed']]]);
        app(CurrentHospital::class)->set($this->hospital->id);
        $this->dentist = $this->staff('doctor');
        $this->patient = Patient::factory()->create([
            'hospital_id' => $this->hospital->id, 'sex' => PatientSex::Female,
            'dob' => now()->subYears(30)->toDateString(), 'allergies' => null,
        ]);
        $this->visit = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $this->patient->id, 'reason' => 'Toothache', 'complaints' => 'Pain lower left']);
    }

    private function staff(string $role, ?Hospital $hospital = null): User
    {
        $user = User::factory()->create(['hospital_id' => ($hospital ?? $this->hospital)->id, 'role' => $role]);
        $user->syncSpatieRole();

        return $user;
    }

    private function sections(): CaseSheetService
    {
        return app(CaseSheetService::class);
    }

    private function sheets(): DentalCaseSheetService
    {
        return app(DentalCaseSheetService::class);
    }

    // ── Where it shows ─────────────────────────────────────────────────

    public function test_it_replaces_the_general_note_in_a_dental_clinic_and_in_a_dental_department_only(): void
    {
        $this->assertTrue(DentalCaseSheetService::appliesTo($this->visit, $this->hospital));

        $general = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'general_hospital', 'packs' => ['dental']]]]);
        app(CurrentHospital::class)->set($general->id);
        $dental = Department::factory()->create(['hospital_id' => $general->id, 'name' => 'Dental', 'is_active' => true]);
        $opd = Department::factory()->create(['hospital_id' => $general->id, 'name' => 'Outpatient', 'is_active' => true]);
        $a = Visit::factory()->create(['hospital_id' => $general->id, 'department_id' => $dental->id]);
        $b = Visit::factory()->create(['hospital_id' => $general->id, 'department_id' => $opd->id]);

        $this->assertTrue(DentalCaseSheetService::appliesTo($a, $general));
        $this->assertFalse(DentalCaseSheetService::appliesTo($b, $general), 'a malaria review keeps the general note');

        $doctor = $this->staff('doctor', $general);
        $this->actingAs($doctor)->get(route('admin.visits.show', $b))->assertOk()->assertDontSee('Dental consultation');
    }

    public function test_the_visit_page_shows_the_case_sheet_to_the_dentist_and_not_the_notes_to_the_desk(): void
    {
        $this->actingAs($this->dentist)->get(route('admin.visits.show', $this->visit))->assertOk()
            ->assertSee('Dental consultation')->assertSee('Examination')->assertSee('Treatment done today')->assertSee('Follow-up');

        $desk = $this->staff('receptionist');
        $this->actingAs($desk)->get(route('admin.visits.show', $this->visit))->assertOk()
            ->assertSee('You do not have access to this');

        Livewire::actingAs($desk)->test(CaseSheetSection::class, ['visitId' => $this->visit->id, 'section' => 'complaint'])->assertForbidden();
    }

    // ── Visit sections ─────────────────────────────────────────────────

    public function test_a_section_is_versioned_append_only_and_a_stale_save_is_refused(): void
    {
        $nurse = $this->staff('nurse');
        $this->sections()->save($this->visit, 'complaint', ['picks' => ['toothache', 'not-an-option'], 'where' => 'Lower left', 'duration' => '5 days', 'worse' => ['cold']], $this->dentist, 0);
        $this->sections()->save($this->visit, 'complaint', ['picks' => ['toothache', 'swelling'], 'duration' => '6 days'], $this->dentist, 1);

        $this->assertSame(2, CaseSheetEntry::count(), 'every version kept');
        $current = $this->sections()->sections($this->visit)['complaint'];
        $this->assertSame(2, $current['version']);
        $this->assertSame(['toothache', 'swelling'], $current['data']['picks']);

        $this->expectException(ValidationException::class);
        $this->sections()->save($this->visit, 'complaint', ['picks' => ['cleaning']], $nurse, 1);
    }

    public function test_options_are_checked_and_the_complaint_and_the_doctor_follow_onto_the_visit(): void
    {
        $this->sections()->save($this->visit, 'complaint', ['picks' => ['toothache', 'bogus'], 'where' => 'Lower left back tooth', 'duration' => '5 days'], $this->dentist);

        $data = $this->sections()->sections($this->visit)['complaint']['data'];
        $this->assertSame(['toothache'], $data['picks']);
        $visit = $this->visit->fresh();
        $this->assertSame('Toothache. Lower left back tooth. 5 days. Pain lower left', $visit->complaints, 'what reception wrote is kept');
        $this->assertSame($this->dentist->id, $visit->doctor_user_id, 'the first clinician to write becomes the doctor');
    }

    public function test_normal_in_one_tap_clears_the_findings_and_keeps_the_hygiene(): void
    {
        $this->sections()->save($this->visit, 'intra_oral', ['gingiva' => 'Inflamed', 'hygiene' => 'fair'], $this->dentist);
        $this->sections()->markNormal($this->visit, 'intra_oral', $this->dentist);

        $data = $this->sections()->sections($this->visit)['intra_oral']['data'];
        $this->assertTrue($data['normal']);
        $this->assertArrayNotHasKey('gingiva', $data);
        $this->assertSame('fair', $data['hygiene']);
        $this->assertSame([['label' => 'Findings', 'value' => 'Normal'], ['label' => 'Oral hygiene', 'value' => 'Fair']], CaseSheetService::lines('intra_oral', $data));
    }

    // ── The patient's own history ──────────────────────────────────────

    public function test_the_medical_history_belongs_to_the_patient_puts_red_flags_on_the_alert_bar_and_is_confirmed_each_visit(): void
    {
        $this->sections()->savePatientHistory($this->patient, 'medical', [
            'allergies' => 'Penicillin, Latex', 'red_flags' => ['bleeding', 'nonsense'], 'medicines' => 'Warfarin 5 mg', 'pregnancy' => 'pregnant',
        ], $this->visit, $this->dentist);

        $patient = $this->patient->fresh();
        $this->assertSame(['Penicillin', 'Latex'], $patient->allergies);
        $this->assertSame(['bleeding', 'pregnant'], $patient->medical_flags);
        $this->assertSame(['Bleeding disorder or blood thinners', 'Pregnant'], PatientAlerts::for($patient)['medical_flags']);
        $summary = collect(app(\App\Services\PatientSummaryService::class)->alerts($patient, [], []))->firstWhere('key', 'medical_flags');
        $this->assertSame(['Bleeding disorder or blood thinners', 'Pregnant'], $summary['items'], 'on the patient record too');

        // HIV or hepatitis is said as the precaution on the bar every desk sees.
        $this->sections()->savePatientHistory($patient, 'medical', ['red_flags' => ['bleeding', 'hiv_hepatitis'], 'pregnancy' => 'pregnant'], $this->visit, $this->dentist);
        $this->assertSame(['Bleeding disorder or blood thinners', 'Infection-control precautions', 'Pregnant'], PatientAlerts::for($patient->fresh())['medical_flags']);
        $this->assertStringNotContainsString('hiv', (string) \Illuminate\Support\Facades\DB::table('patients')->where('id', $patient->id)->value('medical_flags'), 'encrypted at rest');

        // The next visit: a pregnancy is asked again, never confirmed in one tap.
        $next = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $this->patient->id]);
        try {
            $this->sections()->confirmPatientHistory($patient, 'medical', $next, $this->dentist);
            $this->fail('a pregnancy is asked again');
        } catch (ValidationException) {
        }
        $this->sections()->savePatientHistory($patient, 'medical', ['red_flags' => ['bleeding'], 'medicines' => 'Warfarin 5 mg', 'pregnancy' => 'no'], $this->visit, $this->dentist);

        // Then: shown filled, confirmed in one tap, the same data, a version of its own, once per visit.
        $confirmed = $this->sections()->confirmPatientHistory($patient, 'medical', $next, $this->dentist);
        $again = $this->sections()->confirmPatientHistory($patient, 'medical', $next, $this->dentist);
        $this->assertTrue($confirmed->confirmed);
        $this->assertSame($confirmed->id, $again->id, 'asked once per visit');
        $this->assertSame(4, PatientHistoryEntry::query()->where('section', 'medical')->count());
        $this->assertSame('Warfarin 5 mg', $confirmed->data['medicines']);
        $this->assertSame(['Penicillin', 'Latex'], $confirmed->data['allergies']);
        $this->assertTrue($this->sections()->confirmedOn($patient, 'medical', $next));

        // An empty allergy box leaves the allergies as they were.
        $this->sections()->savePatientHistory($patient, 'medical', ['allergies' => '', 'red_flags' => []], $next, $this->dentist);
        $this->assertSame(['Penicillin', 'Latex'], $patient->fresh()->allergies);
        $this->assertNull($patient->fresh()->medical_flags);

        // An allergy added on the patient form meanwhile survives the dentist's edit of what they saw.
        $patient->fresh()->forceFill(['allergies' => ['Penicillin', 'Latex', 'Aspirin']])->save();
        $this->sections()->savePatientHistory($patient, 'medical', ['allergies' => 'Penicillin, Chlorhexidine'], $next, $this->dentist, null, null, ['allergies' => 'Penicillin, Latex']);
        $this->assertSame(['Penicillin', 'Aspirin', 'Chlorhexidine'], $patient->fresh()->allergies);

        // "None" clears the long-term conditions.
        $patient->fresh()->forceFill(['chronic_conditions' => ['Asthma']])->save();
        $this->sections()->savePatientHistory($patient, 'medical', ['conditions' => 'None'], $next, $this->dentist);
        $this->assertSame([], $patient->fresh()->chronic_conditions);
    }

    public function test_pregnancy_is_asked_only_of_a_woman_of_an_age_to_be_asked(): void
    {
        $man = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'sex' => PatientSex::Male]);
        $this->sections()->savePatientHistory($man, 'medical', ['pregnancy' => 'pregnant'], null, $this->dentist);
        $this->assertNull($man->fresh()->medical_flags);
        $this->assertFalse(CaseSheetService::asksPregnancy($man));
        $this->assertTrue(CaseSheetService::asksPregnancy($this->patient));
    }

    // ── Diagnosis ──────────────────────────────────────────────────────

    public function test_a_diagnosis_per_tooth_is_coded_marks_the_chart_and_is_written_on_the_visit(): void
    {
        $this->sheets()->addDiagnosis($this->visit, 'caries_dentine', 35, 'om', $this->dentist);
        $this->sheets()->addDiagnosis($this->visit, 'caries_dentine', 36, null, $this->dentist);
        $this->sheets()->addDiagnosis($this->visit, 'gingivitis', 21, null, $this->dentist);

        $rows = collect($this->sheets()->diagnosisRows($this->visit));
        $this->assertSame(['K02.1', 'K02.1', 'K05.1'], $rows->pluck('code')->all());
        $this->assertSame([35, 36, null], $rows->pluck('tooth')->all(), 'gingivitis is of the mouth');
        $this->assertSame('MO', $rows->first()['surfaces']);
        $this->assertTrue($rows->first()['primary']);

        $chart = app(DentalService::class)->chart($this->patient);
        $this->assertSame(['M' => 'caries', 'O' => 'caries'], $chart[35]['surfaces']);
        $this->assertSame('caries', $chart[36]['finding']);
        $this->assertSame('Caries, dentine (35 MO); Caries, dentine (36); Gingivitis', $this->visit->fresh()->diagnosis);

        try {
            $this->sheets()->addDiagnosis($this->visit, 'caries_dentine', 35, null, $this->dentist);
            $this->fail('the same diagnosis twice on one tooth');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('tooth 35', (string) collect($e->errors())->flatten()->first());
        }
        try {
            $this->sheets()->addDiagnosis($this->visit, 'abscess', null, null, $this->dentist);
            $this->fail('an abscess needs its tooth');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tooth', $e->errors());
        }

        $this->sheets()->removeDiagnosis($this->visit, $rows->last()['id'], $this->dentist);
        $this->assertSame('Caries, dentine (35 MO); Caries, dentine (36)', $this->visit->fresh()->diagnosis);
        $this->assertGreaterThan(3, VisitDiagnosis::count(), 'a removal is a row, nothing deleted');
    }

    public function test_the_diagnosis_panel_takes_the_tooth_picked_on_the_chart(): void
    {
        Livewire::actingAs($this->dentist)->test(DentalDiagnoses::class, ['visitId' => $this->visit->id])
            ->dispatch('dental-tooth-picked', tooth: 46)
            ->assertSet('tooth', 46)
            ->call('add', 'pulpitis_irreversible')
            ->assertHasNoErrors()
            ->assertSee('Irreversible pulpitis')
            ->set('q', 'malocclusion')
            ->call('addCode', 'K07.4')
            ->assertSee('K07.4');

        $this->assertSame(46, VisitDiagnosis::query()->where('code', 'K04.0')->value('tooth'));
    }

    // ── Investigations ─────────────────────────────────────────────────

    public function test_an_x_ray_at_the_chair_is_billed_filed_and_finished_without_a_radiology_order(): void
    {
        Storage::fake('local');
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Dental X-ray (periapical)', 'price' => '20000', 'is_active' => true]);

        $this->assertSame($service->id, $this->sheets()->serviceFor('periapical')?->id);
        $xray = $this->sheets()->recordXray($this->visit, 'periapical', [35, '36'], 'Deep caries close to the pulp on 36.', $service, UploadedFile::fake()->image('pa.jpg'), $this->dentist);

        $order = Order::query()->findOrFail($xray->order_id);
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame('Deep caries close to the pulp on 36.', $order->report);
        $this->assertSame('20000.00', (string) $order->items()->first()->line_total);
        $this->assertSame(1, $order->attachments()->count());
        $this->assertSame([35, 36], $xray->teeth);
        $this->assertSame('Periapical X-ray, 35 36', $xray->title());

        try {
            $this->sheets()->recordXray($this->visit, 'bitewing', [], 'x', null, null, $this->dentist);
            $this->fail('a bitewing names its teeth');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('teeth', $e->errors());
        }
        $this->sheets()->recordXray($this->visit, 'opg', [], 'Impacted 38 and 48.', null, null, $this->dentist);
        $this->assertSame(2, DentalXray::count());
    }

    public function test_the_x_ray_panel_records_one(): void
    {
        Livewire::actingAs($this->dentist)->test(DentalXrays::class, ['visitId' => $this->visit->id])
            ->call('open')
            ->set('kind', 'periapical')->set('teeth', '11 21')->set('finding', 'Periapical radiolucency on 11.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Periapical radiolucency on 11.');
    }

    // ── Treatment, instructions and review ─────────────────────────────

    public function test_an_extraction_done_today_suggests_its_instructions_and_a_review_in_a_week_and_books_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 08:00:00')); // a Monday
        $extraction = Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Tooth extraction', 'price' => '40000', 'is_active' => true]);
        $item = app(DentalService::class)->planItem($this->patient, $extraction, 48, null, null, null, $this->visit, $this->dentist);
        app(DentalService::class)->transition($item, DentalTreatmentStatus::Done, $this->dentist, $this->visit);

        $this->assertSame('Tooth extraction', $this->sheets()->doneToday($this->visit)[0]['procedure']);
        $this->assertSame(['after_extraction'], $this->sheets()->suggestedInstructions($this->visit));
        $review = $this->sheets()->reviewSuggestion($this->visit);
        $this->assertSame(7, $review['days']);
        $this->assertSame('2026-11-09', $review['on']);

        DoctorSchedule::factory()->create([
            'hospital_id' => $this->hospital->id, 'user_id' => $this->dentist->id, 'weekday' => Weekday::Monday,
            'start_time' => '09:00:00', 'end_time' => '12:00:00', 'slot_minutes' => 30,
        ]);
        $this->visit->forceFill(['doctor_user_id' => $this->dentist->id])->save();

        Livewire::actingAs($this->dentist)->test(DentalFollowUp::class, ['visitId' => $this->visit->id, 'part' => 'review'])
            ->call('startBooking')
            ->assertSet('date', '2026-11-09')
            ->call('book', '09:30')
            ->assertHasNoErrors();

        $booked = Appointment::query()->sole();
        $this->assertSame($this->visit->id, $booked->origin_visit_id);
        $this->assertSame('Review the socket', $booked->reason);

        try {
            $this->sheets()->bookReview($this->visit, '2026-11-09', '09:30', $this->dentist);
            $this->fail('a taken slot');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('review', $e->errors());
        }

        // The instructions start ticked from what was done.
        Livewire::actingAs($this->dentist)->test(CaseSheetSection::class, ['visitId' => $this->visit->id, 'section' => 'instructions'])
            ->call('edit')
            ->assertSet('form.templates', ['after_extraction'])
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('After an extraction', $this->sheets()->sheet($this->visit, $this->dentist)['instructions'][0]['label']);

        $this->actingAs($this->dentist)->get(route('admin.visits.dental-instructions', $this->visit))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        Carbon::setTestNow();
    }

    // ── The section component ──────────────────────────────────────────

    public function test_the_section_panel_writes_marks_normal_and_confirms(): void
    {
        Livewire::actingAs($this->dentist)->test(CaseSheetSection::class, ['visitId' => $this->visit->id, 'section' => 'complaint'])
            ->call('edit')
            ->assertSet('form.notes', 'Pain lower left') // from reception
            ->set('form.picks', ['toothache'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Toothache');

        Livewire::actingAs($this->dentist)->test(CaseSheetSection::class, ['visitId' => $this->visit->id, 'section' => 'extra_oral'])
            ->call('normal')
            ->assertSee('Normal');

        $this->sections()->savePatientHistory($this->patient, 'surgical', ['extractions' => 'yes', 'bleeding' => 'no'], null, $this->dentist);
        Livewire::actingAs($this->dentist)->test(CaseSheetSection::class, ['visitId' => $this->visit->id, 'section' => 'surgical'])
            ->assertSee('Still correct')
            ->call('confirm')
            ->assertSee('Checked on this visit');

        $nurse = $this->staff('nurse');
        Livewire::actingAs($nurse)->test(CaseSheetSection::class, ['visitId' => $this->visit->id, 'section' => 'complaint'])
            ->assertSee('Toothache')->assertDontSee('Edit')
            ->call('edit')->assertForbidden();
    }

    public function test_the_financial_history_is_for_those_who_see_money(): void
    {
        $nurse = $this->staff('nurse');
        Livewire::actingAs($nurse)->test(CaseSheetSection::class, ['visitId' => $this->visit->id, 'section' => 'financial'])->assertForbidden();
        $this->assertNull($this->sheets()->sheet($this->visit, $nurse)['financial']);

        // A dentist sees this patient's money on the visit they are treating, not the clinic's.
        $other = $this->staff('doctor');
        Livewire::actingAs($other)->test(CaseSheetSection::class, ['visitId' => $this->visit->id, 'section' => 'financial'])->assertForbidden();
        $this->visit->forceFill(['doctor_user_id' => $this->dentist->id])->save();

        Livewire::actingAs($this->dentist)->test(CaseSheetSection::class, ['visitId' => $this->visit->id, 'section' => 'financial'])
            ->assertSee('Owes from before')
            ->call('edit')->set('form.arrangement', 'Employer pays half')->call('save')
            ->assertSee('Employer pays half');
    }

    // ── The printed sheet (and item F) ─────────────────────────────────

    public function test_the_visit_report_prints_the_case_sheet_headings_the_chart_and_a_plain_stage(): void
    {
        $this->sections()->save($this->visit, 'complaint', ['picks' => ['toothache'], 'duration' => '5 days'], $this->dentist);
        $this->sections()->markNormal($this->visit, 'extra_oral', $this->dentist);
        $this->sheets()->addDiagnosis($this->visit, 'caries_dentine', 35, 'MO', $this->dentist);
        $this->sheets()->recordXray($this->visit, 'periapical', [35], 'Caries into dentine.', null, null, $this->dentist);
        $this->visit->forceFill(['stage' => \App\Enums\VisitStage::Payment, 'status' => \App\Enums\VisitStatus::Ongoing])->save();

        $html = view('pdf.visit-report', ['report' => app(VisitReportService::class)->assemble($this->visit->fresh()), 'hospital' => $this->hospital])->render();

        foreach (['History', 'Presenting complaint', 'Medical history', 'Examination', 'Extra-oral', 'Hard tissues', 'Investigations', 'Diagnosis', 'Treatment done today', 'Plan and follow-up', 'K02.1', 'C MO', 'Caries into dentine.', 'At the cashier'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringNotContainsString('Status Payment', $html);

        $this->actingAs($this->dentist)->get(route('admin.visits.report', $this->visit))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_general_visit_report_prints_its_coded_diagnoses(): void
    {
        $general = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'medical_clinic']]]);
        app(CurrentHospital::class)->set($general->id);
        $visit = Visit::factory()->create(['hospital_id' => $general->id]);
        app(\App\Services\DiagnosisService::class)->add($visit, 'K02.9', true);

        $html = view('pdf.visit-report', ['report' => app(VisitReportService::class)->assemble($visit->fresh()), 'hospital' => $general])->render();
        $this->assertStringContainsString('K02.9', $html);
        $this->assertStringContainsString('Waiting to be seen', $html);
        $this->assertStringNotContainsString('Hard tissues', $html);
    }

    public function test_a_new_visit_takes_the_only_clinical_department(): void
    {
        $visits = app(VisitService::class);
        $dental = Department::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Dental', 'is_active' => true]);
        Department::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Laboratory', 'is_active' => true]);
        Department::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Accounts', 'is_active' => true]);

        $this->assertSame($dental->id, $visits->open(['patient_id' => $this->patient->id])->department_id);

        $chosen = Department::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Orthodontics', 'is_active' => true]);
        $this->assertSame($chosen->id, $visits->open(['patient_id' => $this->patient->id, 'department_id' => $chosen->id])->department_id);
        $this->assertNull($visits->open(['patient_id' => $this->patient->id])->department_id, 'two clinical departments: left to whoever routes the patient');
    }

    // ── The app ────────────────────────────────────────────────────────

    public function test_the_app_reads_and_writes_the_case_sheet(): void
    {
        Sanctum::actingAs($this->dentist);
        $url = "/api/v1/visits/{$this->visit->uuid}/dental-case-sheet";

        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.applies', true)
            ->assertJsonStructure(['data' => ['sections' => [['key', 'label', 'group', 'scope', 'data', 'lines', 'version']], 'definitions', 'diagnosis_list', 'xray_kinds', 'chart', 'xrays', 'labs', 'diagnoses', 'done_today', 'plan', 'instructions', 'review', 'reviews_booked', 'financial', 'instructions_url']]);

        $this->putJson("$url/sections/complaint", ['data' => ['picks' => ['swelling']], 'base_version' => 0], ['Idempotency-Key' => 'cs-1'])->assertOk();
        $this->putJson("$url/sections/complaint", ['data' => ['picks' => ['swelling']], 'base_version' => 0], ['Idempotency-Key' => 'cs-1'])->assertOk();
        $this->assertSame(1, CaseSheetEntry::count(), 'a replay saves once');
        $this->putJson("$url/sections/complaint", ['data' => ['picks' => ['cleaning']], 'base_version' => 0])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $this->putJson("$url/sections/medical", ['data' => ['red_flags' => ['heart']]])->assertOk();
        $this->assertSame(['heart'], $this->patient->fresh()->medical_flags);
        $this->postJson("$url/history/surgical/confirm")->assertOk();
        $this->postJson("$url/sections/intra_oral/normal")->assertOk();
        $this->putJson("$url/sections/nonsense", ['data' => []])->assertNotFound();

        $made = $this->postJson("$url/diagnoses", ['condition' => 'retained_root', 'tooth' => 46])->assertCreated();
        $this->assertSame('to_extract', collect($made->json('data.chart'))->firstWhere('tooth', 46)['finding']);
        $this->postJson("$url/diagnoses", ['code' => 'K07.4'])->assertCreated();
        $this->deleteJson("$url/diagnoses/".$made->json('data.diagnosis_uuid'))->assertOk();
        $this->assertCount(1, $this->sheets()->diagnosisRows($this->visit));

        Storage::fake('local');
        $x = $this->post("$url/xrays", ['kind' => 'periapical', 'teeth' => [46], 'finding' => 'Retained root, no abscess.', 'image' => UploadedFile::fake()->image('x.png')], ['Accept' => 'application/json'])->assertCreated();
        $this->get('/api/v1/dental/xrays/'.$x->json('data.xray_uuid').'/image')->assertOk();
        $this->get("$url/instructions")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_app_is_refused_for_a_role_without_the_chart_and_for_another_hospital(): void
    {
        $url = "/api/v1/visits/{$this->visit->uuid}/dental-case-sheet";
        Sanctum::actingAs($this->staff('receptionist'));
        $this->getJson($url)->assertForbidden()->assertJsonPath('code', 'forbidden');

        $nurse = $this->staff('nurse');
        Sanctum::actingAs($nurse);
        $this->getJson($url)->assertOk()->assertJsonPath('data.financial', null);
        $this->putJson("$url/sections/complaint", ['data' => []])->assertForbidden();
        $this->postJson("$url/diagnoses", ['condition' => 'gingivitis'])->assertForbidden();

        $other = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'dental_clinic']]]);
        $stranger = $this->staff('doctor', $other);
        app(CurrentHospital::class)->set($other->id);
        Sanctum::actingAs($stranger);
        $this->getJson($url)->assertNotFound();
        $this->putJson("$url/sections/complaint", ['data' => ['picks' => ['swelling']]])->assertNotFound();
        $this->assertSame(0, CaseSheetEntry::query()->withoutGlobalScopes()->count());
    }

    public function test_review_findings_stay_fixed(): void
    {
        // "Accident and Emergency" and "Orthopaedics" are not dentistry.
        $general = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'general_hospital', 'packs' => ['dental']]]]);
        app(CurrentHospital::class)->set($general->id);
        foreach (['Accident and Emergency', 'Orthopaedics', 'Student health'] as $name) {
            $dept = Department::factory()->create(['hospital_id' => $general->id, 'name' => $name, 'is_active' => true]);
            $this->assertFalse(DentalCaseSheetService::appliesTo(Visit::factory()->create(['hospital_id' => $general->id, 'department_id' => $dept->id]), $general), $name);
        }
        $ortho = Department::factory()->create(['hospital_id' => $general->id, 'name' => 'Orthodontics', 'is_active' => true]);
        $this->assertTrue(DentalCaseSheetService::appliesTo(Visit::factory()->create(['hospital_id' => $general->id, 'department_id' => $ortho->id]), $general));
        app(CurrentHospital::class)->set($this->hospital->id);

        // The printed report keeps the case sheet from a role the screen keeps it from.
        $this->sections()->savePatientHistory($this->patient, 'medical', ['medicines' => 'Warfarin 5 mg'], $this->visit, $this->dentist);
        $reports = app(VisitReportService::class);
        $this->assertNotNull($reports->assemble($this->visit->fresh(), $this->dentist)['dental']);
        $this->assertNull($reports->assemble($this->visit->fresh(), $this->staff('receptionist'))['dental']);

        // Nothing more is charged on an invoiced visit: said in words, not a crash.
        $service = Service::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Dental X-ray (periapical)', 'price' => '20000', 'is_active' => true]);
        $this->sheets()->recordXray($this->visit->fresh(), 'opg', [], 'Impacted 38.', $service, null, $this->dentist);
        app(\App\Services\BillingService::class)->generateInvoice($this->visit->fresh(), '0.00', $this->dentist->id);
        try {
            $this->sheets()->recordXray($this->visit->fresh(), 'periapical', [11], 'Clear.', $service, null, $this->dentist);
            $this->fail('charged on an invoiced visit');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('service', $e->errors());
        }
        $this->sheets()->recordXray($this->visit->fresh(), 'periapical', [11], 'Clear.', null, null, $this->dentist);
        $this->assertSame(2, DentalXray::count(), 'recorded, not charged');
    }

    public function test_the_demo_gives_adult_dental_visits_full_case_sheets_and_runs_twice_safely(): void
    {
        $demo = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'general_hospital', 'packs' => ['dental']]]]);
        app(CurrentHospital::class)->set($demo->id);
        $this->staff('doctor', $demo);
        $dental = Department::factory()->create(['hospital_id' => $demo->id, 'name' => 'Dental', 'is_active' => true]);
        $man = Patient::factory()->create(['hospital_id' => $demo->id, 'sex' => PatientSex::Male, 'dob' => now()->subYears(40)->toDateString()]);
        $child = Patient::factory()->create(['hospital_id' => $demo->id, 'dob' => now()->subYears(7)->toDateString()]);
        $adult = Visit::factory()->create(['hospital_id' => $demo->id, 'patient_id' => $man->id, 'department_id' => $dental->id]);
        $young = Visit::factory()->create(['hospital_id' => $demo->id, 'patient_id' => $child->id, 'department_id' => $dental->id]);

        \Database\Seeders\DemoScenarios\DentalCaseSheet::run($demo);
        $rows = CaseSheetEntry::count();
        \Database\Seeders\DemoScenarios\DentalCaseSheet::run($demo);

        app(CurrentHospital::class)->set($demo->id);
        $this->assertSame($rows, CaseSheetEntry::count(), 'idempotent');
        $this->assertSame(6, CaseSheetEntry::query()->where('visit_id', $adult->id)->count());
        $this->assertSame(0, CaseSheetEntry::query()->where('visit_id', $young->id)->count(), 'permanent-teeth stories only on adults');
        $this->assertSame(['hypertension'], $man->fresh()->medical_flags, 'no pregnancy question for a man');
        $this->assertSame(46, VisitDiagnosis::query()->where('visit_id', $adult->id)->where('code', 'K04.7')->value('tooth'));
        $this->assertSame(1, DentalXray::query()->where('visit_id', $adult->id)->count());
    }

    public function test_another_hospitals_visit_cannot_be_opened_in_a_panel(): void
    {
        $other = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'dental_clinic']]]);
        $theirs = Visit::factory()->create(['hospital_id' => $other->id]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($this->dentist)->test(CaseSheetSection::class, ['visitId' => $theirs->id, 'section' => 'complaint']);
    }
}
