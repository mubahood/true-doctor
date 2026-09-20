<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Onboarding\Steps\Profile;
use App\Livewire\Settings\Hospital as Letterhead;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use App\Support\DocumentBrand;
use App\Support\OnboardingStatus;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The hospital's letterhead — and the one place it is stored.
 *
 * Setup asked for a phone number and wrote `settings['contact']`. The
 * letterhead page asked for the same phone number and wrote
 * `settings['profile']`, which is what DocumentBrand prints. So a hospital
 * could answer either one and be wrong: fill in setup and every invoice went
 * out with no way to ring anybody; fill in the letterhead and the setup step
 * holding you in the wizard stayed unticked.
 */
class LetterheadPageTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Storage::fake('public');

        $this->hospital = Hospital::factory()->create(['address' => 'Plot 4, Kampala Road']);
        app(CurrentHospital::class)->set($this->hospital->id);

        $admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();
        $this->actingAs($admin);
    }

    // ── One store ────────────────────────────────────────────────────────

    public function test_what_the_letterhead_saves_is_what_setup_reads(): void
    {
        Livewire::test(Letterhead::class)
            ->set('name', 'St. Mary Clinic')
            ->set('address', 'Plot 4, Kampala Road')
            ->set('phone', '+256700111222')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $this->hospital->fresh();

        $this->assertTrue(
            app(OnboardingStatus::class)->profileConfigured($fresh),
            'filling this in must tick the setup step that is holding the admin in the wizard',
        );
        $this->assertSame('+256700111222', app(DocumentBrand::class)->profileOf($fresh)['phone']);
    }

    public function test_what_setup_saves_shows_up_on_this_page(): void
    {
        Livewire::test(Profile::class)
            ->set('name', 'St. Mary Clinic')
            ->set('address', 'Plot 4, Kampala Road')
            ->set('email', 'info@stmary.org')
            ->set('timezone', 'Africa/Kampala')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test(Letterhead::class)->assertSet('email', 'info@stmary.org');
    }

    /** Hospitals set up before the merge keep what they already gave. */
    public function test_details_under_the_old_key_are_still_shown_and_printed(): void
    {
        $this->hospital->settings = ['contact' => ['phone' => '+256700999888']];
        $this->hospital->save();

        Livewire::test(Letterhead::class)->assertSet('phone', '+256700999888');
        $this->assertSame('+256700999888', app(DocumentBrand::class)->profileOf($this->hospital->fresh())['phone']);
    }

    /** Saving one field must not wipe the ones the other page owns. */
    public function test_saving_the_letterhead_keeps_everything_else_in_the_store(): void
    {
        $this->hospital->settings = ['profile' => ['website' => 'stmary.org', 'registration' => 'REG-99']];
        $this->hospital->save();

        Livewire::test(Letterhead::class)
            ->set('name', 'St. Mary Clinic')
            ->set('phone', '+256700111222')
            ->set('website', 'stmary.org')
            ->set('registration', 'REG-99')
            ->call('save')
            ->assertHasNoErrors();

        $profile = $this->hospital->fresh()->settings['profile'];

        $this->assertSame('stmary.org', $profile['website']);
        $this->assertSame('REG-99', $profile['registration']);
        $this->assertSame('+256700111222', $profile['phone']);
    }

    // ── The logo ─────────────────────────────────────────────────────────

    public function test_a_logo_can_be_uploaded_from_the_letterhead(): void
    {
        Livewire::test(Letterhead::class)
            ->set('name', 'St. Mary Clinic')
            ->set('logo', UploadedFile::fake()->image('mark.png', 400, 200))
            ->call('save')
            ->assertHasNoErrors();

        $stored = $this->hospital->fresh()->logo;

        $this->assertNotNull($stored);
        Storage::disk('public')->assertExists($stored);
    }

    /**
     * And from SETUP, which is where a new hospital actually is: the logo used
     * to live only on a settings page they had no reason to visit, so the
     * first invoice they ever sent went out plain and nothing had asked.
     */
    public function test_a_logo_can_be_uploaded_during_setup(): void
    {
        Livewire::test(Profile::class)
            ->set('name', 'St. Mary Clinic')
            ->set('address', 'Plot 4, Kampala Road')
            ->set('phone', '+256700111222')
            ->set('timezone', 'Africa/Kampala')
            ->set('logo', UploadedFile::fake()->image('mark.png', 400, 200))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNotNull($this->hospital->fresh()->logo);
    }

    /** It is a nudge, never a blocker: a hospital without a logo is a hospital. */
    public function test_setup_completes_without_a_logo(): void
    {
        Livewire::test(Profile::class)
            ->set('name', 'St. Mary Clinic')
            ->set('address', 'Plot 4, Kampala Road')
            ->set('phone', '+256700111222')
            ->set('timezone', 'Africa/Kampala')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $this->hospital->fresh();

        $this->assertTrue(app(OnboardingStatus::class)->profileConfigured($fresh));
        $this->assertContains(
            'No logo yet — documents will print with your name only.',
            app(OnboardingStatus::class)->steps($fresh)->firstOrFail(fn ($s) => $s->key === 'profile')->issues,
        );
    }

    public function test_the_nudge_goes_once_there_is_a_logo(): void
    {
        $this->hospital->logo = 'logos/mark.png';
        $this->hospital->save();

        $issues = app(OnboardingStatus::class)->steps($this->hospital->fresh())
            ->firstOrFail(fn ($s) => $s->key === 'profile')->issues;

        $this->assertNotContains('No logo yet — documents will print with your name only.', $issues);
    }

    public function test_replacing_a_logo_does_not_leave_the_old_file_behind(): void
    {
        $page = Livewire::test(Letterhead::class)
            ->set('name', 'St. Mary Clinic')
            ->set('logo', UploadedFile::fake()->image('first.png', 400, 200))
            ->call('save');

        $first = $this->hospital->fresh()->logo;

        $page->set('logo', UploadedFile::fake()->image('second.png', 400, 200))->call('save');

        $second = $this->hospital->fresh()->logo;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_removing_the_logo_takes_it_off_every_document(): void
    {
        Livewire::test(Letterhead::class)
            ->set('name', 'St. Mary Clinic')
            ->set('logo', UploadedFile::fake()->image('mark.png', 400, 200))
            ->call('save')
            ->call('removeLogo')
            ->assertSet('currentLogo', null);

        $this->assertNull($this->hospital->fresh()->logo);
    }

    public function test_something_that_is_not_an_image_is_refused(): void
    {
        Livewire::test(Letterhead::class)
            ->set('name', 'St. Mary Clinic')
            ->set('logo', UploadedFile::fake()->create('prices.pdf', 40, 'application/pdf'))
            ->call('save')
            ->assertHasErrors('logo');

        $this->assertNull($this->hospital->fresh()->logo);
    }

    // ── The page ─────────────────────────────────────────────────────────

    /** Dragging a logo onto the page is what people try first. */
    public function test_the_logo_field_takes_a_dropped_file(): void
    {
        $html = Livewire::test(Letterhead::class)->html();

        $this->assertStringContainsString('tb-drop', $html);
        $this->assertStringContainsString('drop.prevent', $html);
    }

    public function test_both_pages_use_the_same_drop_field(): void
    {
        foreach ([Letterhead::class, Profile::class] as $component) {
            $this->assertStringContainsString(
                'tb-drop',
                Livewire::test($component)->html(),
                $component.' has its own idea of how a file is chosen',
            );
        }
    }

    /** The preview is the point of the page; it must redraw as you type. */
    public function test_the_preview_follows_what_is_typed(): void
    {
        Livewire::test(Letterhead::class)
            ->set('name', 'St. Mary Clinic')
            ->set('footer', 'Thank you for choosing us.')
            ->assertSee('St. Mary Clinic')
            ->assertSee('Thank you for choosing us.');
    }

    public function test_only_somebody_who_may_manage_settings_can_open_it(): void
    {
        $nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();

        $this->actingAs($nurse)->get(route('admin.settings.hospital'))->assertForbidden();
    }
}
