<?php

namespace Tests\Feature\Api;

use App\Models\Department;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Subscription (SaaS), Licence & updates (standalone) and the setup checklist, over the API. */
class AdminAccountStateApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create();
    }

    protected function tearDown(): void
    {
        if (isset($this->marker)) {
            @unlink($this->marker);
        }
        parent::tearDown();
    }

    private string $marker;

    /** A standalone installation: the edition setting and the installed marker that must agree with it. */
    private function standalone(): void
    {
        $this->marker = storage_path('framework/testing/installed-admin-'.bin2hex(random_bytes(4)).'.json');
        file_put_contents($this->marker, json_encode(['edition' => 'standalone', 'version' => '1.0.0']));
        config()->set('edition.name', 'standalone');
        config()->set('edition.installed_path', $this->marker);
    }

    private function as(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();
        Sanctum::actingAs($u);

        return $u;
    }

    // ── Subscription (SaaS) ─────────────────────────────────────

    public function test_the_subscription_shows_the_plan_the_plans_on_offer_the_payments_and_where_to_pay(): void
    {
        $starter = Plan::factory()->create(['name' => 'Starter', 'price' => 100000, 'limits' => ['max_staff' => 5], 'is_active' => true]);
        $pro = Plan::factory()->create(['name' => 'Pro', 'price' => 300000, 'is_active' => true]);
        Plan::factory()->create(['name' => 'Retired', 'is_active' => false]);
        $sub = Subscription::factory()->create(['hospital_id' => $this->h->id, 'plan_id' => $starter->id]);
        SubscriptionPayment::factory()->create(['subscription_id' => $sub->id, 'amount' => 100000, 'reference' => 'REF-1', 'notes' => '1 month']);
        // Another hospital's payment must not appear.
        $foreign = Subscription::factory()->create(['plan_id' => $pro->id]);
        SubscriptionPayment::factory()->create(['subscription_id' => $foreign->id, 'reference' => 'FOREIGN']);
        $this->as('hospital_admin');

        $res = $this->getJson('/api/v1/admin/subscription')->assertOk()
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.plan.name', 'Starter')
            ->assertJsonPath('data.subscription.plan.limits.max_staff', 5)
            ->assertJsonPath('data.subscription.grants_access', true)
            ->assertJsonPath('data.subscription.is_blocked', false)
            ->assertJsonPath('data.subscription.paid_plan_id', $starter->id)
            ->assertJsonPath('data.pay_url', route('admin.subscription.index'));

        $this->assertGreaterThanOrEqual(24, $res->json('data.subscription.days_remaining'));
        $plans = collect($res->json('data.plans'));
        $this->assertSame(['Starter', 'Pro'], $plans->pluck('name')->all());
        $this->assertTrue($plans->firstWhere('name', 'Starter')['is_current']);
        $this->assertFalse($plans->firstWhere('name', 'Pro')['is_current']);
        $this->assertSame(['REF-1'], collect($res->json('data.payments'))->pluck('reference')->all());
        $this->assertNotNull($pro);
    }

    public function test_an_ended_subscription_can_still_read_its_own_page_while_everything_else_is_refused(): void
    {
        Plan::factory()->create(['is_active' => true]);
        config(['tenancy.enforce_subscription' => true]);
        Subscription::factory()->expired()->create(['hospital_id' => $this->h->id]);
        $this->as('hospital_admin');

        $this->getJson('/api/v1/admin/subscription')->assertOk()
            ->assertJsonPath('data.subscription.grants_access', false)->assertJsonPath('data.subscription.is_blocked', true)
            ->assertJsonPath('data.subscription.status', 'expired');
        $this->getJson('/api/v1/admin/departments')->assertStatus(403)->assertJsonPath('code', 'subscription_ended');
    }

    public function test_the_subscription_is_for_the_owner_only_and_is_saas_only(): void
    {
        $this->as('nurse');
        $this->getJson('/api/v1/admin/subscription')->assertStatus(403);

        $this->as('hospital_admin');
        $this->standalone();
        $this->getJson('/api/v1/admin/subscription')->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    // ── Licence & updates (standalone) ──────────────────────────

    public function test_licence_and_updates_is_standalone_only_and_manage_settings_only(): void
    {
        $this->as('hospital_admin');
        $this->getJson('/api/v1/admin/system')->assertStatus(404)->assertJsonPath('code', 'not_found');

        $this->standalone();
        $this->getJson('/api/v1/admin/system')->assertOk()
            ->assertJsonPath('data.edition', 'standalone')
            ->assertJsonPath('data.licence.valid', false)
            ->assertJsonPath('data.update.available', false)
            ->assertJsonStructure(['data' => ['version', 'licence' => ['id', 'holder', 'updates_until', 'address_matches'], 'update' => ['status', 'web_url'], 'health' => ['scheduler_alive', 'queued_jobs'], 'backups']]);

        $this->as('nurse');
        $this->getJson('/api/v1/admin/system')->assertStatus(403);
    }

    public function test_a_known_newer_release_is_reported_but_never_installed_from_here(): void
    {
        $this->standalone();
        \Illuminate\Support\Facades\Cache::put('standalone:update-check', [
            'checked_at' => now()->toIso8601String(),
            'latest' => ['version' => '99.0.0', 'notes' => 'Big one', 'published_at' => '2026-10-01', 'size' => 1234, 'sha256' => 'x', 'download_url' => 'https://example.test/x.zip'],
        ], now()->addDay());
        $this->as('hospital_admin');

        $res = $this->getJson('/api/v1/admin/system')->assertOk()
            ->assertJsonPath('data.update.available', true)->assertJsonPath('data.update.latest.version', '99.0.0');
        // No download link or checksum is handed out: updating stays on the web.
        $this->assertStringNotContainsString('example.test', $res->getContent());
        $this->postJson('/api/v1/admin/system')->assertStatus(405);
    }

    // ── Onboarding checklist ────────────────────────────────────

    public function test_the_checklist_lists_steps_with_done_and_required_and_follows_the_data(): void
    {
        $this->as('hospital_admin');

        $res = $this->getJson('/api/v1/admin/onboarding')->assertOk()
            ->assertJsonPath('data.complete', false)->assertJsonPath('data.can_configure', true)->assertJsonPath('data.next_step', 'practice');
        $steps = collect($res->json('data.steps'))->keyBy('key');
        foreach (['practice', 'profile', 'billing', 'departments', 'services', 'staff'] as $key) {
            $this->assertTrue($steps[$key]['required'], $key);
        }
        $this->assertFalse($steps['departments']['done']);
        $this->assertContains('departments', $res->json('data.blocking'));

        Department::factory()->create(['hospital_id' => $this->h->id, 'is_active' => true]);
        $after = collect($this->getJson('/api/v1/admin/onboarding')->json('data.steps'))->keyBy('key');
        $this->assertTrue($after['departments']['done']);
        $this->assertSame('Done', $after['departments']['status']);
    }

    public function test_the_checklist_is_readable_by_staff_but_not_actionable_and_is_per_hospital(): void
    {
        $other = Hospital::factory()->create();
        Department::factory()->create(['hospital_id' => $other->id, 'is_active' => true]);
        $this->as('nurse');

        $res = $this->getJson('/api/v1/admin/onboarding')->assertOk()->assertJsonPath('data.can_configure', false);
        $done = collect($res->json('data.steps'))->keyBy('key')['departments']['done'];
        $this->assertFalse($done, 'another hospital\'s department must not tick this hospital\'s step');
    }
}
