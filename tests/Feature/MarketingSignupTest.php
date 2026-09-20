<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Support\VisitorRegion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The path from the public site into a signed-up hospital.
 *
 * A plan's stored price is in the PLATFORM's currency — shillings, because
 * that is what a subscription is settled in (see config/pricing.php and
 * PlanSeeder). The public pages quote it in shillings or convert it to
 * dollars depending on where the reader appears to be, and these fixtures are
 * priced accordingly. They used to be written as though the column held
 * dollars, which made the page and the seeder disagree about what a number
 * meant.
 */
class MarketingSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pricing.base_currency', 'UGX');
        config()->set('services.pesapal.usd_to_ugx_rate', 3800);
    }

    public function test_pricing_page_lists_real_plans_with_trial_ctas(): void
    {
        $starter = Plan::factory()->create([
            'name' => 'Starter', 'slug' => 'starter', 'price' => '49000.00', 'is_active' => true,
        ]);
        Plan::factory()->create([
            'name' => 'Professional', 'slug' => 'professional', 'price' => '149000.00', 'is_active' => true,
        ]);

        // In shillings, for a reader who appears to be in East Africa.
        $this->withHeader('Accept-Language', 'en-UG')
            ->get('/pricing')
            ->assertOk()
            ->assertSee('Starter')
            ->assertSee('USh 49,000')
            ->assertSee('USh 149,000')
            ->assertSee('Start 14-day trial')
            ->assertSee(route('register', ['plan' => $starter->id]), false);
    }

    /** The same plans, converted, for a reader anywhere else. */
    public function test_the_pricing_page_converts_for_a_reader_outside_east_africa(): void
    {
        Plan::factory()->create(['name' => 'Starter', 'slug' => 'starter', 'price' => '38000.00', 'is_active' => true]);

        $this->withHeader('Accept-Language', 'en-GB')
            ->get('/pricing')
            ->assertOk()
            ->assertSee('$10.00')
            ->assertDontSee('USh 38,000');
    }

    /** Whatever the reader is quoted, the sign-up link is the same link. */
    public function test_the_plan_link_survives_the_currency_it_is_quoted_in(): void
    {
        $plan = Plan::factory()->create(['name' => 'Starter', 'slug' => 'starter', 'price' => '49000.00', 'is_active' => true]);

        foreach (['UGX', 'USD'] as $currency) {
            $this->withCookie(VisitorRegion::COOKIE, $currency)
                ->get('/pricing')
                ->assertOk()
                ->assertSee(route('register', ['plan' => $plan->id]), false);
        }
    }

    public function test_home_hero_links_to_registration(): void
    {
        $this->get('/')->assertOk()->assertSee(route('register'), false)->assertSee('Sign up');
    }

    public function test_header_shows_signup_and_signin(): void
    {
        $this->get('/')->assertOk()
            ->assertSee(route('register'), false)
            ->assertSee(route('admin.login'), false);
    }

    public function test_register_preselects_plan_from_query(): void
    {
        Plan::factory()->create(['name' => 'Starter', 'price' => '49000.00', 'is_active' => true]);
        $pro = Plan::factory()->create(['name' => 'Professional', 'price' => '149000.00', 'is_active' => true]);

        $html = $this->get(route('register', ['plan' => $pro->id]))->assertOk()->getContent();

        // Whitespace-tolerant: the attributes sit on separate lines in the
        // markup, and whether a radio is checked is not a fact about how the
        // template happens to be wrapped.
        $this->assertMatchesRegularExpression(
            '/value="'.$pro->id.'"\s+checked/',
            $html,
            'the plan carried in the query string is not the one selected',
        );
    }

    public function test_an_unknown_plan_in_the_query_falls_back_rather_than_failing(): void
    {
        $first = Plan::factory()->create(['name' => 'Starter', 'price' => '49000.00', 'is_active' => true]);

        // This URL gets shared and edited by hand; a stale plan id must not
        // produce a form with nothing selected.
        $html = $this->get(route('register', ['plan' => 99999]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/value="'.$first->id.'"\s+checked/', $html);
    }
}
