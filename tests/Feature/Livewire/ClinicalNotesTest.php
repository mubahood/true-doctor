<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Visits\Panels\Clinical;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\Visit;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The clinical notes dialog.
 *
 * Two ideas. First, everything a clinician needs while writing is IN FRONT of
 * them — allergies loudest, because prescribing against one is the mistake
 * this screen sits closest to. None of it is new data; it was on the record
 * already and nobody showed it at the moment it mattered. Second, the phrases
 * are a typing aid and not a menu: they land as editable text, and a second
 * click appends rather than replaces.
 */
class ClinicalNotesTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private Patient $patient;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        $this->doctor->syncSpatieRole();
        $this->actingAs($this->doctor);

        $this->patient = Patient::factory()->create([
            'hospital_id' => $this->hospital->id,
            'allergies' => ['Penicillin', 'Sulfa drugs'],
            'chronic_conditions' => ['Type 2 diabetes'],
        ]);
    }

    private function visit(array $attrs = []): Visit
    {
        return app(VisitService::class)->open(['patient_id' => $this->patient->id] + $attrs);
    }

    private function panel(Visit $visit): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Clinical::class, ['visitId' => $visit->id, 'lazy' => false]);
    }

    // ── The doctor is searched, not listed ───────────────────────────────

    /**
     * The picker replaces a select that rendered every doctor in the hospital
     * on every render of this panel.
     */
    public function test_the_doctor_is_a_searchable_picker_not_a_whole_table_select(): void
    {
        $html = $this->panel($this->visit())->call('openForm')->html();

        $this->assertStringContainsString('ui.select-search', $html);
        $this->assertStringNotContainsString('— unassigned —', $html, 'the whole-table select survived');
        $this->assertStringNotContainsString('id="cl-doctor"', $html);
    }

    public function test_the_picker_reports_its_pick_and_it_saves(): void
    {
        $visit = $this->visit();

        $this->panel($visit)
            ->call('openForm')
            ->call('picked', 'doctor_user_id', $this->doctor->id)
            ->assertSet('doctor_user_id', $this->doctor->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($this->doctor->id, $visit->fresh()->doctor_user_id);

        $this->panel($visit->fresh())
            ->call('openForm')
            ->call('cleared', 'doctor_user_id')
            ->assertSet('doctor_user_id', null);
    }

    /** And it is narrowed to the visit's own department, like every other pair. */
    public function test_the_doctor_picker_is_scoped_to_the_visits_department(): void
    {
        $dept = Department::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Paediatrics']);
        StaffProfile::factory()->create([
            'hospital_id' => $this->hospital->id, 'user_id' => $this->doctor->id, 'department_id' => $dept->id,
        ]);

        $visit = $this->visit(['department_id' => $dept->id]);

        $this->panel($visit)->call('openForm')->assertSee('Narrowed to Paediatrics');
    }

    // ── What is in front of you ──────────────────────────────────────────

    public function test_allergies_and_conditions_are_shown_while_writing(): void
    {
        $this->panel($this->visit())
            ->call('openForm')
            ->assertSee('Allergies')
            ->assertSee('Penicillin')
            ->assertSee('Sulfa drugs')
            ->assertSee('Type 2 diabetes');
    }

    public function test_the_vitals_just_taken_are_shown(): void
    {
        $visit = $this->visit();
        app(VisitService::class)->recordVitals($visit, [
            'temperature' => '38.4', 'blood_pressure' => '110/70', 'pulse' => '96',
        ]);

        $this->panel($visit->fresh())
            ->call('openForm')
            ->assertSee('38.4°C', escape: false)
            ->assertSee('110/70')
            ->assertSee('96 bpm');
    }

    /** What this patient was diagnosed with last time — the follow-up case. */
    public function test_the_previous_diagnosis_is_shown(): void
    {
        $earlier = $this->visit();
        app(VisitService::class)->updateClinical($earlier, ['diagnosis' => 'Uncomplicated malaria']);

        $this->panel($this->visit())
            ->call('openForm')
            ->assertSee('Last seen')
            ->assertSee('Uncomplicated malaria');
    }

    /** Another patient's history is never shown against this one. */
    public function test_another_patients_history_is_not_shown(): void
    {
        $other = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $theirs = app(VisitService::class)->open(['patient_id' => $other->id]);
        app(VisitService::class)->updateClinical($theirs, ['diagnosis' => 'Zzz somebody elses']);

        $this->panel($this->visit())
            ->call('openForm')
            ->assertDontSee('Zzz somebody elses');
    }

    // ── The phrases ──────────────────────────────────────────────────────

    public function test_every_note_field_offers_phrases(): void
    {
        $phrases = $this->panel($this->visit())->call('openForm')->get('phrases');

        foreach (['complaints', 'diagnosis', 'doctor_remarks'] as $field) {
            $this->assertNotEmpty($phrases[$field], "{$field} offers nothing");
        }
    }

    /** What reception wrote on THIS visit leads the complaints. */
    public function test_what_reception_wrote_is_offered_first(): void
    {
        $visit = $this->visit(['reason' => 'Fever for three days']);

        $phrases = $this->panel($visit)->call('openForm')->get('phrases');

        $this->assertSame('Fever for three days', $phrases['complaints'][0]['value']);
        $this->assertSame('reception', $phrases['complaints'][0]['source']);
    }

    /** What this hospital writes often comes before the curated set. */
    public function test_the_hospitals_own_words_come_before_the_curated_ones(): void
    {
        foreach (range(1, 3) as $ignored) {
            $visit = $this->visit();
            app(VisitService::class)->updateClinical($visit, ['diagnosis' => 'Snake bite']);
        }

        $values = array_column($this->panel($this->visit())->call('openForm')->get('phrases')['diagnosis'], 'value');

        $this->assertSame('Snake bite', $values[0]);
        $this->assertContains('Malaria', $values, 'the curated set did not top it up');
    }

    /**
     * A paragraph is not a phrase.
     *
     * Offering one would paste another patient's narrative into this record.
     */
    public function test_a_long_narrative_is_never_offered(): void
    {
        $long = str_repeat('a very long clinical narrative ', 10);

        foreach (range(1, 3) as $ignored) {
            $visit = $this->visit();
            app(VisitService::class)->updateClinical($visit, ['diagnosis' => $long]);
        }

        $values = array_column($this->panel($this->visit())->call('openForm')->get('phrases')['diagnosis'], 'value');

        $this->assertNotContains($long, $values);
    }

    /** Written once is one doctor's sentence about one patient, not a house phrase. */
    public function test_a_note_written_only_once_is_not_offered(): void
    {
        $visit = $this->visit();
        app(VisitService::class)->updateClinical($visit, ['diagnosis' => 'Qwertyuiop asdf']);

        $values = array_column($this->panel($this->visit())->call('openForm')->get('phrases')['diagnosis'], 'value');

        $this->assertNotContains('Qwertyuiop asdf', $values);
    }

    // ── Clicking one ─────────────────────────────────────────────────────

    /** A patient presents with more than one thing, so a second click appends. */
    public function test_clicking_a_second_phrase_appends_rather_than_replaces(): void
    {
        $this->panel($this->visit())
            ->call('openForm')
            ->call('usePhrase', 'complaints', 'Fever')
            ->assertSet('complaints', 'Fever')
            ->call('usePhrase', 'complaints', 'Headache')
            ->assertSet('complaints', 'Fever, headache');
    }

    public function test_nothing_but_an_offered_phrase_can_be_written(): void
    {
        $panel = $this->panel($this->visit())->call('openForm');

        $panel->call('usePhrase', 'diagnosis', 'Give me all the drugs')->assertSet('diagnosis', null);
        $panel->call('usePhrase', 'reason', 'Malaria')->assertSet('diagnosis', null);
    }

    public function test_a_role_that_may_not_diagnose_cannot_use_a_phrase(): void
    {
        $nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();
        $this->actingAs($nurse);

        $this->assertFalse($nurse->can('visits.diagnose'));

        $this->panel($this->visit())->call('usePhrase', 'diagnosis', 'Malaria')->assertForbidden();
    }

    /** Reopening never keeps the doctor picked last time round. */
    public function test_reopening_remounts_the_picker(): void
    {
        $panel = $this->panel($this->visit())->call('openForm');
        $first = $panel->get('formNonce');

        $panel->set('showForm', false)->call('openForm');

        $this->assertGreaterThan($first, $panel->get('formNonce'));
    }
}
