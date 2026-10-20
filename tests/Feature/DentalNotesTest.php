<?php

namespace Tests\Feature;

use App\Livewire\Settings\VisitFormSettings;
use App\Livewire\Visits\Index as VisitsIndex;
use App\Livewire\Visits\Panels\DentalNotes;
use App\Livewire\Visits\Panels\Vitals;
use App\Models\AuditEntry;
use App\Models\CaseSheetEntry;
use App\Models\DentalNote;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\CaseSheetService;
use App\Services\DentalNotesService;
use App\Services\VisitReportService;
use App\Support\CurrentHospital;
use App\Support\Practice\VisitForm;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A dental clinic's second round (docs/dental.md, "Simple notes"; docs/practice.md,
 * "The visit form"): blood pressure as the only vital, no reason box, the
 * presenting complaint with History and Oral examination as plain text on the
 * new visit form and the visit page, the printed report, the app, and a
 * general hospital left as it was.
 */
class DentalNotesTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $dentist;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Livewire::withoutLazyLoading();

        $this->hospital = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'dental_clinic']]]);
        app(CurrentHospital::class)->set($this->hospital->id);
        // A dentist who also opens visits, as in a small clinic.
        $this->dentist = $this->staff('hospital_admin');
        $this->patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
    }

    private function staff(string $role, ?Hospital $hospital = null): User
    {
        $user = User::factory()->create(['hospital_id' => ($hospital ?? $this->hospital)->id, 'role' => $role]);
        $user->syncSpatieRole();

        return $user;
    }

    private function visit(array $attributes = []): Visit
    {
        return Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $this->patient->id] + $attributes + ['reason' => null, 'complaints' => null, 'diagnosis' => null]);
    }

    private function notes(): DentalNotesService
    {
        return app(DentalNotesService::class);
    }

    private function general(): Hospital
    {
        $general = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'general_hospital']]]);
        app(CurrentHospital::class)->set($general->id);

        return $general;
    }

    // ── The setting ────────────────────────────────────────────────────

    public function test_a_dental_clinic_records_blood_pressure_and_writes_simple_notes_and_a_general_hospital_is_as_it_was(): void
    {
        $form = VisitForm::of($this->hospital);
        $this->assertSame(['blood_pressure'], $form->vitals);
        $this->assertSame('Blood pressure', $form->vitalsTitle());
        $this->assertSame(VisitForm::SIMPLE, $form->dentalNotes);
        $this->assertFalse($form->asksReason());
        $this->assertFalse($form->suggests());
        $this->assertTrue($form->dentalVisitForm());

        $general = VisitForm::of($this->general());
        $this->assertSame(array_keys(VisitForm::VITALS), $general->vitals);
        $this->assertSame('Vital signs', $general->vitalsTitle());
        $this->assertSame(VisitForm::DETAILED, $general->dentalNotes);
        $this->assertTrue($general->asksReason());
        $this->assertFalse($general->dentalVisitForm());
    }

    public function test_the_reason_is_the_first_words_of_the_complaint(): void
    {
        $this->assertSame('Pain in the lower left back tooth', VisitForm::reasonFrom("Pain in the lower left back tooth.\nStarted three days ago."));
        $this->assertSame('Swelling of the right cheek since Monday and it is', VisitForm::reasonFrom('Swelling of the right cheek since Monday and it is getting worse every day'));
        $this->assertNull(VisitForm::reasonFrom('  '));
    }

    public function test_settings_choose_the_vitals_and_the_dental_notes_and_only_an_admin_may(): void
    {
        $this->actingAs($this->dentist);
        Livewire::test(VisitFormSettings::class)
            ->assertSee('Vital signs recorded')->assertSee('Simple notes')->assertSee('Detailed case sheet')
            ->set('vitals', ['blood_pressure', 'temperature'])
            ->set('dentalNotes', VisitForm::DETAILED)
            ->call('save')->assertHasNoErrors();

        $form = VisitForm::of($this->hospital->fresh());
        $this->assertSame(['blood_pressure', 'temperature'], $form->vitals);
        $this->assertSame(VisitForm::DETAILED, $form->dentalNotes);

        Livewire::test(VisitFormSettings::class)->call('useDefaults');
        $this->assertSame(['blood_pressure'], VisitForm::of($this->hospital->fresh())->vitals);

        $this->actingAs($this->staff('doctor'));
        Livewire::test(VisitFormSettings::class)->assertForbidden();
        $this->get(route('admin.settings.practice'))->assertForbidden();
    }

    public function test_the_app_reads_and_changes_the_visit_form_setting(): void
    {
        Sanctum::actingAs($this->dentist);
        $this->getJson('/api/v1/admin/settings/practice')->assertOk()
            ->assertJsonPath('data.visit_form.vitals', ['blood_pressure'])
            ->assertJsonPath('data.visit_form.dental_notes', 'simple');

        $this->putJson('/api/v1/admin/settings/practice/visit-form', ['vitals' => ['blood_pressure', 'pulse']])->assertOk()
            ->assertJsonPath('data.visit_form.vitals', ['blood_pressure', 'pulse'])
            ->assertJsonPath('data.visit_form.dental_notes', 'simple');
        $this->putJson('/api/v1/admin/settings/practice/visit-form', ['vitals' => ['heart']])->assertStatus(422);

        Sanctum::actingAs($this->staff('doctor'));
        $this->putJson('/api/v1/admin/settings/practice/visit-form', ['vitals' => []])->assertForbidden();
    }

    // ── The new visit form ─────────────────────────────────────────────

    public function test_a_dental_clinics_new_visit_form_has_statements_for_headings_blood_pressure_history_and_oral_examination(): void
    {
        $this->actingAs($this->dentist);
        Livewire::test(VisitsIndex::class)->call('create')
            ->assertSee('Patient')->assertSee('Blood pressure')->assertSee('BP mmHg')
            ->assertDontSee('Temp °C')->assertDontSee('Pulse bpm')->assertDontSee('SpO₂ %')->assertDontSee('Weight kg')->assertDontSee('BMI')
            ->assertDontSee('Reason for visit')
            ->assertSee('Presenting complaint')
            ->assertSee('History')->assertSee('Medical history')->assertSee('Family history')->assertSee('Dental history')->assertSee('Surgical history')
            ->assertSee('Oral examination')->assertSee('Hard tissues (teeth)')->assertSee('Soft tissues (gums)')->assertSee('X-ray')
            ->assertSee('Visit status')->assertSee('Waiting')->assertSee('Being seen now')
            ->assertDontSee('Who is it?')->assertDontSee('How are they?')->assertDontSee('Why have they come?')->assertDontSee('Is anybody seeing them yet?')
            ->assertDontSeeHtml('tb-pill');
    }

    public function test_a_general_hospitals_form_keeps_every_vital_the_reason_and_its_suggestions(): void
    {
        $general = $this->general();
        $this->actingAs($this->staff('hospital_admin', $general));
        Livewire::test(VisitsIndex::class)->call('create')
            ->assertSee('Vital signs')->assertSee('Temp °C')->assertSee('Weight kg')->assertSee('BMI')
            ->assertSee('Reason for visit')->assertSee('Visit status')
            ->assertDontSee('Oral examination')->assertDontSee('Is anybody seeing them yet?')
            ->assertSeeHtml('tb-pill');
    }

    public function test_opening_a_dental_visit_saves_the_notes_fills_the_reason_and_the_diagnosis(): void
    {
        $this->actingAs($this->dentist);
        Livewire::test(VisitsIndex::class)->call('create')
            ->call('picked', 'patient_id', $this->patient->id)
            ->set('blood_pressure', '130/85')
            ->set('complaints', "Pain in the lower left back tooth when chewing.\nThree days.")
            ->set('dental_notes.medical_history', 'Hypertension, on amlodipine')
            ->set('dental_notes.family_history', 'Diabetes in the mother')
            ->set('dental_notes.dental_history', 'Last visit two years ago')
            ->set('dental_notes.hard_tissues', 'Deep caries 36 DO')
            ->set('dental_notes.diagnosis', 'Irreversible pulpitis 36')
            ->call('save')->assertHasNoErrors();

        $visit = Visit::query()->latest('id')->firstOrFail();
        $this->assertSame('Pain in the lower left back tooth when chewing', $visit->reason);
        $this->assertSame('130/85', $visit->blood_pressure);
        $this->assertSame('Irreversible pulpitis 36', $visit->diagnosis);
        $note = DentalNote::query()->where('visit_id', $visit->id)->firstOrFail();
        $this->assertSame(1, $note->version);
        $this->assertSame('Hypertension, on amlodipine', $note->data['medical_history']);
        $this->assertArrayNotHasKey('soft_tissues', $note->data);
        // Encrypted at rest, and audited without its words.
        $this->assertStringNotContainsString('amlodipine', (string) \DB::table('dental_notes')->value('data'));
        $this->assertTrue(AuditEntry::query()->where('log_name', 'dental_note')->exists());
    }

    public function test_medical_family_and_surgical_history_carry_forward_and_dental_history_does_not(): void
    {
        $first = $this->visit();
        $this->notes()->save($first, ['medical_history' => 'Asthma', 'family_history' => 'None known', 'surgical_history' => 'Appendix out 2015', 'dental_history' => 'Braces as a child', 'diagnosis' => 'Gingivitis'], $this->dentist);

        $this->actingAs($this->dentist);
        $form = Livewire::test(VisitsIndex::class)->call('create')->call('picked', 'patient_id', $this->patient->id)
            ->assertSet('dental_notes.medical_history', 'Asthma')
            ->assertSet('dental_notes.surgical_history', 'Appendix out 2015')
            ->assertSee('Filled from the last visit');
        $this->assertNull($form->get('dental_notes.dental_history'));
        $this->assertNull($form->get('dental_notes.diagnosis'));

        // Another patient takes the carried history out again.
        $other = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $form->call('picked', 'patient_id', $other->id)->assertSet('dental_notes.medical_history', null);

        // On the visit page, a visit with no notes yet starts from them too.
        $second = $this->visit();
        $carried = $this->notes()->forVisit($second);
        $this->assertSame(['medical_history', 'family_history', 'surgical_history'], $carried['carried']);
        $this->assertSame(0, $carried['version']);
    }

    public function test_the_new_patient_tab_never_keeps_the_last_patients_history(): void
    {
        $this->notes()->save($this->visit(), ['medical_history' => 'HIV positive, on ART'], $this->dentist);

        $this->actingAs($this->dentist);
        Livewire::test(VisitsIndex::class)->call('create')
            ->call('picked', 'patient_id', $this->patient->id)
            ->assertSet('dental_notes.medical_history', 'HIV positive, on ART')
            ->call('setTab', 'intake')
            ->assertSet('dental_notes.medical_history', null)
            ->assertSet('carried', [])
            ->set('first_name', 'Grace')->set('last_name', 'Newcomer')
            ->set('dental_notes.dental_history', 'First visit')
            ->call('save')->assertHasNoErrors();

        $note = DentalNote::query()->latest('id')->firstOrFail();
        $this->assertNotSame($this->patient->id, $note->patient_id);
        $this->assertArrayNotHasKey('medical_history', $note->data);

        // Coming from the patient's record carries their history in too.
        $this->get(route('admin.visits.index', ['for' => $this->patient->uuid]))->assertOk()->assertSee('HIV positive, on ART');
    }

    public function test_a_history_taken_on_the_detailed_case_sheet_carries_into_simple_notes(): void
    {
        $earlier = $this->visit();
        app(CaseSheetService::class)->savePatientHistory($this->patient, 'medical', ['medicines' => 'Warfarin 5 mg'], $earlier, $this->dentist);

        $carried = $this->notes()->carriedForward($this->patient->id);
        $this->assertSame('Current medicines: Warfarin 5 mg', $carried['medical_history']);
        $this->assertFalse(DentalNotesService::appliesTo($earlier, $this->hospital), 'the history was asked on it, so it stays a case sheet');
    }

    public function test_an_unrelated_edit_keeps_a_diagnosis_written_elsewhere_and_a_save_id_belongs_to_one_visit(): void
    {
        $visit = $this->visit();
        $this->notes()->save($visit, ['diagnosis' => 'Caries 36'], $this->dentist, null, '11111111-1111-4111-8111-111111111111');
        app(\App\Services\VisitService::class)->updateClinical($visit->fresh(), ['diagnosis' => 'Caries 36 and 37']);

        $this->notes()->save($visit->fresh(), ['diagnosis' => 'Caries 36', 'xray' => 'Bitewing: 36 and 37 into dentine'], $this->dentist);
        $this->assertSame('Caries 36 and 37', $visit->fresh()->diagnosis);

        $this->expectException(ValidationException::class);
        $this->notes()->save($this->visit(), ['xray' => 'x'], $this->dentist, null, '11111111-1111-4111-8111-111111111111');
    }

    public function test_the_desk_does_not_see_or_write_the_clinical_notes_on_the_form(): void
    {
        $this->actingAs($this->staff('receptionist'));
        Livewire::test(VisitsIndex::class)->call('create')
            ->assertSee('Presenting complaint')->assertDontSee('Oral examination')->assertDontSee('Medical history')
            ->call('picked', 'patient_id', $this->patient->id)
            ->set('complaints', 'Broken front tooth')
            ->set('dental_notes.diagnosis', 'Smuggled in')
            ->call('save')->assertHasNoErrors();

        $visit = Visit::query()->latest('id')->firstOrFail();
        $this->assertSame('Broken front tooth', $visit->reason);
        $this->assertNull($visit->diagnosis);
        $this->assertSame(0, DentalNote::query()->count());
    }

    // ── The visit page ─────────────────────────────────────────────────

    public function test_the_visit_page_shows_the_dental_notes_in_place_of_the_case_sheet_and_the_blood_pressure_alone(): void
    {
        $visit = $this->visit(['complaints' => 'Toothache']);
        $this->actingAs($this->dentist)->get(route('admin.visits.show', $visit))->assertOk()
            ->assertSee('Dental notes')->assertSee('Presenting complaint')->assertSee('Oral examination')
            ->assertSee('Treatment done today')->assertSee('Blood pressure')
            ->assertDontSee('Dental consultation');

        $this->actingAs($this->staff('receptionist'))->get(route('admin.visits.show', $visit))->assertOk()
            ->assertDontSee('Oral examination');
    }

    public function test_the_clinician_edits_the_notes_and_each_save_is_a_version(): void
    {
        $visit = $this->visit(['complaints' => 'Toothache']);
        $this->actingAs($this->dentist);

        $panel = Livewire::test(DentalNotes::class, ['visitId' => $visit->id])
            ->call('openForm')
            ->set('complaints', 'Toothache upper right')
            ->set('form.soft_tissues', 'Swollen gum around 16')
            ->set('form.diagnosis', 'Periodontal abscess 16')
            ->call('save')->assertHasNoErrors()
            ->assertSee('Swollen gum around 16');

        $visit->refresh();
        $this->assertSame('Toothache upper right', $visit->complaints);
        $this->assertSame('Toothache upper right', $visit->reason);
        $this->assertSame('Periodontal abscess 16', $visit->diagnosis);
        $this->assertSame($this->dentist->id, $visit->doctor_user_id);

        $panel->call('openForm')->set('form.diagnosis', '')->call('save')->assertHasNoErrors();
        $this->assertSame(2, $this->notes()->current($visit)->version);
        $this->assertNull($visit->fresh()->diagnosis, 'the diagnosis was the notes\' own');

        // Nothing changed: no new version.
        $panel->call('openForm')->call('save');
        $this->assertSame(2, DentalNote::query()->where('visit_id', $visit->id)->count());

        // A save against an older version is refused, in words.
        $this->expectException(ValidationException::class);
        $this->notes()->save($visit, ['xray' => 'Bone loss'], $this->dentist, 1);
    }

    public function test_a_nurse_reads_the_notes_but_cannot_write_them(): void
    {
        $visit = $this->visit();
        $this->actingAs($this->staff('nurse'));
        Livewire::test(DentalNotes::class, ['visitId' => $visit->id])->assertDontSee('Write notes')->call('openForm')->assertForbidden();
    }

    public function test_the_vitals_panel_asks_only_for_blood_pressure_and_keeps_what_was_measured_before(): void
    {
        $visit = $this->visit(['weight' => '70.0', 'height' => '170.0', 'bmi' => 24.22, 'vitals_recorded_at' => now()]);
        $this->actingAs($this->dentist);

        Livewire::test(Vitals::class, ['visitId' => $visit->id])
            ->assertSee('Blood pressure')
            ->call('openForm')
            ->assertSee('Weight kg')            // taken on this visit, so it can be corrected
            ->assertDontSee('Temp °C')
            ->set('blood_pressure', '120/80')
            ->call('save')->assertHasNoErrors();

        $visit->refresh();
        $this->assertSame('120/80', $visit->blood_pressure);
        $this->assertEquals(24.22, (float) $visit->bmi, 'the BMI from the earlier reading stays');

        $fresh = $this->visit();
        Livewire::test(Vitals::class, ['visitId' => $fresh->id])->call('openForm')->assertDontSee('Weight kg')->assertSee('No blood pressure recorded', false);
    }

    // ── What was there before ──────────────────────────────────────────

    public function test_a_visit_written_before_keeps_its_case_sheet_and_its_reason(): void
    {
        $old = $this->visit(['reason' => 'Check-up', 'complaints' => null]);
        app(CaseSheetService::class)->save($old, 'complaint', ['picks' => ['checkup'], 'notes' => 'Routine check'], $this->dentist);
        $this->assertTrue(CaseSheetEntry::query()->where('visit_id', $old->id)->exists());

        $this->assertFalse(DentalNotesService::appliesTo($old, $this->hospital), 'a case sheet already started stays a case sheet');
        $this->actingAs($this->dentist)->get(route('admin.visits.show', $old))->assertOk()->assertSee('Dental consultation');
        $this->assertNotNull(app(VisitReportService::class)->assemble($old->fresh(), $this->dentist)['dental']);

        // A visit with only its reason prints the reason as the complaint.
        $reasonOnly = $this->visit(['reason' => 'Loose filling']);
        $printed = app(VisitReportService::class)->assemble($reasonOnly, $this->dentist)['dentalNotes'];
        $this->assertSame('Loose filling', $printed['complaint']);
        $this->actingAs($this->dentist)->get(route('admin.visits.report', $reasonOnly))->assertOk();

        // A hospital that later chooses the detailed sheet keeps notes already written as notes.
        $written = $this->visit();
        $this->notes()->save($written, ['xray' => 'No periapical lesion'], $this->dentist);
        $settings = $this->hospital->settings;
        $settings['clinical'] = ['dental_notes' => 'detailed'];
        $this->hospital->forceFill(['settings' => $settings])->save();
        $this->assertTrue(DentalNotesService::appliesTo($written, $this->hospital->fresh()));
        $this->assertFalse(DentalNotesService::appliesTo($this->visit(), $this->hospital->fresh()));
    }

    // ── The printed report ─────────────────────────────────────────────

    public function test_the_report_prints_the_complaint_history_and_oral_examination_and_drops_empty_headings(): void
    {
        $visit = $this->visit(['complaints' => 'Toothache lower left', 'blood_pressure' => '125/80', 'vitals_recorded_at' => now()]);
        $this->notes()->save($visit, ['medical_history' => 'Hypertension', 'hard_tissues' => 'Caries 36', 'diagnosis' => 'Caries 36'], $this->dentist);

        $report = app(VisitReportService::class)->assemble($visit->fresh(), $this->dentist);
        $this->assertNull($report['dental']);
        $html = view('pdf.visit-report', ['report' => $report, 'hospital' => $this->hospital])->render();

        foreach (['Presenting complaint', 'Toothache lower left', 'History', 'Medical history', 'Hypertension', 'Oral examination', 'Hard tissues (teeth)', 'Caries 36', 'Blood pressure'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        foreach (['Family history', 'Soft tissues (gums)', 'Reason for visit', 'Treatment done', 'Dental chart'] as $text) {
            $this->assertStringNotContainsString($text, $html, $text);
        }

        // The desk prints no clinical notes.
        $this->assertNull(app(VisitReportService::class)->assemble($visit->fresh(), $this->staff('receptionist'))['dentalNotes']);
    }

    // ── The app ────────────────────────────────────────────────────────

    public function test_the_app_reads_and_writes_the_notes(): void
    {
        $first = $this->visit();
        $this->notes()->save($first, ['family_history' => 'Diabetes'], $this->dentist);
        $visit = $this->visit(['complaints' => 'Bleeding gums']);
        $url = "/api/v1/visits/{$visit->uuid}/dental-notes";

        Sanctum::actingAs($this->dentist);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.applies', true)->assertJsonPath('data.mode', 'simple')
            ->assertJsonPath('data.notes.family_history', 'Diabetes')
            ->assertJsonPath('data.carried', ['family_history'])
            ->assertJsonPath('data.version', 0)
            ->assertJsonPath('data.fields.4.label', 'Hard tissues (teeth)');

        $body = ['notes' => ['family_history' => 'Diabetes', 'soft_tissues' => 'Generalised gingivitis', 'diagnosis' => 'Gingivitis'], 'complaints' => 'Bleeding gums when brushing', 'base_version' => 0];
        $this->putJson($url, $body, ['Idempotency-Key' => 'dn-1'])->assertOk()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.diagnosis', 'Gingivitis')
            ->assertJsonPath('data.reason', 'Bleeding gums when brushing');
        $this->putJson($url, $body, ['Idempotency-Key' => 'dn-1'])->assertOk()->assertJsonPath('data.version', 1);
        $this->assertSame(1, DentalNote::query()->where('visit_id', $visit->id)->count());
        $this->putJson($url, $body + ['uuid' => null], ['Idempotency-Key' => 'dn-2'])->assertStatus(422)->assertJsonValidationErrors('notes');

        $this->getJson("/api/v1/visits/{$visit->uuid}")->assertOk()->assertJsonPath('data.vital_signs', ['blood_pressure']);
        $this->getJson("/api/v1/visits/{$visit->uuid}/dental-case-sheet")->assertOk()->assertJsonPath('data.mode', 'simple');
        $this->getJson('/api/v1/visits/phrases')->assertOk()
            ->assertJsonPath('data.reason', [])->assertJsonPath('data.form.vitals_title', 'Blood pressure')
            ->assertJsonPath('data.form.asks_reason', false);

        // A new visit through the app: the reason from the complaint, the notes saved.
        $this->postJson('/api/v1/visits', [
            'patient_id' => $this->patient->id, 'complaints' => 'Swelling of the right cheek', 'blood_pressure' => '118/76',
            'dental_notes' => ['medical_history' => 'Asthma', 'xray' => 'Periapical lesion 46'],
        ], ['Idempotency-Key' => 'dn-open'])->assertCreated()->assertJsonPath('data.reason', 'Swelling of the right cheek');
        $opened = Visit::query()->latest('id')->firstOrFail();
        $this->assertSame('Periapical lesion 46', $this->notes()->current($opened)->data['xray']);

        // A nurse reads, cannot write; another hospital cannot see it.
        Sanctum::actingAs($this->staff('nurse'));
        $this->getJson($url)->assertOk();
        $this->putJson($url, ['notes' => []], ['Idempotency-Key' => 'dn-3'])->assertForbidden();

        $other = Hospital::factory()->create(['settings' => ['practice' => ['sector' => 'dental_clinic']]]);
        $stranger = $this->staff('hospital_admin', $other);
        app(CurrentHospital::class)->set($other->id);
        Sanctum::actingAs($stranger);
        $this->getJson($url)->assertNotFound();
        $this->putJson($url, ['notes' => ['xray' => 'x']], ['Idempotency-Key' => 'dn-4'])->assertNotFound();
    }

    public function test_a_general_hospital_visit_is_opened_and_shown_as_before(): void
    {
        $general = $this->general();
        $admin = $this->staff('hospital_admin', $general);
        $patient = Patient::factory()->create(['hospital_id' => $general->id]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/visits', ['patient_id' => $patient->id, 'complaints' => 'Fever for three days'], ['Idempotency-Key' => 'gen-1'])
            ->assertCreated()->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.vital_signs', array_keys(VisitForm::VITALS));

        $visit = Visit::query()->latest('id')->firstOrFail();
        $this->actingAs($admin)->get(route('admin.visits.show', $visit))->assertOk()->assertSee('Clinical')->assertDontSee('Dental notes');
    }
}
