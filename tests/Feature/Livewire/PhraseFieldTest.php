<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Visits\Index as Visits;
use App\Livewire\Visits\Panels\Clinical;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\VisitService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fields that hold a LIST written as prose.
 *
 * "Fever, headache, joint pain" is one sentence and three findings. Clicking a
 * second suggestion used to wipe the first, which made the pills useful for
 * exactly one answer — and a patient almost never presents with exactly one
 * thing.
 *
 * One rule, in ChoosesPhrases, so the new-visit dialog and the clinical notes
 * cannot come to disagree about what a second click does.
 */
class PhraseFieldTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $doctor;

    /** Opening a visit is reception's job; writing the notes is the doctor's. */
    private User $clerk;

    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        $this->doctor->syncSpatieRole();

        $this->clerk = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist']);
        $this->clerk->syncSpatieRole();

        $this->actingAs($this->clerk);

        $this->visit = app(VisitService::class)->open([
            'patient_id' => Patient::factory()->create(['hospital_id' => $this->hospital->id])->id,
        ]);
    }

    private function dialog(): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAs($this->clerk);

        return Livewire::test(Visits::class)->call('create');
    }

    private function notes(): \Livewire\Features\SupportTesting\Testable
    {
        // Writing a diagnosis needs `visits.diagnose`, which is the doctor's.
        $this->actingAs($this->doctor);
        Livewire::withoutLazyLoading();

        return Livewire::test(Clinical::class, ['visitId' => $this->visit->id])->call('openForm');
    }

    // ── Many, separated by commas ────────────────────────────────────────

    public function test_a_second_complaint_is_added_rather_than_replacing_the_first(): void
    {
        $this->dialog()
            ->call('usePhrase', 'complaints', 'Fever')
            ->assertSet('complaints', 'Fever')
            ->call('usePhrase', 'complaints', 'Headache')
            ->assertSet('complaints', 'Fever, headache')
            ->call('usePhrase', 'complaints', 'Cough')
            ->assertSet('complaints', 'Fever, headache, cough');
    }

    /** Somebody comes in for a follow-up AND a medication refill. */
    public function test_reasons_stack_the_same_way(): void
    {
        $page = $this->dialog();
        $offered = $page->instance()->reasonSuggestions();

        $page->call('usePhrase', 'reason', $offered[0])
            ->call('usePhrase', 'reason', $offered[1]);

        $this->assertStringContainsString(', ', (string) $page->get('reason'));
        $this->assertStringContainsString($offered[0], (string) $page->get('reason'));
    }

    public function test_clicking_a_phrase_again_takes_it_out(): void
    {
        $this->dialog()
            ->call('usePhrase', 'complaints', 'Fever')
            ->call('usePhrase', 'complaints', 'Headache')
            ->call('usePhrase', 'complaints', 'Cough')
            ->assertSet('complaints', 'Fever, headache, cough')
            ->call('usePhrase', 'complaints', 'Headache')
            ->assertSet('complaints', 'Fever, cough');
    }

    /** Removing the first one gives the next its capital back. */
    public function test_removing_the_first_leaves_a_sentence_that_still_reads(): void
    {
        $this->dialog()
            ->call('usePhrase', 'complaints', 'Fever')
            ->call('usePhrase', 'complaints', 'Headache')
            ->assertSet('complaints', 'Fever, headache')
            ->call('usePhrase', 'complaints', 'Fever')
            ->assertSet('complaints', 'Headache');
    }

    public function test_taking_the_last_one_out_empties_the_field(): void
    {
        $this->dialog()
            ->call('usePhrase', 'complaints', 'Fever')
            ->call('usePhrase', 'complaints', 'Fever')
            ->assertSet('complaints', null);
    }

    /** Clicking the same thing twice in a row is not two of it. */
    public function test_a_phrase_is_never_added_twice(): void
    {
        $page = $this->dialog()
            ->call('usePhrase', 'complaints', 'Fever')
            ->call('usePhrase', 'complaints', 'Fever')
            ->call('usePhrase', 'complaints', 'Fever');

        $this->assertSame('Fever', (string) $page->get('complaints'));
    }

    // ── What somebody typed is theirs ────────────────────────────────────

    /**
     * The field belongs to whoever is writing in it. A pill adds to what is
     * there; it never re-words it.
     */
    public function test_text_typed_by_hand_is_left_exactly_as_written(): void
    {
        $this->dialog()
            ->set('complaints', 'Vomiting since Tuesday')
            ->call('usePhrase', 'complaints', 'Fever')
            ->assertSet('complaints', 'Vomiting since Tuesday, fever')
            ->call('usePhrase', 'complaints', 'Fever')
            ->assertSet('complaints', 'Vomiting since Tuesday');
    }

    // ── The pills read back what is chosen ───────────────────────────────

    public function test_a_pill_lights_up_when_its_phrase_is_in_the_field(): void
    {
        $page = $this->dialog();

        $this->assertFalse($page->instance()->hasPhrase('complaints', 'Fever'));

        $page->call('usePhrase', 'complaints', 'Fever');

        $this->assertTrue($page->instance()->hasPhrase('complaints', 'Fever'));
        $this->assertFalse($page->instance()->hasPhrase('complaints', 'Cough'));
    }

    /** Case is not a difference: "fever" in the field matches the "Fever" pill. */
    public function test_a_pill_matches_whatever_case_the_field_holds(): void
    {
        $page = $this->dialog()->set('complaints', 'Headache, FEVER');

        $this->assertTrue($page->instance()->hasPhrase('complaints', 'Fever'));
    }

    // ── Only what was offered, only where it belongs ─────────────────────

    public function test_a_phrase_that_was_never_offered_is_refused(): void
    {
        $this->dialog()
            ->call('usePhrase', 'complaints', 'Anything at all')
            ->assertSet('complaints', null);
    }

    public function test_a_field_the_form_does_not_own_is_refused(): void
    {
        $page = $this->dialog()->set('reason', 'Follow-up visit');

        $page->call('usePhrase', 'visit_no', 'Fever');

        $this->assertSame('Follow-up visit', (string) $page->get('reason'));
    }

    // ── And the notes panel does exactly the same ────────────────────────

    public function test_the_clinical_notes_stack_and_unstack_the_same_way(): void
    {
        $notes = $this->notes();
        $offered = array_column($notes->instance()->phrases()['diagnosis'], 'value');

        $notes->call('usePhrase', 'diagnosis', $offered[0])
            ->call('usePhrase', 'diagnosis', $offered[1]);

        $this->assertStringContainsString(', ', (string) $notes->get('diagnosis'));

        $notes->call('usePhrase', 'diagnosis', $offered[1]);

        $this->assertSame($offered[0], (string) $notes->get('diagnosis'));
    }

    public function test_both_screens_share_one_implementation(): void
    {
        foreach ([Visits::class, Clinical::class] as $component) {
            $this->assertContains(
                \App\Livewire\Concerns\ChoosesPhrases::class,
                class_uses_recursive($component),
                $component.' has its own idea of what a second click does',
            );
        }
    }
}
