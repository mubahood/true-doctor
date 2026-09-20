<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Visits\Panels\Vitals;
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
 * What is offered under each vitals field.
 *
 * Two kinds of suggestion, and the difference is the whole design. The
 * patient's own last reading is a real datum and is marked as one. The rest
 * are common readings spread deliberately ACROSS the clinical range — because
 * a field that offers only the healthy value invites someone to accept it
 * without measuring, and that is how a record comes to say something nobody
 * observed.
 */
class VitalsSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private Patient $patient;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $this->nurse->syncSpatieRole();
        $this->actingAs($this->nurse);

        $this->patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
    }

    private function visit(): Visit
    {
        return app(VisitService::class)->open(['patient_id' => $this->patient->id]);
    }

    private function panel(Visit $visit): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Vitals::class, ['visitId' => $visit->id, 'lazy' => false]);
    }

    // ── What is offered ──────────────────────────────────────────────────

    public function test_every_field_offers_something_except_weight(): void
    {
        $suggestions = $this->panel($this->visit())->get('suggestions');

        foreach (['temperature', 'blood_pressure', 'pulse', 'spo2', 'respiratory_rate'] as $field) {
            $this->assertNotEmpty($suggestions[$field], "{$field} offers nothing");
        }

        // A guessed weight is worse than a blank one: it is dosing information.
        $this->assertSame([], $suggestions['weight']);
    }

    /**
     * Spread, not clustered on normal.
     *
     * Four ways of saying afebrile would make the click a default to accept;
     * 36.5 to 39.5 makes it a choice between real alternatives.
     */
    public function test_the_common_readings_span_the_clinical_range(): void
    {
        $suggestions = $this->panel($this->visit())->get('suggestions');

        $temps = array_map('floatval', array_column($suggestions['temperature'], 'value'));
        $this->assertLessThanOrEqual(37.0, min($temps), 'nothing afebrile is offered');
        $this->assertGreaterThanOrEqual(39.0, max($temps), 'no fever is offered');

        $spo2 = array_map('intval', array_column($suggestions['spo2'], 'value'));
        $this->assertLessThanOrEqual(90, min($spo2), 'no low saturation is offered');
        $this->assertGreaterThanOrEqual(98, max($spo2));
    }

    /** Every offered value must be one the form would accept. */
    public function test_nothing_offered_would_be_rejected_on_save(): void
    {
        $visit = $this->visit();
        $suggestions = $this->panel($visit)->get('suggestions');

        foreach ($suggestions as $field => $pills) {
            foreach ($pills as $pill) {
                $this->panel($visit)
                    ->call('openForm')
                    ->call('useVital', $field, $pill['value'])
                    ->call('save')
                    ->assertHasNoErrors($field);
            }
        }
    }

    // ── The patient's own last reading ───────────────────────────────────

    /**
     * The useful one. An adult's height does not change between visits, and a
     * nurse should not fetch a tape measure to record what is already known.
     */
    public function test_the_patients_last_reading_is_offered_first_and_marked(): void
    {
        $earlier = $this->visit();
        app(VisitService::class)->recordVitals($earlier, [
            'temperature' => '38.4', 'height' => '172', 'weight' => '68.5',
        ]);

        $suggestions = $this->panel($this->visit())->get('suggestions');

        $this->assertSame('172', $suggestions['height'][0]['value']);
        $this->assertSame('Last 172', $suggestions['height'][0]['label']);
        $this->assertTrue($suggestions['height'][0]['last']);

        // Weight offers only the last one — never a common value.
        $this->assertCount(1, $suggestions['weight']);
        $this->assertSame('68.5', $suggestions['weight'][0]['value']);

        // And it leads the list for a field that has common values too.
        $this->assertSame('38.4', $suggestions['temperature'][0]['value']);
        $this->assertTrue($suggestions['temperature'][0]['last']);
    }

    /** Printed as anyone writes it: 68.5 kg, not 68.50. */
    public function test_the_last_reading_drops_its_trailing_noughts(): void
    {
        $earlier = $this->visit();
        app(VisitService::class)->recordVitals($earlier, ['weight' => '70.00', 'height' => '180.50']);

        $suggestions = $this->panel($this->visit())->get('suggestions');

        $this->assertSame('70', $suggestions['weight'][0]['value']);
        $this->assertSame('180.5', $suggestions['height'][0]['value']);
    }

    /** It is never offered twice — once as "last" and again as a common value. */
    public function test_a_last_reading_that_is_also_common_is_offered_once(): void
    {
        $earlier = $this->visit();
        app(VisitService::class)->recordVitals($earlier, ['pulse' => '80']);

        $pulse = array_column($this->panel($this->visit())->get('suggestions')['pulse'], 'value');

        $this->assertSame(['80'], array_values(array_unique(array_filter($pulse, fn ($v) => $v === '80'))));
        $this->assertCount(count(array_unique($pulse)), $pulse, 'a value was offered twice');
    }

    /** Another patient's readings are never offered for this one. */
    public function test_another_patients_readings_are_not_offered(): void
    {
        $someoneElse = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $theirs = app(VisitService::class)->open(['patient_id' => $someoneElse->id]);
        app(VisitService::class)->recordVitals($theirs, ['height' => '199']);

        $suggestions = $this->panel($this->visit())->get('suggestions');

        $this->assertSame([], $suggestions['height'], "another patient's height was offered");
    }

    // ── Clicking one ─────────────────────────────────────────────────────

    public function test_clicking_a_pill_fills_the_field(): void
    {
        $this->panel($this->visit())
            ->call('openForm')
            ->call('useVital', 'temperature', '38.5')
            ->assertSet('temperature', '38.5');
    }

    /** Height and weight together recompute the BMI preview. */
    public function test_filling_height_and_weight_updates_the_bmi_preview(): void
    {
        $earlier = $this->visit();
        app(VisitService::class)->recordVitals($earlier, ['height' => '170', 'weight' => '70']);

        $panel = $this->panel($this->visit())
            ->call('openForm')
            ->call('useVital', 'height', '170')
            ->call('useVital', 'weight', '70');

        $this->assertNotNull($panel->get('bmi'));
        $this->assertEqualsWithDelta(24.22, $panel->get('bmi'), 0.05);
    }

    /** Only the seven fields, and only values that field actually offered. */
    public function test_nothing_else_can_be_written_through_a_pill(): void
    {
        $panel = $this->panel($this->visit())->call('openForm');

        $panel->call('useVital', 'temperature', '44.9')->assertSet('temperature', null);
        $panel->call('useVital', 'weight', '70')->assertSet('weight', null);
        $panel->call('useVital', 'diagnosis', 'Malaria')->assertSet('temperature', null);
    }

    /** A reader who may not record vitals cannot use them either. */
    public function test_a_role_without_vitals_cannot_use_a_pill(): void
    {
        $doctor = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);
        $doctor->syncSpatieRole();
        $this->actingAs($doctor);

        $this->assertFalse($doctor->can('visits.vitals'));

        $this->panel($this->visit())->call('useVital', 'temperature', '38.5')->assertForbidden();
    }
}
