<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\TrialEnding;
use App\Notifications\WelcomeToTrial;
use App\Support\SupportContact;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The support WhatsApp is reachable from every surface, and read from one place. */
class SupportEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private const DIGITS = '256700111222';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'support.whatsapp' => self::DIGITS,
            'support.phone' => '+256 700 111 222',
            'support.email' => 'help@example.test',
            'support.hours' => 'Always on',
        ]);
        $this->seed(RbacSeeder::class);
    }

    public function test_every_public_page_offers_whatsapp_and_the_footer_facts_and_no_noreply(): void
    {
        foreach (['/', '/pricing', '/contact', '/features', '/security', '/privacy', '/terms'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('https://wa.me/'.self::DIGITS, $html, "{$path} has no WhatsApp link");
            $this->assertStringContainsString('class="wa-float"', $html, "{$path} has no floating button");
            $this->assertStringContainsString('+256 700 111 222', $html, "{$path} footer has no phone");
            $this->assertStringContainsString('help@example.test', $html, "{$path} footer has no email");
            $this->assertStringNotContainsStringIgnoringCase('noreply@', $html);
        }
    }

    public function test_the_contact_page_puts_whatsapp_before_the_form(): void
    {
        $html = $this->get('/contact')->getContent();

        $this->assertLessThan(strpos($html, '<form'), strpos($html, 'Chat with us on WhatsApp'));
    }

    public function test_sign_in_and_password_pages_say_where_to_get_help(): void
    {
        foreach (['/admin/login', '/forgot-password'] as $path) {
            $this->get($path)->assertOk()->assertSee('Trouble signing in?', false)->assertSee('https://wa.me/'.self::DIGITS, false);
        }
        $this->get('/register')->assertOk()->assertSee('name="whatsapp"', false)->assertSee('https://wa.me/'.self::DIGITS, false);
    }

    public function test_the_error_page_gives_a_reference_and_the_way_to_reach_us(): void
    {
        $this->get('/no-such-page-here')->assertNotFound()->assertSee('https://wa.me/'.self::DIGITS, false);
    }

    public function test_the_back_office_help_menu_is_on_the_screen(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertSee('Help and support', false)
            ->assertSee('WhatsApp support', false)
            ->assertSee('tel:+256700111222', false)
            ->assertSee('mailto:help@example.test', false);
    }

    public function test_vendor_emails_carry_the_support_line(): void
    {
        $hospital = Hospital::factory()->create(['name' => 'Kampala Dental']);
        $owner = User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'hospital_admin']);
        $subscription = \App\Models\Subscription::factory()->for($hospital)->for(Plan::factory())->create(['trial_ends_at' => now()->addDays(3)]);

        foreach ([new WelcomeToTrial($subscription), new TrialEnding($subscription, 3)] as $notification) {
            $mail = $notification->toMail($owner);
            $text = collect(array_merge($mail->introLines, $mail->outroLines))->implode("\n");

            $this->assertStringContainsString('wa.me/'.self::DIGITS, $text);
            $this->assertStringContainsString('Kampala%20Dental', $text);
        }
    }

    public function test_the_api_carries_the_support_block(): void
    {
        $this->getJson('/api/v1/public/plans')->assertOk()
            ->assertJsonPath('data.support.whatsapp', self::DIGITS)
            ->assertJsonPath('data.support.who', 'platform')
            ->assertJsonPath('data.support.templates.site.demo', fn ($v) => true);

        $hospital = Hospital::factory()->create();
        $admin = User::factory()->create(['hospital_id' => $hospital->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $this->getJson('/api/v1/meta')->assertOk()
            ->assertJsonPath('data.support.whatsapp', self::DIGITS)
            ->assertJsonPath('data.support.phone', '+256 700 111 222');
    }

    public function test_a_plan_limit_message_says_how_to_upgrade(): void
    {
        $this->assertStringContainsString('+256 700 111 222', \App\Exceptions\PlanLimitExceededException::make('staff', 5)->getMessage());
    }

    public function test_a_super_admin_setting_overrides_the_env_fallback(): void
    {
        \App\Support\Settings::set('contact_whatsapp', '+256 755 000 111');

        $this->assertSame('256755000111', SupportContact::platform()->whatsappDigits());
    }

    public function test_the_registration_needs_a_whatsapp_number_on_the_web_but_not_over_the_api(): void
    {
        $plan = Plan::factory()->create(['is_active' => true]);
        $data = ['hospital_name' => 'Gulu Clinic', 'name' => 'Ann Ojok', 'email' => 'ann@gulu.test', 'password' => 'Str0ng-pass-99', 'password_confirmation' => 'Str0ng-pass-99', 'plan_id' => $plan->id];

        $this->post('/register', $data)->assertSessionHasErrors('whatsapp');
        $this->postJson('/api/v1/public/register', $data)->assertJsonMissingValidationErrors('whatsapp');
    }
}
