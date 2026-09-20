<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Shell\NotificationBell;
use App\Livewire\Shell\ProfileModal;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ShellComponentsTest extends TestCase
{
    use RefreshDatabase;

    private function doctor(): User
    {
        $this->seed(RbacSeeder::class);
        $h = Hospital::factory()->create();
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'doctor', 'password' => Hash::make('Secret123')]);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_bell_shows_unread_count_and_refreshes_on_event(): void
    {
        $u = $this->doctor();
        \Illuminate\Notifications\DatabaseNotification::create([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'test',
            'notifiable_type' => User::class, 'notifiable_id' => $u->id, 'data' => ['title' => 'Hello'],
        ]);

        $c = Livewire::test(NotificationBell::class)->assertSee('1');
        $u->unreadNotifications()->update(['read_at' => now()]);
        $c->dispatch('notifications-read')->assertDontSee('tb-bell-badge');
    }

    public function test_profile_modal_updates_name_phone_bio(): void
    {
        $u = $this->doctor();

        Livewire::test(ProfileModal::class)
            ->dispatch('open-profile')
            ->assertSet('showProfile', true)
            ->set('name', 'Dr. Updated')
            ->set('phone', '0700000000')
            ->set('bio', 'Cardiology')
            ->call('saveProfile')
            ->assertHasNoErrors()
            ->assertSet('showProfile', false)
            ->assertDispatched('toast');

        $this->assertSame('Dr. Updated', $u->fresh()->name);
        $this->assertSame('0700000000', $u->fresh()->phone);
    }

    public function test_profile_modal_stores_avatar(): void
    {
        Storage::fake('public');
        $u = $this->doctor();

        Livewire::test(ProfileModal::class)
            ->dispatch('open-profile')
            ->set('avatar', UploadedFile::fake()->image('me.png', 300, 300))
            ->call('saveProfile')
            ->assertHasNoErrors();

        $this->assertNotNull($u->fresh()->avatar);
        Storage::disk('public')->assertExists($u->fresh()->avatar);
    }

    public function test_password_change_requires_current_password_and_policy(): void
    {
        $u = $this->doctor();

        Livewire::test(ProfileModal::class)
            ->dispatch('open-password')
            ->set('current_password', 'wrong')
            ->set('password', 'NewSecret123')
            ->set('password_confirmation', 'NewSecret123')
            ->call('savePassword')
            ->assertHasErrors(['current_password']);

        Livewire::test(ProfileModal::class)
            ->dispatch('open-password')
            ->set('current_password', 'Secret123')
            ->set('password', 'abc')
            ->set('password_confirmation', 'abc')
            ->call('savePassword')
            ->assertHasErrors(['password']);

        Livewire::test(ProfileModal::class)
            ->dispatch('open-password')
            ->set('current_password', 'Secret123')
            ->set('password', 'NewSecret123')
            ->set('password_confirmation', 'NewSecret123')
            ->call('savePassword')
            ->assertHasNoErrors()
            ->assertSet('showPassword', false);

        $this->assertTrue(Hash::check('NewSecret123', $u->fresh()->password));
    }

    public function test_admin_layout_renders_persisted_shell_and_server_title(): void
    {
        $this->doctor();

        $this->get(route('admin.departments.index'))
            ->assertOk()
            ->assertSee('<title>Departments · True-Doctor</title>', false)
            ->assertSee('x-persist="sidebar"', false)
            ->assertSee('x-persist="toasts"', false)
            ->assertDontSee('chart.min.js')
            ->assertDontSee('CX-DIAG');
    }
}
