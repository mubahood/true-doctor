<?php

namespace Tests\Feature;

use App\Livewire\Settings\Hospital as Letterhead;
use App\Models\Hospital;
use App\Models\User;
use App\Support\BrandColour;
use App\Support\CurrentHospital;
use App\Support\DocumentBrand;
use App\Support\HospitalBrand;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A hospital's own colour and logos — on its screens, its documents and, on a
 * standalone installation, its sign-in page. Nothing changes for a hospital
 * that has set none of it.
 */
class HospitalBrandingTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Storage::fake('public');
        config()->set('onboarding.gate_enabled', false);

        $this->hospital = Hospital::factory()->create(['name' => 'Divine Dental Clinic']);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $this->admin->syncSpatieRole();
    }

    // ── The colour ───────────────────────────────────────────────────────

    public function test_a_colour_is_normalised_and_judged_by_its_contrast_with_white(): void
    {
        $this->assertSame('#1C4795', BrandColour::normalise(' #1c4795 '));
        $this->assertSame('#1C4795', BrandColour::normalise('1C4795'));
        $this->assertSame('#AABBCC', BrandColour::normalise('#abc'));
        $this->assertNull(BrandColour::normalise('navy'));
        $this->assertNull(BrandColour::normalise('#1C4795;}</style><script>'));

        $this->assertTrue(BrandColour::readableOnWhite('#1C4795'));   // the logo's navy
        $this->assertFalse(BrandColour::readableOnWhite('#2EBDEE'));  // the swoosh's cyan
        $this->assertEqualsWithDelta(21.0, BrandColour::contrastWithWhite('#000000'), 0.01);
        $this->assertEqualsWithDelta(1.0, BrandColour::contrastWithWhite('#FFFFFF'), 0.01);

        $palette = BrandColour::palette('#1C4795');
        $this->assertSame('#1C4795', $palette['base']);
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $palette['dark']);
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $palette['soft']);
        $this->assertGreaterThan(BrandColour::contrastWithWhite('#1C4795'), BrandColour::contrastWithWhite($palette['dark']));
    }

    public function test_the_letterhead_page_saves_a_readable_colour(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(Letterhead::class)
            ->set('brandColour', '#1c4795')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.settings.hospital'));

        $this->assertSame('#1C4795', $this->hospital->fresh()->settings['brand']['colour']);
    }

    public function test_a_colour_too_light_for_white_text_is_refused_with_the_reason(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(Letterhead::class)
            ->set('brandColour', '#2EBDEE')
            ->call('save')
            ->assertHasErrors('brandColour')
            ->assertSee('too light for white text');

        $this->assertNull($this->hospital->fresh()->settings['brand']['colour'] ?? null);
    }

    public function test_a_refused_colour_never_reads_as_meeting_the_minimum(): void
    {
        $this->actingAs($this->admin);

        // #777777 is 4.48:1 — refused, and must not be shown as "4.5:1".
        Livewire::test(Letterhead::class)
            ->set('brandColour', '#777777')
            ->assertHasErrors('brandColour')
            ->assertSee('contrast 4.4:1')
            ->set('brandColour', '')
            ->assertHasNoErrors('brandColour');
    }

    public function test_something_that_is_not_a_colour_is_refused(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(Letterhead::class)
            ->set('brandColour', 'red;}</style>')
            ->call('save')
            ->assertHasErrors('brandColour');
    }

    public function test_emptying_the_colour_goes_back_to_true_doctor_blue(): void
    {
        $this->brand(['colour' => '#1C4795']);
        $this->actingAs($this->admin);

        Livewire::test(Letterhead::class)
            ->assertSet('brandColour', '#1C4795')
            ->set('brandColour', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertArrayNotHasKey('colour', $this->hospital->fresh()->settings['brand'] ?? []);
        $this->assertSame('', HospitalBrand::current()->adminCss());
    }

    public function test_saving_the_brand_keeps_the_rest_of_the_settings(): void
    {
        $this->hospital->update(['settings' => ['billing' => ['invoice_prefix' => 'DDC'], 'profile' => ['phone' => '+256772556919']]]);
        $this->actingAs($this->admin);

        Livewire::test(Letterhead::class)->set('brandColour', '#1C4795')->call('save')->assertHasNoErrors();

        $settings = $this->hospital->fresh()->settings;
        $this->assertSame('DDC', $settings['billing']['invoice_prefix']);
        $this->assertSame('+256772556919', $settings['profile']['phone']);
        $this->assertSame('#1C4795', $settings['brand']['colour']);
    }

    // ── The screens ──────────────────────────────────────────────────────

    public function test_the_back_office_wears_the_hospitals_colour_and_logo(): void
    {
        Storage::disk('public')->put('logos/square.png', $this->png(240, 240));
        $this->hospital->update(['logo' => 'logos/square.png']);
        $this->brand(['colour' => '#1C4795']);

        $page = $this->actingAs($this->admin)->get(route('admin.settings.hospital'))->assertOk();

        $page->assertSee('--br:#1C4795', false);
        $page->assertSee('<meta name="theme-color" content="#1C4795">', false);
        $page->assertSee(Storage::disk('public')->url('logos/square.png'), false);
    }

    public function test_a_hospital_that_set_nothing_looks_as_it_always_did(): void
    {
        $page = $this->actingAs($this->admin)->get(route('admin.settings.hospital'))->assertOk();

        $page->assertDontSee('--br:#', false);
        $page->assertSee(asset('images/logo-icon.png'), false);
        $page->assertSee(asset('images/favicon.png'), false);
    }

    /**
     * Before the wide logo existed, the one logo slot was the letterhead's, so
     * hospitals filled it with horizontal logos. Those stay on documents and
     * never become a 30-pixel sliver in the menu or a squashed tab icon.
     */
    public function test_a_wide_letterhead_logo_never_becomes_the_menu_mark_or_tab_icon(): void
    {
        Storage::disk('public')->put('logos/wide.png', $this->png(600, 200));
        $this->hospital->update(['logo' => 'logos/wide.png']);

        $page = $this->actingAs($this->admin)->get(route('admin.settings.hospital'))->assertOk();

        $page->assertSee(asset('images/logo-icon.png'), false);
        $page->assertSee(asset('images/favicon.png'), false);
        $this->assertNull(HospitalBrand::current()->markUrl());
        $this->assertNotNull(HospitalBrand::current()->logoUrl());
    }

    public function test_a_logo_whose_file_has_gone_is_not_drawn_as_an_empty_box(): void
    {
        $this->hospital->update(['logo' => 'logos/gone.png']);

        // Any page: the menu mark and tab icon come from the layout.
        $page = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();

        $page->assertDontSee('logos/gone.png', false);
        $page->assertSee(asset('images/logo-icon.png'), false);
    }

    public function test_an_unreadable_stored_colour_never_reaches_the_page(): void
    {
        // The form refuses these; an imported or hand-edited row might not.
        $this->brand(['colour' => '#1C4795;}</style><script>alert(1)</script>']);
        $page = $this->actingAs($this->admin)->get(route('admin.settings.hospital'))->assertOk();
        $page->assertDontSee('<script>alert(1)</script>', false);
        $page->assertDontSee('--br:', false);

        $this->brand(['colour' => '#2EBDEE']);
        $this->assertSame('', HospitalBrand::current()->adminCss());
    }

    // ── The logos ────────────────────────────────────────────────────────

    public function test_a_wide_logo_is_stored_replaced_and_removed_without_leaving_files(): void
    {
        $this->actingAs($this->admin);

        $page = Livewire::test(Letterhead::class)
            ->set('wordmark', UploadedFile::fake()->image('wide.png', 1904, 826))
            ->call('save')
            ->assertHasNoErrors();
        $first = $this->hospital->fresh()->settings['brand']['wordmark'];
        Storage::disk('public')->assertExists($first);

        $page->set('wordmark', UploadedFile::fake()->image('wider.png', 1200, 400))->call('save')->assertHasNoErrors();
        $second = $this->hospital->fresh()->settings['brand']['wordmark'];
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);

        $page->call('removeWordmark')->assertSet('currentWordmark', null);
        Storage::disk('public')->assertMissing($second);
        $this->assertArrayNotHasKey('wordmark', $this->hospital->fresh()->settings['brand']);
    }

    public function test_documents_head_with_the_wide_logo_and_keep_the_square_one_for_small_marks(): void
    {
        Storage::disk('public')->put('logos/square.png', $this->png());
        Storage::disk('public')->put('logos/wide.png', $this->png());
        $this->hospital->update(['logo' => 'logos/square.png']);
        $this->brand(['colour' => '#1C4795', 'wordmark' => 'logos/wide.png']);

        $brand = app(DocumentBrand::class)->resolve();

        $this->assertTrue($brand['wide']);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $brand['logo']);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $brand['icon']);
        $this->assertSame('#1C4795', $brand['accent']);
    }

    public function test_documents_without_a_brand_print_as_before(): void
    {
        $brand = app(DocumentBrand::class)->resolve();

        $this->assertNull($brand['logo']);
        $this->assertFalse($brand['wide']);
        $this->assertNull($brand['accent']);
    }

    // ── The sign-in page ─────────────────────────────────────────────────

    public function test_a_standalone_sign_in_page_is_the_organisations(): void
    {
        Storage::disk('public')->put('logos/square.png', $this->png(240, 240));
        Storage::disk('public')->put('logos/wide.png', $this->png(590, 300));
        $marker = storage_path('framework/testing/installed-brand-'.bin2hex(random_bytes(4)).'.json');
        file_put_contents($marker, json_encode(['edition' => 'standalone', 'version' => '1.0.0']));
        config()->set('edition.name', 'standalone');
        config()->set('edition.installed_path', $marker);
        $this->hospital->update(['logo' => 'logos/square.png']);
        $this->brand(['colour' => '#1C4795', 'wordmark' => 'logos/wide.png']);

        try {
            $page = $this->get(route('admin.login'))->assertOk();

            $page->assertSee(Storage::disk('public')->url('logos/wide.png'), false);
            $page->assertSee('alt="Divine Dental Clinic"', false);
            $page->assertSee('--pri:#1C4795', false);
            $page->assertSee('Runs on True-Doctor');
            $page->assertSee('| Divine Dental Clinic</title>', false);
        } finally {
            @unlink($marker);
        }
    }

    public function test_the_standalone_sign_in_panel_speaks_for_the_organisation(): void
    {
        Storage::disk('public')->put('logos/wide.png', $this->png(590, 300));
        $marker = $this->standalone();
        $this->hospital->update(['address' => 'Plot 1088 Buye-Ntinda, Kampala', 'settings' => [
            'profile' => ['phone' => '+256 772 556 919', 'email' => 'info@divinedentalntinda.org', 'website' => 'divinedentalntinda.org'],
            'brand' => ['colour' => '#1C4795', 'wordmark' => 'logos/wide.png', 'tagline' => 'For a better smile'],
        ]]);

        try {
            $page = $this->get(route('admin.login'))->assertOk();

            $page->assertSee('class="is-org"', false);
            $page->assertSee('class="org-panel"', false);
            $page->assertSee('For a better smile');
            $page->assertSee('Plot 1088 Buye-Ntinda, Kampala');
            $page->assertSee('+256 772 556 919');
            $page->assertSee('Divine Dental Clinic staff only');
            $page->assertSee('placeholder="you@divinedentalntinda.org"', false);
            $page->assertSee('Your own server');
            $page->assertDontSee('Per-hospital isolation');
        } finally {
            @unlink($marker);
        }
    }

    public function test_a_standalone_install_with_no_brand_keeps_the_true_doctor_sign_in(): void
    {
        $marker = $this->standalone();

        try {
            $page = $this->get(route('admin.login'))->assertOk();

            $page->assertDontSee('org-panel', false);
            $page->assertSee(asset('images/logo-icon.png'), false);
            $page->assertSee('Hospital staff only');
        } finally {
            @unlink($marker);
        }
    }

    public function test_error_pages_wear_the_organisations_brand_on_its_own_server(): void
    {
        Storage::disk('public')->put('logos/wide.png', $this->png(590, 300));
        $marker = $this->standalone();
        $this->brand(['colour' => '#1C4795', 'wordmark' => 'logos/wide.png']);

        try {
            $page = $this->get('/no-such-page-at-all')->assertNotFound();

            $page->assertSee('We could not find that page');
            $page->assertSee('--br:#1C4795', false);
            $page->assertSee(Storage::disk('public')->url('logos/wide.png'), false);
            $page->assertSee('Divine Dental Clinic');
        } finally {
            @unlink($marker);
        }
    }

    public function test_online_error_pages_are_true_doctors(): void
    {
        $this->brand(['colour' => '#1C4795']);

        $page = $this->get('/no-such-page-at-all')->assertNotFound();

        $page->assertSee('We could not find that page');
        $page->assertSee('--br:#0A6EBD', false);
        $page->assertDontSee('Divine Dental Clinic');
    }

    public function test_the_maintenance_page_says_whose_system_is_updating(): void
    {
        $marker = $this->standalone();
        $this->brand(['colour' => '#1C4795']);

        try {
            // As `down --render=errors::503` pre-renders it during an update.
            $html = view('errors.503', ['retryAfter' => 30])->render();

            $this->assertStringContainsString('Being updated', $html);
            $this->assertStringContainsString('Divine Dental Clinic', $html);
        } finally {
            @unlink($marker);
        }
    }

    public function test_staff_emails_are_sent_in_the_hospitals_name_and_colour(): void
    {
        $this->brand(['colour' => '#1C4795', 'tagline' => 'For a better smile']);
        $staff = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist', 'name' => 'Christine Birungi']);

        $mail = new \App\Mail\WelcomeCredentials($staff, 'Temp-Pass-123');
        $html = $mail->render();

        $this->assertSame('Your Divine Dental Clinic staff account', $mail->envelope()->subject);
        $this->assertStringContainsString('Divine Dental Clinic', $html);
        $this->assertStringContainsString('#1C4795', $html);
        $this->assertStringContainsString('Temp-Pass-123', $html);
        $this->assertStringNotContainsString('Trading Simulator', $html);
        $this->assertStringNotContainsString('trading simulator', $html);

        $reset = (new \App\Notifications\ResetPasswordNotification('token-123'))->toMail($staff);
        $this->assertSame('Reset your password — Divine Dental Clinic', $reset->subject);
        $this->assertStringContainsString('#1C4795', (string) $reset->render());
    }

    public function test_the_app_is_told_the_brand(): void
    {
        Storage::disk('public')->put('logos/square.png', $this->png(240, 240));
        $this->hospital->update(['logo' => 'logos/square.png']);
        $this->brand(['colour' => '#1C4795', 'tagline' => 'For a better smile']);
        $token = $this->admin->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/meta')->assertOk()
            ->assertJsonPath('data.hospital.brand.colour', '#1C4795')
            ->assertJsonPath('data.hospital.brand.tagline', 'For a better smile')
            ->assertJsonPath('data.hospital.brand.mark_url', Storage::disk('public')->url('logos/square.png'));
    }

    public function test_a_hospitals_own_server_puts_its_name_on_the_browser_tab(): void
    {
        $marker = $this->standalone();
        $this->brand(['colour' => '#1C4795']);

        try {
            $this->actingAs($this->admin)->get(route('admin.settings.hospital'))->assertOk()
                ->assertSee('· Divine Dental Clinic</title>', false)
                ->assertSee('<meta name="td-title-suffix" content="Divine Dental Clinic">', false);
        } finally {
            @unlink($marker);
        }
    }

    public function test_the_dashboard_counts_only_what_the_practice_does(): void
    {
        $this->brand([]);
        $settings = $this->hospital->fresh()->settings;
        $settings['practice'] = ['sector' => 'dental_clinic', 'packs' => [], 'modules' => ['pharmacy'], 'chosen_at' => now()->toIso8601String()];
        $this->hospital->update(['settings' => $settings]);
        app()->forgetInstance(\App\Support\HospitalSettings::class);

        $practice = \App\Support\Practice\Practice::current();
        $this->assertFalse($practice->allows('ipd.view'));
        $this->assertFalse($practice->allows('patients.card.manage'));
        $this->assertTrue($practice->allows('pharmacy.view'));
        $this->assertTrue($practice->allows('visits.view'));

        $labels = array_column(\App\Support\Dashboard\StatCards::for('admin', [], $this->admin), 'label');
        $this->assertNotContains('Inpatients', $labels);
        $this->assertContains('Open visits', $labels);
    }

    public function test_a_specific_refusal_is_shown_and_a_generic_one_explained(): void
    {
        \Illuminate\Support\Facades\Route::middleware('web')->get('/_brand-test/why', fn () => abort(403, 'Your hospital’s subscription has ended.'));
        \Illuminate\Support\Facades\Route::middleware('web')->get('/_brand-test/generic', fn () => abort(403, 'This action is unauthorized.'));

        $this->get('/_brand-test/why')->assertForbidden()->assertSee('Your hospital’s subscription has ended.');
        $this->get('/_brand-test/generic')->assertForbidden()
            ->assertSee('Your role does not include it')
            ->assertDontSee('This action is unauthorized.');
    }

    public function test_only_maintenance_says_it_is_updating_and_nothing_reloads_by_meta(): void
    {
        $outage = view('errors.503')->render();
        $this->assertStringContainsString('Temporarily unavailable', $outage);
        $this->assertStringNotContainsString('http-equiv="refresh"', $outage);

        $maintenance = view('errors.503', ['retryAfter' => 30])->render();
        $this->assertStringContainsString('Being updated', $maintenance);
        $this->assertStringNotContainsString('http-equiv="refresh"', $maintenance);
    }

    public function test_a_hospital_whose_only_logo_is_wide_shows_it_in_emails_and_error_pages(): void
    {
        Storage::disk('public')->put('logos/wide-only.png', $this->png(600, 200));
        $marker = $this->standalone();
        $this->hospital->update(['logo' => 'logos/wide-only.png']);
        $this->brand(['colour' => '#1C4795']);
        $url = Storage::disk('public')->url('logos/wide-only.png');

        try {
            $this->assertSame($url, HospitalBrand::forSignIn()->wideUrl());
            $staff = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist']);
            $this->assertStringContainsString($url, (new \App\Mail\WelcomeCredentials($staff, 'x'))->render());
            $this->get('/no-such-page-at-all')->assertNotFound()->assertSee($url, false);
        } finally {
            @unlink($marker);
        }
    }

    public function test_a_colour_without_a_logo_still_heads_emails_with_the_hospitals_name(): void
    {
        $this->brand(['colour' => '#1C4795']);
        $staff = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'receptionist']);

        $html = (new \App\Mail\WelcomeCredentials($staff, 'x'))->render();

        $this->assertStringNotContainsString('logo-horizontal.png', $html);
        $this->assertStringContainsString('Divine Dental Clinic', $html);
    }

    public function test_the_picture_check_takes_the_brand_colour_on_the_sign_in_pages(): void
    {
        $this->brand(['colour' => '#1C4795']);
        $marker = $this->standalone();

        try {
            $this->assertStringContainsString('--br:#1C4795', HospitalBrand::forSignIn()->authCss());
        } finally {
            @unlink($marker);
        }
    }

    public function test_the_verification_email_is_the_hospitals_too(): void
    {
        $this->brand(['colour' => '#1C4795']);
        $staff = User::factory()->unverified()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor']);

        $mail = (new \App\Notifications\VerifyEmailNotification)->toMail($staff);

        $this->assertSame('Confirm your email address — Divine Dental Clinic', $mail->subject);
        $html = (string) $mail->render();
        $this->assertStringContainsString('#1C4795', $html);
        $this->assertStringContainsString('Confirm my address', $html);
    }

    public function test_an_accountant_at_a_practice_without_insurance_sees_no_claims(): void
    {
        $settings = $this->hospital->fresh()->settings ?? [];
        $settings['practice'] = ['sector' => 'dental_clinic', 'packs' => [], 'modules' => ['pharmacy'], 'chosen_at' => now()->toIso8601String()];
        $this->hospital->update(['settings' => $settings]);
        app()->forgetInstance(\App\Support\HospitalSettings::class);
        $accountant = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'accountant']);
        $accountant->syncSpatieRole();

        $labels = array_column(\App\Support\Dashboard\StatCards::for('accountant', [], $accountant), 'label');

        $this->assertNotContains('Pending claims', $labels);
        $this->assertContains('Outstanding', $labels);
    }

    public function test_the_online_sign_in_page_stays_true_doctors(): void
    {
        Storage::disk('public')->put('logos/square.png', $this->png(240, 240));
        Storage::disk('public')->put('logos/wide.png', $this->png(590, 300));
        $this->hospital->update(['logo' => 'logos/square.png']);
        $this->brand(['colour' => '#1C4795', 'wordmark' => 'logos/wide.png']);

        $page = $this->get(route('admin.login'))->assertOk();

        $page->assertDontSee(Storage::disk('public')->url('logos/wide.png'), false);
        $page->assertDontSee('--pri:', false);
        $page->assertSee(asset('images/logo-icon.png'), false);
    }

    /** Switch this test to a standalone installation; returns the marker to delete. */
    private function standalone(): string
    {
        $marker = storage_path('framework/testing/installed-brand-'.bin2hex(random_bytes(4)).'.json');
        file_put_contents($marker, json_encode(['edition' => 'standalone', 'version' => '1.0.0']));
        config()->set('edition.name', 'standalone');
        config()->set('edition.installed_path', $marker);

        return $marker;
    }

    /** @param array<string,mixed> $brand */
    private function brand(array $brand): void
    {
        $settings = $this->hospital->fresh()->settings ?? [];
        $settings['brand'] = $brand;
        $this->hospital->update(['settings' => $settings]);
        app()->forgetInstance(\App\Support\HospitalSettings::class);
    }

    private function png(int $width = 4, int $height = 2): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
