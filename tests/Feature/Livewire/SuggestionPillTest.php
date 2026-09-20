<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Appointments\Index as Appointments;
use App\Livewire\Schedules\Index as Schedules;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What a suggestion pill actually posts.
 *
 * `['10' => '10 min']` reads as value => label, but PHP casts a canonical
 * decimal string key to an int the moment it becomes an array key — so the
 * old `is_int($key)` test said "this is a plain list" and the pill posted the
 * LABEL. "10 min" into `public int $slot_minutes` is a 500, and into a string
 * property it is silent rubbish that only fails later, at validation.
 */
class SuggestionPillTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $admin = User::factory()->create([
            'hospital_id' => $this->hospital->id, 'role' => 'hospital_admin',
        ]);
        $admin->syncSpatieRole();

        $this->doctor = User::factory()->create([
            'hospital_id' => $this->hospital->id, 'role' => 'doctor',
        ]);
        $this->doctor->syncSpatieRole();

        $this->actingAs($admin);
    }

    /** The click script sits in an attribute, so read it back un-escaped. */
    private function pills(string $blade, array $data = []): string
    {
        return html_entity_decode(Blade::render($blade, $data), ENT_QUOTES);
    }

    // ── What the pill carries ────────────────────────────────────────────

    /** A map offers the KEY and shows the label. */
    public function test_a_numeric_key_is_what_the_pill_posts(): void
    {
        $html = $this->pills(
            '<x-ui.suggestions set="slot_minutes" :options="[\'10\' => \'10 min\', \'60\' => \'1 hour\']" />'
        );

        $this->assertStringContainsString("\$wire.set('slot_minutes', 10)", $html);
        $this->assertStringContainsString("\$wire.set('slot_minutes', 60)", $html);
        $this->assertStringNotContainsString("'1 hour'", $html, 'the pill posts its label instead of its value');
        $this->assertStringContainsString('1 hour', $html, 'the label is still what the reader sees');
    }

    /** A non-numeric key behaves the same way — this never was broken. */
    public function test_a_worded_key_is_what_the_pill_posts(): void
    {
        $html = $this->pills(
            '<x-ui.suggestions set="reason" :options="[\'follow-up\' => \'Follow-up\']" />'
        );

        $this->assertStringContainsString("\$wire.set('reason', 'follow-up')", $html);
    }

    /** A plain list has no separate label: the value is the label. */
    public function test_a_plain_list_posts_its_own_entries(): void
    {
        $html = $this->pills('<x-ui.suggestions set="start_time" :options="[\'07:00\', \'08:00\']" />');

        $this->assertStringContainsString("\$wire.set('start_time', '07:00')", $html);
        $this->assertStringContainsString("\$wire.set('start_time', '08:00')", $html);
    }

    /** The pill reads back as chosen whichever way the value was typed. */
    public function test_a_pill_lights_up_against_an_int_the_component_holds(): void
    {
        $html = $this->pills(
            '<x-ui.suggestions set="slot_minutes" :current="30" :options="[\'15\' => \'15 min\', \'30\' => \'30 min\']" />'
        );

        $this->assertMatchesRegularExpression('/is-on[^>]*>\s*30 min/', $html);
        $this->assertDoesNotMatchRegularExpression('/is-on[^>]*>\s*15 min/', $html);
    }

    // ── And the components those pills point at accept it ────────────────

    public function test_choosing_a_slot_length_does_not_blow_up(): void
    {
        Livewire::test(Schedules::class)
            ->call('create')
            ->set('slot_minutes', 45)
            ->assertSet('slot_minutes', 45)
            ->assertOk();
    }

    public function test_choosing_an_appointment_length_does_not_blow_up(): void
    {
        Livewire::test(Appointments::class)
            ->call('create')
            ->set('duration_minutes', 60)
            ->assertSet('duration_minutes', 60)
            ->assertOk();
    }

    // ── Emptying a number field is a mistake, not a crash ────────────────

    /**
     * `wire:model` on <input type="number"> posts "" when the field is
     * cleared, which ConvertEmptyStringsToNull turns into null — and a
     * non-nullable `int` cannot hold null, so Livewire unsets the property.
     *
     * That lands on the declared default rather than on an error page: a
     * window with no slot length given is thirty minutes, which is what the
     * field says before anybody touches it. Pinned here because it is the
     * kind of thing a later type change would quietly turn into a 500.
     */
    public function test_clearing_the_slot_length_falls_back_to_the_default(): void
    {
        Livewire::test(Schedules::class)
            ->call('create')
            ->call('pickedForForm', 'user_id', $this->doctor->id)
            ->set('weekday', '1')
            ->set('start_time', '08:00')
            ->set('end_time', '12:00')
            ->set('slot_minutes', null)
            ->assertOk()
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(30, DoctorSchedule::firstOrFail()->slot_minutes);
    }

    public function test_clearing_the_appointment_length_leaves_a_page_that_still_renders(): void
    {
        Livewire::test(Appointments::class)
            ->call('create')
            ->set('duration_minutes', null)
            ->assertOk();
    }
}
