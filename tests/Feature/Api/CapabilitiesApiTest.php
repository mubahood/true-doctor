<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\User;
use App\Support\Capabilities;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** meta.capabilities: what this server can do for this user (docs/api/plane.md). */
class CapabilitiesApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->hospital = Hospital::factory()->create(['timezone' => 'Africa/Kampala']);
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    private function as(string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => $role, 'is_active' => true]);
        $u->syncSpatieRole();
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_meta_carries_the_capabilities_block_and_keeps_every_old_key(): void
    {
        $this->as('doctor');

        $data = $this->getJson('/api/v1/meta')->assertOk()->json('data');

        foreach (['api_version', 'edition', 'version', 'sync_protocol', 'server_time', 'web_url', 'app', 'user', 'hospital', 'subscription', 'setup_required', 'navigation', 'enums', 'options'] as $key) {
            $this->assertArrayHasKey($key, $data, $key);
        }

        $c = $data['capabilities'];
        $this->assertSame(1, $c['api_version']);
        $this->assertSame(1, $c['min_api_version']);
        $this->assertNull($c['deprecated_after']);
        $this->assertSame('saas', $c['edition']);
        $this->assertSame('general_hospital', $c['practice_kind']);
        $this->assertSame('Africa/Kampala', $c['timezone']);
        $this->assertSame(['en', 'lg', 'sw'], $c['locales']);
        $this->assertNotEmpty($c['server_time']);
        $this->assertNull($c['licence']);
    }

    public function test_the_feature_flags_are_exactly_these(): void
    {
        $this->as('doctor');

        $this->assertSame(
            ['search' => true, 'etag' => true, 'tombstones' => true, 'bootstrap' => true, 'agenda' => true, 'push' => false, 'attachments' => false, 'batch' => false],
            $this->getJson('/api/v1/meta')->json('data.capabilities.features'),
        );
    }

    public function test_push_is_only_on_when_a_real_driver_is_configured(): void
    {
        $this->as('doctor');
        config(['push.driver' => 'fcm']);

        $this->assertTrue($this->getJson('/api/v1/meta')->json('data.capabilities.features.push'));
    }

    public function test_limits_come_from_the_server(): void
    {
        $this->as('doctor');

        $limits = $this->getJson('/api/v1/meta')->json('data.capabilities.limits');

        $this->assertSame(100, $limits['per_page_max']);
        $this->assertSame(240, $limits['rate_per_min']);
        $this->assertSame(15, $limits['idle_timeout_minutes']);
        $this->assertSame(Capabilities::uploadMaxKb(), $limits['upload_max_kb']);
        $this->assertGreaterThan(0, $limits['upload_max_kb']);
    }

    public function test_upload_max_is_the_smaller_of_post_max_and_upload_max_filesize(): void
    {
        $this->assertSame(8 * 1024, intdiv(min(Capabilities::bytes('8M'), Capabilities::bytes('64M')), 1024));
        $this->assertSame(2 * 1024 * 1024, intdiv(Capabilities::bytes('2G'), 1024));
        $this->assertSame(512, intdiv(Capabilities::bytes('512K'), 1024));
        $this->assertSame(1024, Capabilities::bytes('1024'));
        $this->assertSame(0, Capabilities::bytes('-1'));
        $this->assertSame(0, Capabilities::bytes('junk'));
    }

    public function test_permissions_are_the_users_effective_permissions_and_differ_by_role(): void
    {
        $this->as('receptionist');
        $reception = $this->getJson('/api/v1/meta')->json('data.capabilities.permissions');
        $this->assertContains('patients.create', $reception);
        $this->assertNotContains('pharmacy.view', $reception);

        $this->app['auth']->forgetGuards();
        $this->as('pharmacist');
        $pharmacy = $this->getJson('/api/v1/meta')->json('data.capabilities.permissions');
        $this->assertContains('pharmacy.view', $pharmacy);
        $this->assertNotContains('patients.create', $pharmacy);
    }

    public function test_modules_follow_the_practice(): void
    {
        $this->as('doctor');
        $this->hospital->update(['settings' => ['practice' => ['sector' => 'dental_clinic']]]);

        $c = $this->getJson('/api/v1/meta')->json('data.capabilities');

        $this->assertSame('dental_clinic', $c['practice_kind']);
        $this->assertFalse($c['modules']['inpatient']);
        $this->assertArrayHasKey('laboratory', $c['modules']);
    }

    public function test_a_standalone_copy_reports_its_licence(): void
    {
        $marker = storage_path('framework/testing/installed-caps-'.bin2hex(random_bytes(4)).'.json');
        file_put_contents($marker, json_encode(['edition' => 'standalone', 'version' => '1.0.0']));
        config()->set('edition.name', 'standalone');
        config()->set('edition.installed_path', $marker);
        config()->set('edition.licence_path', storage_path('framework/testing/none-'.bin2hex(random_bytes(4)).'.json'));
        $this->as('hospital_admin');

        $c = $this->getJson('/api/v1/meta')->assertOk()->json('data.capabilities');
        @unlink($marker);

        $this->assertSame('standalone', $c['edition']);
        $this->assertSame(false, $c['licence']['valid']);
        $this->assertArrayHasKey('updates_until', $c['licence']);
    }
}
