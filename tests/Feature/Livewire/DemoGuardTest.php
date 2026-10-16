<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Shell\ProfileModal;
use App\Livewire\Users\Index;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** The demonstration's logins are public: the web panel must not let a visitor change them. */
class DemoGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(RbacSeeder::class);
        $this->seed(PlanSeeder::class);
        $this->seed(DemoSeeder::class);
    }

    public function test_a_demo_visitor_cannot_change_the_password_in_the_profile_modal(): void
    {
        $doctor = User::where('email', 'doctor.a@test.com')->firstOrFail();
        $before = $doctor->password;

        Livewire::actingAs($doctor)->test(ProfileModal::class)
            ->set('current_password', 'password1')
            ->set('password', 'Brand-new-pass-77')
            ->set('password_confirmation', 'Brand-new-pass-77')
            ->call('savePassword')
            ->assertDispatched('toast');

        $this->assertSame($before, $doctor->fresh()->password);
        $this->assertTrue(Hash::check('password1', $doctor->fresh()->password));
    }

    public function test_a_demo_admin_cannot_change_a_demo_accounts_password_email_or_role(): void
    {
        $admin = User::where('email', 'admin.a@test.com')->firstOrFail();
        $nurse = User::where('email', 'nurse.a@test.com')->firstOrFail();
        $component = Livewire::actingAs($admin)->test(Index::class)->call('edit', $nurse->id);

        $component->set('password', 'Brand-new-pass-77')->set('password_confirmation', 'Brand-new-pass-77')->call('save');
        $this->assertTrue(Hash::check('password1', $nurse->fresh()->password));

        $component->set('password', null)->set('password_confirmation', null)->set('email', 'x@test.com')->call('save');
        $this->assertSame('nurse.a@test.com', $nurse->fresh()->email);
    }
}
