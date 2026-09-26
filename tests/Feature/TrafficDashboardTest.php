<?php

namespace Tests\Feature;

use App\Livewire\Super\Traffic\Index;
use App\Models\Hospital;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\TrafficEvent;
use App\Models\TrafficSession;
use App\Models\User;
use App\Services\TrafficRecorder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The traffic screen: whether its numbers are the numbers.
 *
 * The data is made the way production makes it — real requests through the
 * real middleware — so a test here fails if recording and reporting ever
 * stop agreeing about what a click, a visitor or a customer is.
 */
class TrafficDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private const SUFFIX = 'utm_source=google&utm_medium=cpc&utm_campaign=true-doctor-search&utm_content=rsa-hms';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(PlanSeeder::class);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['hospital_id' => null, 'role' => 'super_admin', 'is_admin' => true]);
    }

    /**
     * A browser with nothing about it yet. Laravel keeps cookies and headers
     * set with withCookie()/withHeader() for the rest of a test, so without
     * this every "visitor" after the first would carry the last one's cookie.
     */
    private function as(?string $visitor, string $agent = self::UA): static
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->flushHeaders();

        $this->withHeader('User-Agent', $agent);

        return $visitor === null ? $this : $this->withCookie(TrafficRecorder::COOKIE, $visitor);
    }

    /** One visitor clicking an ad and following the redirect, as a browser would. */
    private function click(string $visitor, string $query): void
    {
        $response = $this->as($visitor)->get('/?'.$query);

        if ($response->isRedirect()) {
            $this->as($visitor)->get((string) $response->headers->get('Location'));
        }
    }

    private function open(string $visitor, string $path): void
    {
        $this->as($visitor)->get($path);
    }

    private function signUp(string $visitor, string $name): Hospital
    {
        $plan = \App\Models\Plan::where('slug', 'starter')->firstOrFail();

        $this->as($visitor)->post('/register', [
            'hospital_name' => $name, 'name' => 'Owner', 'email' => Str::slug($name).'@dash.test',
            'password' => 'password1', 'password_confirmation' => 'password1', 'plan_id' => $plan->id,
        ])->assertRedirect();

        auth()->logout();

        return Hospital::where('name', $name)->firstOrFail();
    }

    private function pays(Hospital $hospital, string $amount): void
    {
        $subscription = Subscription::withoutGlobalScopes()->where('hospital_id', $hospital->id)->firstOrFail();

        SubscriptionPayment::create([
            'subscription_id' => $subscription->id, 'amount' => $amount,
            'method' => 'manual', 'reference' => 'T-'.$hospital->id, 'paid_at' => Carbon::now(),
        ]);
    }

    /** The story every test below reads back. */
    private function aWeekOfTraffic(): Hospital
    {
        // Two people click the Pharmacy sitelink; one reads on and signs up, then pays.
        $this->click('alice', 'section=modules&module=pharmacy&'.self::SUFFIX.'&gclid=CjALICE00001&keyword=pharmacy+software');
        $this->open('alice', '/pricing');
        $this->open('alice', '/register');
        $hospital = $this->signUp('alice', 'Alice Clinic');
        $this->pays($hospital, '350000');

        $this->click('bob', 'section=modules&module=pharmacy&'.self::SUFFIX.'&gclid=CjBOB0000001');

        // One clicks the Starter price asset and bounces.
        $this->click('carol', 'section=pricing&plan=starter&'.self::SUFFIX.'&gclid=CjCAROL00001');

        // One finds the site on their own.
        $this->as('dave')->withHeader('Referer', 'https://www.bing.com/search?q=hms')
            ->get('/');

        // And a crawler, which must not appear in any figure.
        $this->as(null, 'Googlebot/2.1')->get('/?'.self::SUFFIX.'&gclid=CjBOT0000001');

        return $hospital;
    }

    // ── Who may see it ───────────────────────────────────────────────────

    public function test_only_the_super_admin_sees_the_traffic_screen(): void
    {
        $this->get('/super/traffic')->assertRedirect(route('admin.login'));

        $hospitalAdmin = User::factory()->create(['hospital_id' => Hospital::factory()->create()->id, 'role' => 'hospital_admin']);
        $this->actingAs($hospitalAdmin)->get('/super/traffic')->assertForbidden();
    }

    public function test_the_screen_renders_with_real_traffic_in_it(): void
    {
        $this->aWeekOfTraffic();

        $this->actingAs($this->superAdmin())->get('/super/traffic')
            ->assertOk()
            ->assertSee('Pharmacy Management')
            ->assertSee('Starter Plan (price asset)')
            ->assertSee('From click to customer')
            ->assertSee('Landing URL health');
    }

    // ── The numbers ──────────────────────────────────────────────────────

    public function test_the_headline_counts_people_clicks_trials_and_customers(): void
    {
        $this->aWeekOfTraffic();
        $this->actingAs($this->superAdmin());

        $t = Livewire::test(Index::class)->instance()->totals;

        $this->assertSame(4, $t['visitors'], 'the crawler was counted, or somebody was lost');
        $this->assertSame(3, $t['clicks'], 'redirects or the crawler were counted as clicks');
        $this->assertSame(1, $t['trials']);
        $this->assertSame(1, $t['customers']);
        $this->assertSame(1, $t['form']);
        $this->assertSame(1, $t['bots']);
    }

    public function test_each_ad_asset_is_credited_with_what_it_produced(): void
    {
        $this->aWeekOfTraffic();
        $this->actingAs($this->superAdmin());

        $rows = collect(Livewire::test(Index::class)->instance()->byAsset)->keyBy('label');

        $pharmacy = $rows['Pharmacy Management'];
        $this->assertSame(2, $pharmacy['clicks']);
        $this->assertSame(2, $pharmacy['visitors']);
        $this->assertSame(1, $pharmacy['form']);
        $this->assertSame(1, $pharmacy['trials']);
        $this->assertSame(1, $pharmacy['customers']);
        $this->assertSame(50.0, $pharmacy['bounce'], 'bob left after one page; alice did not');

        $starter = $rows['Starter Plan (price asset)'];
        $this->assertSame(1, $starter['clicks']);
        $this->assertSame(0, $starter['trials']);

        // Sorted by what it produced, not by volume.
        $this->assertSame('Pharmacy Management', $rows->keys()->first());
    }

    public function test_keywords_and_ads_are_reported(): void
    {
        $this->aWeekOfTraffic();
        $this->actingAs($this->superAdmin());

        $component = Livewire::test(Index::class)->instance();

        $this->assertSame('pharmacy software', $component->byKeyword[0]['label']);
        $this->assertSame('rsa-hms', $component->byAd[0]['label']);
        $this->assertSame(3, $component->byAd[0]['clicks']);
    }

    public function test_the_channel_filter_separates_paid_from_everything_else(): void
    {
        $this->aWeekOfTraffic();
        $this->actingAs($this->superAdmin());

        $paid = Livewire::test(Index::class, ['channel' => 'paid'])->instance()->totals;
        $this->assertSame(3, $paid['visitors']);
        $this->assertSame(3, $paid['clicks']);

        $unpaid = Livewire::test(Index::class, ['channel' => 'unpaid'])->instance()->totals;
        $this->assertSame(1, $unpaid['visitors'], 'the visitor from bing');
        $this->assertSame(0, $unpaid['clicks']);

        // Nonsense in the URL is not an error.
        $this->assertSame('all', Livewire::test(Index::class, ['channel' => 'wat'])->get('channel'));
    }

    public function test_landing_url_health_shows_a_url_that_fails(): void
    {
        config()->set('demo.enabled', false);
        $this->click('erin', 'section=demo&'.self::SUFFIX.'&gclid=CjERIN000001');

        $this->actingAs($this->superAdmin());
        $health = collect(Livewire::test(Index::class)->instance()->health)->keyBy('path');

        $this->assertSame(1, $health['/test-login']['errors'], 'a 404 on the demo sitelink went unnoticed');
        $this->assertSame(1, $health['/']['redirects']);
    }

    public function test_a_visit_can_be_traced_by_its_click_id_whenever_it_was(): void
    {
        $this->aWeekOfTraffic();
        TrafficSession::query()->update(['first_seen_at' => Carbon::now()->subYear()]);

        $this->actingAs($this->superAdmin());

        $found = Livewire::test(Index::class)->set('search', 'CjBOB0000001')->instance()->recent;
        $this->assertCount(1, $found);

        $byHospital = Livewire::test(Index::class)->set('search', 'Alice')->instance()->recent;
        $this->assertCount(1, $byHospital);
        $this->assertNotNull($byHospital->first()->converted_hospital_id);
    }

    public function test_the_trail_shows_everything_one_visitor_did(): void
    {
        $this->aWeekOfTraffic();
        $this->actingAs($this->superAdmin());

        $alice = TrafficSession::where('gclid', 'CjALICE00001')->sole();

        Livewire::test(Index::class)
            ->call('showTrail', $alice->id)
            ->assertSee($alice->uuid)
            ->assertSee('/features#pharmacy')
            ->assertSee('signed up');
    }

    // ── Back to Google ───────────────────────────────────────────────────

    private function exported(): string
    {
        $this->actingAs($this->superAdmin());

        $response = Livewire::test(Index::class)->instance()->exportConversions();

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    public function test_the_export_carries_the_trial_and_the_payment(): void
    {
        $hospital = $this->aWeekOfTraffic();

        $lines = array_values(array_filter(explode("\n", $this->exported())));

        $this->assertSame('"Google Click ID","Conversion Name","Conversion Time","Conversion Value","Conversion Currency"', $lines[0]);
        $this->assertCount(3, $lines, 'expected a header, one trial and one payment');
        $this->assertStringStartsWith('CjALICE00001,"Trial signup",', $lines[1]);
        $this->assertStringStartsWith('CjALICE00001,"Paid subscription",', $lines[2]);
        $this->assertStringContainsString(',350000.00,UGX', $lines[2]);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}/', $lines[1]);
    }

    /**
     * A gclid is whatever somebody typed into a URL. One shaped like a
     * spreadsheet formula must not reach the file that gets opened in one.
     */
    public function test_a_click_id_that_is_not_a_click_id_is_never_exported(): void
    {
        Hospital::factory()->create(['gclid' => '=HYPERLINK("http://evil.test","x")', 'attributed_at' => Carbon::now()]);
        Hospital::factory()->create(['gclid' => 'CjGOODCLICK01', 'attributed_at' => Carbon::now()]);

        $csv = $this->exported();

        $this->assertStringNotContainsString('HYPERLINK', $csv);
        $this->assertStringContainsString('CjGOODCLICK01', $csv);
    }

    // ── What is kept, and for how long ───────────────────────────────────

    public function test_retention_keeps_what_became_a_hospital(): void
    {
        $hospital = $this->aWeekOfTraffic();

        $old = Carbon::now()->subDays(TrafficEvent::KEEP_DAYS + 5);
        TrafficSession::query()->update(['last_seen_at' => $old]);
        TrafficEvent::query()->update(['created_at' => $old]);

        $this->artisan('model:prune', ['--model' => [TrafficEvent::class, TrafficSession::class]])->assertSuccessful();

        $left = TrafficSession::sole();
        $this->assertSame($hospital->id, $left->converted_hospital_id, 'the visit that earned a hospital was pruned');
        $this->assertGreaterThan(0, $left->events()->count(), 'the path to a customer was pruned');
        $this->assertSame(0, TrafficEvent::where('traffic_session_id', '!=', $left->id)->count());
    }

    public function test_crawlers_go_sooner(): void
    {
        $this->as(null, 'Googlebot/2.1')->get('/');
        $this->as('recent-person')->get('/');

        TrafficSession::query()->update(['last_seen_at' => Carbon::now()->subDays(TrafficSession::KEEP_BOT_DAYS + 1)]);

        $this->artisan('model:prune', ['--model' => [TrafficSession::class]])->assertSuccessful();

        $this->assertFalse(TrafficSession::sole()->is_bot, 'the crawler outlived its 90 days, or the person was pruned early');
    }
}
