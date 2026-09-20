<?php

namespace Tests\Feature\Offline;

use App\Livewire\Sync\Index as DeviceDashboard;
use App\Models\Device;
use App\Models\Hospital;
use App\Models\SyncOperation;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The administrator's view of who holds a copy of the hospital's records.
 *
 * The two properties that matter: a device can be taken away, and nothing on
 * this screen exposes what was in any operation — a clinical record is
 * readable by whoever may see the patient, not by whoever may see a
 * diagnostics page.
 */
class DeviceDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->admin = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'hospital_admin']);
        $this->admin->syncSpatieRole();
        $this->actingAs($this->admin);
    }

    private function device(array $attrs = []): Device
    {
        return Device::create(array_merge([
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->admin->id,
            'device_uuid' => (string) Str::uuid(),
            'label' => 'Ward tablet',
            'registered_at' => now(),
            'last_seen_at' => now(),
        ], $attrs));
    }

    public function test_the_list_shows_every_machine_holding_a_copy(): void
    {
        $this->device(['label' => 'Maternity desk laptop']);
        $this->device(['label' => 'Theatre tablet']);

        Livewire::test(DeviceDashboard::class)
            ->assertOk()
            ->assertSee('Maternity desk laptop')
            ->assertSee('Theatre tablet');
    }

    public function test_it_counts_what_an_administrator_needs_to_act_on(): void
    {
        $this->device();
        $this->device(['revoked_at' => now(), 'label' => 'Lost laptop']);
        $stale = $this->device(['label' => 'Forgotten tablet']);
        DB::table('devices')->where('id', $stale->id)->update(['last_seen_at' => now()->subDays(60)]);

        $figures = Livewire::test(DeviceDashboard::class)->instance()->figures();

        $this->assertSame(2, $figures['devices']);   // not the revoked one
        $this->assertSame(1, $figures['revoked']);
        $this->assertSame(1, $figures['stale']);
    }

    public function test_a_device_is_blocked_with_a_reason_the_holder_will_read(): void
    {
        $device = $this->device(['label' => 'Lost laptop']);

        Livewire::test(DeviceDashboard::class)
            ->call('openRevoke', $device->id)
            ->assertSet('showRevoke', true)
            // The quick view steps aside rather than stacking under it.
            ->assertSet('showPeek', false)
            ->set('revoke_reason', 'Reported lost on 19 September.')
            ->call('revoke')
            ->assertHasNoErrors();

        $device->refresh();

        $this->assertNotNull($device->revoked_at);
        $this->assertSame($this->admin->id, $device->revoked_by);
        $this->assertSame('Reported lost on 19 September.', $device->revoked_reason);
        $this->assertDatabaseHas('activity_log', ['description' => 'offline device revoked']);
    }

    /**
     * A block with no explanation is how a clinician concludes the system is
     * broken and starts writing on paper.
     */
    public function test_blocking_a_device_requires_a_reason(): void
    {
        $device = $this->device();

        Livewire::test(DeviceDashboard::class)
            ->call('openRevoke', $device->id)
            ->call('revoke')
            ->assertHasErrors(['revoke_reason']);

        $this->assertNull($device->fresh()->revoked_at);
    }

    public function test_a_found_laptop_can_be_allowed_back(): void
    {
        $device = $this->device(['revoked_at' => now(), 'revoked_reason' => 'Lost']);

        Livewire::test(DeviceDashboard::class)->call('restore', $device->id);

        $this->assertNull($device->fresh()->revoked_at);
        $this->assertNull($device->fresh()->revoked_reason);
        $this->assertDatabaseHas('activity_log', ['description' => 'offline device restored']);
    }

    public function test_the_quick_view_shows_what_a_device_did_but_never_what_was_in_it(): void
    {
        $device = $this->device();

        SyncOperation::create([
            'operation_id' => strtoupper((string) Str::ulid()),
            'hospital_id' => $this->hospital->id,
            'user_id' => $this->admin->id,
            'device_id' => $device->id,
            'entity' => 'vitals',
            'entity_uuid' => (string) Str::uuid(),
            'operation' => 'create',
            'status' => 'accepted',
            'payload_hash' => hash('sha256', 'a confidential clinical note'),
        ]);

        $component = Livewire::test(DeviceDashboard::class)
            ->call('peek', $device->id)
            ->assertSet('showPeek', true)
            ->assertSee('Operations sent')
            ->assertSee('vitals')
            ->assertSee('accepted');

        // The ledger never stored the payload, so there is nothing to leak —
        // and the dialog says so out loud.
        $component->assertSee('only a fingerprint of it');
        $this->assertStringNotContainsString('confidential', $component->html());
    }

    public function test_another_hospitals_devices_are_never_listed(): void
    {
        $theirs = Hospital::factory()->create();
        $theirUser = User::factory()->create(['hospital_id' => $theirs->id, 'role' => 'nurse']);

        app(CurrentHospital::class)->set($theirs->id);
        Device::create([
            'hospital_id' => $theirs->id, 'user_id' => $theirUser->id,
            'device_uuid' => (string) Str::uuid(), 'label' => 'TheirLaptop', 'registered_at' => now(),
        ]);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->device(['label' => 'OurLaptop']);

        Livewire::test(DeviceDashboard::class)
            ->assertSee('OurLaptop')
            ->assertDontSee('TheirLaptop');
    }

    public function test_another_hospitals_device_cannot_be_blocked(): void
    {
        $theirs = Hospital::factory()->create();
        $theirUser = User::factory()->create(['hospital_id' => $theirs->id, 'role' => 'nurse']);

        app(CurrentHospital::class)->set($theirs->id);
        $theirDevice = Device::create([
            'hospital_id' => $theirs->id, 'user_id' => $theirUser->id,
            'device_uuid' => (string) Str::uuid(), 'label' => 'Theirs', 'registered_at' => now(),
        ]);
        app(CurrentHospital::class)->set($this->hospital->id);

        // The global scope makes it a 404, not a 403 — the same shape every
        // other cross-tenant lookup in this system has.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(DeviceDashboard::class)
            ->set('revokingId', $theirDevice->id)
            ->set('revoke_reason', 'Trying it on')
            ->call('revoke');
    }

    public function test_a_clinician_cannot_see_the_device_list(): void
    {
        $nurse = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();
        $this->actingAs($nurse);

        Livewire::test(DeviceDashboard::class)->assertForbidden();
    }

    public function test_the_page_loads_over_http_for_an_administrator(): void
    {
        $this->device();

        $this->get(route('admin.offline-devices.index'))
            ->assertOk()
            ->assertSee('Offline devices');
    }
}
