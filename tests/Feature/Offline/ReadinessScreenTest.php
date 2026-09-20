<?php

namespace Tests\Feature\Offline;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\Device;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\Sync\PullService;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Am I ready to work without a connection?"
 *
 * The screen exists because of how both serious offline bugs were actually
 * found: not by this suite, but by somebody switching the server off and
 * discovering that something everybody assumed worked had never worked once.
 * A clinician should not have to find that out in a ward with no signal.
 *
 * Almost all of it is measured in the browser, so most of what can be tested
 * here is the frame: that the page opens for the people who need it, that the
 * server tells them the size of the download without lying about what is in
 * it, and that the page carries the two ids without which it would report on
 * the wrong local database.
 */
class ReadinessScreenTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $this->nurse->syncSpatieRole();
    }

    // ── Who may open it ──────────────────────────────────────────────────

    public function test_a_clinician_can_check_their_own_device(): void
    {
        // Deliberately NOT gated on `manage-users`. The people who work
        // offline are not the people who administer user accounts, and a
        // readiness screen only they could open would be useful to nobody.
        $this->actingAs($this->nurse)
            ->get(route('admin.offline.readiness'))
            ->assertOk()
            ->assertSee('Offline readiness');
    }

    public function test_it_needs_somebody_signed_in(): void
    {
        $this->get(route('admin.offline.readiness'))->assertRedirect();
    }

    public function test_the_menu_offers_it_to_a_clinician_who_cannot_administer_users(): void
    {
        // The fleet page beside it is `manage-users`. If this one inherited
        // that gate the feature would be invisible to its whole audience.
        $html = $this->actingAs($this->nurse)->get(route('admin.offline.readiness'))->assertOk()->getContent();

        $this->assertStringContainsString(route('admin.offline.readiness'), $html);
        $this->assertFalse($this->nurse->can('manage-users'), 'The fixture no longer proves anything.');
    }

    // ── What the server contributes ──────────────────────────────────────

    public function test_it_says_how_much_there_is_to_download(): void
    {
        // The one thing the browser cannot know. On a phone tether this is the
        // difference between a considered decision and a surprise.
        Patient::factory()->count(3)->create(['hospital_id' => $this->hospital->id]);

        $this->actingAs($this->nurse)
            ->get(route('admin.offline.readiness'))
            ->assertOk()
            ->assertSee('Available to you')
            ->assertSee('What is downloaded');
    }

    public function test_the_figures_are_the_same_streams_a_real_pull_would_send(): void
    {
        // Counted from `PullService` itself rather than re-derived, so the
        // promise on screen cannot drift from what actually arrives.
        Patient::factory()->count(4)->create(['hospital_id' => $this->hospital->id]);

        $counts = app(PullService::class)->availableFor($this->nurse);

        $this->assertArrayHasKey('patients', $counts);
        $this->assertSame(4, $counts['patients']);
    }

    public function test_the_figures_are_scoped_to_what_the_role_may_see(): void
    {
        // A receptionist has no business holding inpatient charts, and the
        // screen must not promise them either.
        $clerk = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist']);
        $clerk->syncSpatieRole();

        $nurseCounts = app(PullService::class)->availableFor($this->nurse);
        $clerkCounts = app(PullService::class)->availableFor($clerk);

        $this->assertArrayHasKey('vitals', $nurseCounts);
        $this->assertArrayNotHasKey('vitals', $clerkCounts);
    }

    public function test_no_money_is_ever_offered_for_download(): void
    {
        $counts = app(PullService::class)->availableFor($this->nurse);

        foreach (array_keys($counts) as $entity) {
            $this->assertStringNotContainsString('invoice', $entity);
            $this->assertStringNotContainsString('payment', $entity);
            $this->assertStringNotContainsString('card', $entity);
        }

        // And it is said on the page, because a clinician wondering where the
        // billing screen went deserves the reason rather than its absence.
        $this->actingAs($this->nurse)
            ->get(route('admin.offline.readiness'))
            ->assertSee('No money is ever downloaded');
    }

    // ── The ids the page cannot work without ─────────────────────────────

    public function test_the_panel_carries_the_ids_of_the_local_database(): void
    {
        // There is one local database per (hospital, user). If this page read
        // different ids from the ones Field Mode uses it would cheerfully
        // report on a database nobody is working in — which is worse than
        // reporting nothing, because it would say "ready".
        $this->actingAs($this->nurse)
            ->get(route('admin.offline.readiness'))
            ->assertOk()
            ->assertSee('<meta name="td-hospital" content="'.$this->hospital->id.'">', false)
            ->assertSee('<meta name="td-user" content="'.$this->nurse->id.'">', false);
    }

    public function test_the_panel_and_field_mode_agree_on_those_ids(): void
    {
        $panel = $this->actingAs($this->nurse)->get(route('admin.offline.readiness'))->getContent();
        $field = $this->actingAs($this->nurse)->get(route('field'))->getContent();

        foreach (['td-hospital', 'td-user', 'td-base'] as $name) {
            preg_match('/<meta name="'.$name.'" content="([^"]*)">/', $panel, $inPanel);
            preg_match('/<meta name="'.$name.'" content="([^"]*)">/', $field, $inField);

            $this->assertNotEmpty($inPanel, "The panel does not carry {$name}.");
            $this->assertNotEmpty($inField, "Field Mode does not carry {$name}.");
            $this->assertSame($inField[1], $inPanel[1], "The panel and Field Mode disagree about {$name}.");
        }
    }

    // ── This person's machines ───────────────────────────────────────────

    public function test_it_lists_the_machines_registered_to_this_person(): void
    {
        Device::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->nurse->id,
            'device_uuid' => (string) Str::uuid(),
            'label' => 'Maternity desk laptop',
            'registered_at' => now(),
        ]);

        $this->actingAs($this->nurse)
            ->get(route('admin.offline.readiness'))
            ->assertOk()
            ->assertSee('Maternity desk laptop');
    }

    public function test_it_does_not_list_somebody_elses_machines(): void
    {
        $other = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $other->syncSpatieRole();

        Device::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $other->id,
            'device_uuid' => (string) Str::uuid(),
            'label' => 'Somebody elses tablet',
            'registered_at' => now(),
        ]);

        $this->actingAs($this->nurse)
            ->get(route('admin.offline.readiness'))
            ->assertOk()
            ->assertDontSee('Somebody elses tablet');
    }

    public function test_a_blocked_machine_says_so_and_says_why(): void
    {
        Device::create([
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->nurse->id,
            'device_uuid' => (string) Str::uuid(),
            'label' => 'Lost in the car park',
            'registered_at' => now()->subDay(),
            'revoked_at' => now(),
            'revoked_reason' => 'Reported missing on Tuesday.',
        ]);

        $this->actingAs($this->nurse)
            ->get(route('admin.offline.readiness'))
            ->assertOk()
            ->assertSee('Blocked')
            ->assertSee('Reported missing on Tuesday.');
    }

    // ── No clinical content on a diagnostics page ────────────────────────

    public function test_the_page_carries_no_patient_data(): void
    {
        $patient = Patient::factory()->create([
            'hospital_id' => $this->hospital->id,
            'first_name' => 'Unmistakable',
            'last_name' => 'Testname',
        ]);

        $html = $this->actingAs($this->nurse)->get(route('admin.offline.readiness'))->getContent();

        // It reports COUNTS. A readiness screen showing names would be a
        // second copy of the register under nobody's governance.
        $this->assertStringNotContainsString('Unmistakable', $html);
        $this->assertStringNotContainsString($patient->patient_no, $html);
        $this->assertStringNotContainsString($patient->uuid, $html);
    }

    public function test_it_counts_a_real_admission_without_naming_anybody(): void
    {
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'Unmistakable']);
        $bed = Bed::factory()->create(['hospital_id' => $this->hospital->id]);
        app(AdmissionService::class)->admit($patient, $bed, [], $this->nurse->id);

        $counts = app(PullService::class)->availableFor($this->nurse);

        $this->assertSame(1, $counts['admissions'] ?? 0);
        $this->assertSame(1, Admission::count());

        $this->actingAs($this->nurse)
            ->get(route('admin.offline.readiness'))
            ->assertOk()
            ->assertDontSee('Unmistakable');
    }
}
