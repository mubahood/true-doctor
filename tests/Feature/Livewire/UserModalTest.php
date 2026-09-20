<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Users\Index;
use App\Mail\WelcomeCredentials;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class UserModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function actingAdmin(Hospital $h): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_modal_creates_user_and_emails_credentials(): void
    {
        Mail::fake();
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'New Nurse')
            ->set('email', 'nurse@example.com')
            ->set('role', 'nurse')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $user = User::where('email', 'nurse@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame($h->id, $user->hospital_id);
        $this->assertTrue((bool) $user->password_change_required);
        Mail::assertQueued(WelcomeCredentials::class);
    }

    public function test_modal_validates_unique_email(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        User::factory()->create(['hospital_id' => $h->id, 'email' => 'taken@example.com']);

        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'X')
            ->set('email', 'taken@example.com')
            ->set('role', 'nurse')
            ->call('save')
            ->assertHasErrors('email');
    }

    public function test_modal_edits_user_and_optional_password_reset(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $target = User::factory()->create(['hospital_id' => $h->id, 'name' => 'Old', 'role' => 'nurse']);

        Livewire::test(Index::class)
            ->call('edit', $target->id)
            ->assertSet('name', 'Old')
            ->set('name', 'Renamed')
            ->set('password', 'NewPass123')
            ->set('password_confirmation', 'NewPass123')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Renamed', $target->fresh()->name);
        $this->assertTrue((bool) $target->fresh()->password_change_required);
    }

    public function test_cannot_delete_own_account(): void
    {
        $h = Hospital::factory()->create();
        $admin = $this->actingAdmin($h);

        Livewire::test(Index::class)->call('delete', $admin->id);

        $this->assertNotNull(User::find($admin->id));
    }

    public function test_cannot_edit_another_hospitals_user(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $this->actingAdmin($a);
        $userB = User::factory()->create(['hospital_id' => $b->id]);

        // Outside the actor's manageable scope the row does not exist at all.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        try {
            Livewire::test(Index::class)->call('edit', $userB->id);
        } finally {
            $this->assertNotNull(User::find($userB->id));
        }
    }

    public function test_avatar_must_be_an_allowed_image_type(): void
    {
        Mail::fake();
        Storage::fake('public');
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);

        // config('livewire.temporary_file_upload.rules') still accepts a PDF —
        // the component's own mimes rule is what keeps it off a user record.
        Livewire::test(Index::class)
            ->call('create')
            ->set('name', 'Avatar Nurse')
            ->set('email', 'avatar@example.com')
            ->set('role', 'nurse')
            ->set('avatar', UploadedFile::fake()->create('payload.pdf', 8, 'application/pdf'))
            ->call('save')
            ->assertHasErrors('avatar');

        $this->assertDatabaseMissing('users', ['email' => 'avatar@example.com']);
    }

    public function test_avatar_upload_is_stored_and_replaced_on_update(): void
    {
        Mail::fake();
        Storage::fake('public');
        $h = Hospital::factory()->create();
        $this->actingAdmin($h);
        $target = User::factory()->create(['hospital_id' => $h->id, 'role' => 'nurse']);

        Livewire::test(Index::class)
            ->call('edit', $target->id)
            ->set('avatar', UploadedFile::fake()->image('first.jpg'))
            ->call('save')
            ->assertHasNoErrors();

        $first = $target->fresh()->avatar;
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);

        Livewire::test(Index::class)
            ->call('edit', $target->id)
            ->set('avatar', UploadedFile::fake()->image('second.jpg'))
            ->call('save')
            ->assertHasNoErrors();

        $second = $target->fresh()->avatar;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);   // old file dropped only after a successful update
    }

    public function test_forbidden_without_manage_users(): void
    {
        $h = Hospital::factory()->create();
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => 'nurse']);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        Livewire::test(Index::class)->assertForbidden();
    }
}
