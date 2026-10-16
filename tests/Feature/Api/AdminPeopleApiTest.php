<?php

namespace Tests\Feature\Api;

use App\Mail\WelcomeCredentials;
use App\Models\Department;
use App\Models\Device;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\StaffProfile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** User accounts, staff profiles, roles and offline devices over the API. */
class AdminPeopleApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private Hospital $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create();
        $this->other = Hospital::factory()->create();
    }

    private function user(string $role, ?Hospital $h = null, array $extra = []): User
    {
        $u = User::factory()->create($extra + ['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function as(string $role, ?Hospital $h = null): User
    {
        $u = $this->user($role, $h);
        Sanctum::actingAs($u);

        return $u;
    }

    // ── Users ───────────────────────────────────────────────────

    public function test_listing_is_scoped_filterable_and_never_carries_a_password(): void
    {
        $admin = $this->as('hospital_admin');
        $this->user('doctor', null, ['name' => 'Dr Able']);
        $this->user('nurse', null, ['name' => 'Nurse Off', 'is_active' => false]);
        $this->user('doctor', $this->other, ['name' => 'Dr Foreign']);

        $res = $this->getJson('/api/v1/admin/users')->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertStringNotContainsString('"password"', json_encode($res->json('data')));
        $this->assertStringNotContainsString('Dr Foreign', json_encode($res->json('data')));

        $this->assertSame(['Dr Able'], collect($this->getJson('/api/v1/admin/users?role=doctor')->json('data'))->pluck('name')->all());
        $this->assertSame(['Nurse Off'], collect($this->getJson('/api/v1/admin/users?status=inactive')->json('data'))->pluck('name')->all());
        $this->assertSame([$admin->name], collect($this->getJson('/api/v1/admin/users?q='.urlencode($admin->email))->json('data'))->pluck('name')->all());
    }

    public function test_only_manage_users_may_see_accounts(): void
    {
        $this->as('nurse');
        $this->getJson('/api/v1/admin/users')->assertStatus(403);
        $this->getJson('/api/v1/admin/roles')->assertStatus(403);
        $this->postJson('/api/v1/admin/users', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'nurse'])->assertStatus(403);
    }

    public function test_creating_an_account_gives_a_temporary_password_by_email_and_never_in_the_response(): void
    {
        Mail::fake();
        $this->as('hospital_admin');

        $res = $this->postJson('/api/v1/admin/users', ['name' => 'New Nurse', 'email' => 'new.nurse@example.com', 'role' => 'nurse', 'phone' => '0700'])
            ->assertCreated()->assertJsonPath('data.password_change_required', true)->assertJsonPath('data.role_label', 'Nurse');
        $this->assertStringNotContainsString('password"', json_encode($res->json('data')));

        $created = User::where('email', 'new.nurse@example.com')->first();
        $this->assertSame($this->h->id, $created->hospital_id);
        $this->assertTrue($created->hasRole('nurse'));
        $this->assertTrue($created->password_change_required);
        Mail::assertQueued(WelcomeCredentials::class);
    }

    public function test_a_hospital_admin_cannot_mint_a_super_admin_or_reuse_an_email(): void
    {
        Mail::fake();
        $admin = $this->as('hospital_admin');

        $this->postJson('/api/v1/admin/users', ['name' => 'Root', 'email' => 'root@example.com', 'role' => 'super_admin'])
            ->assertStatus(422)->assertJsonValidationErrors('role', 'errors');
        $this->postJson('/api/v1/admin/users', ['name' => 'Dup', 'email' => $admin->email, 'role' => 'nurse'])
            ->assertStatus(422)->assertJsonValidationErrors('email', 'errors');
        $this->assertFalse(User::where('email', 'root@example.com')->exists());
    }

    public function test_the_plan_staff_limit_is_enforced(): void
    {
        Mail::fake();
        $plan = Plan::factory()->create(['limits' => ['max_staff' => 1]]);
        Subscription::factory()->create(['hospital_id' => $this->h->id, 'plan_id' => $plan->id]);
        $this->as('hospital_admin');   // fills the single seat

        $this->postJson('/api/v1/admin/users', ['name' => 'Over', 'email' => 'over@example.com', 'role' => 'nurse'])
            ->assertStatus(403)->assertJsonPath('code', 'plan_limit');
        $this->assertFalse(User::where('email', 'over@example.com')->exists());
    }

    public function test_updating_assigning_a_role_and_the_also_sees_patients_flag(): void
    {
        $this->as('hospital_admin');
        $target = $this->user('receptionist');

        $this->putJson("/api/v1/admin/users/{$target->id}", ['role' => 'accountant', 'sees_patients' => true])
            ->assertOk()->assertJsonPath('data.role', 'accountant')->assertJsonPath('data.sees_patients', true)
            ->assertJsonPath('data.email', $target->email);
        $this->assertTrue($target->fresh()->hasRole('accountant'));

        // A doctor sees patients by being one; the flag is for everybody else.
        $this->putJson("/api/v1/admin/users/{$target->id}", ['role' => 'doctor', 'sees_patients' => true])->assertOk()->assertJsonPath('data.sees_patients', false);

        $this->getJson("/api/v1/admin/users/{$target->id}")->assertOk()->assertJsonStructure(['data' => ['abilities', 'profile']]);
    }

    public function test_deactivate_activate_and_the_account_actually_loses_access(): void
    {
        $admin = $this->as('hospital_admin');
        $target = $this->user('nurse');

        $this->postJson("/api/v1/admin/users/{$target->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertFalse($target->fresh()->is_active);
        $this->postJson("/api/v1/admin/users/{$target->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);

        // Not the account you are signed in with.
        $this->postJson("/api/v1/admin/users/{$admin->id}/deactivate")->assertStatus(422);
        $this->putJson("/api/v1/admin/users/{$admin->id}", ['is_active' => false])->assertStatus(422);
        $this->deleteJson("/api/v1/admin/users/{$admin->id}")->assertStatus(422);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_an_administrators_reset_forces_a_new_password_at_next_sign_in(): void
    {
        $this->as('hospital_admin');
        $target = $this->user('nurse', null, ['password_change_required' => false]);

        $this->postJson("/api/v1/admin/users/{$target->id}/reset-password", ['password' => 'Tempor4ry-pass', 'password_confirmation' => 'different'])->assertStatus(422);
        $res = $this->postJson("/api/v1/admin/users/{$target->id}/reset-password", ['password' => 'Tempor4ry-pass', 'password_confirmation' => 'Tempor4ry-pass'])
            ->assertOk()->assertJsonPath('data.password_change_required', true);
        $this->assertStringNotContainsString('Tempor4ry', $res->getContent());

        $fresh = $target->fresh();
        $this->assertTrue($fresh->password_change_required);
        $this->assertTrue(Hash::check('Tempor4ry-pass', $fresh->password));
    }

    public function test_accounts_of_another_hospital_do_not_exist_here(): void
    {
        $this->as('hospital_admin');
        $foreign = $this->user('nurse', $this->other);

        $this->getJson("/api/v1/admin/users/{$foreign->id}")->assertStatus(404);
        $this->putJson("/api/v1/admin/users/{$foreign->id}", ['name' => 'Hijack'])->assertStatus(404);
        $this->postJson("/api/v1/admin/users/{$foreign->id}/deactivate")->assertStatus(404);
        $this->postJson("/api/v1/admin/users/{$foreign->id}/reset-password", ['password' => 'Tempor4ry-pass', 'password_confirmation' => 'Tempor4ry-pass'])->assertStatus(404);
        $this->deleteJson("/api/v1/admin/users/{$foreign->id}")->assertStatus(404);
        $this->assertTrue($foreign->fresh()->is_active);
    }

    public function test_roles_list_what_each_role_carries_and_leaves_out_super_admin(): void
    {
        $this->as('hospital_admin');

        $roles = collect($this->getJson('/api/v1/admin/roles')->assertOk()->json('data'))->keyBy('key');
        $this->assertFalse($roles->has('super_admin'));
        $this->assertSame('Lab Technician', $roles['lab_technician']['label']);
        $this->assertContains('access-admin', $roles['nurse']['permissions']);
        $this->assertContains('manage-users', $roles['hospital_admin']['permissions']);
        $this->assertNotContains('manage-users', $roles['nurse']['permissions']);
    }

    // ── Staff profiles ──────────────────────────────────────────

    public function test_staff_profiles_one_per_user_with_an_immutable_user_link(): void
    {
        $this->as('hospital_admin');
        $doctor = $this->user('doctor');
        $dep = Department::factory()->create(['hospital_id' => $this->h->id]);

        $id = $this->postJson('/api/v1/admin/staff-profiles', ['user_id' => $doctor->id, 'department_id' => $dep->id, 'specialty' => 'Cardiology', 'license_no' => 'L-1'])
            ->assertCreated()->assertJsonPath('data.user.name', $doctor->name)->assertJsonPath('data.department.id', $dep->id)->json('data.id');

        $this->postJson('/api/v1/admin/staff-profiles', ['user_id' => $doctor->id])->assertStatus(422)->assertJsonValidationErrors('user_id', 'errors');

        $other = $this->user('nurse');
        $this->putJson("/api/v1/admin/staff-profiles/{$id}", ['user_id' => $other->id, 'job_title' => 'Consultant'])
            ->assertOk()->assertJsonPath('data.user_id', $doctor->id)->assertJsonPath('data.job_title', 'Consultant')->assertJsonPath('data.specialty', 'Cardiology');

        $this->getJson('/api/v1/admin/staff-profiles?q=Cardio')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/admin/staff-profiles/{$id}")->assertOk()->assertJsonStructure(['data' => ['schedule', 'upcoming_appointments']]);
        $this->getJson("/api/v1/admin/users/{$doctor->id}")->assertJsonPath('data.profile.id', $id);

        $this->deleteJson("/api/v1/admin/staff-profiles/{$id}")->assertOk();
        // Archived, so the same person gets the same profile back.
        $this->postJson('/api/v1/admin/staff-profiles', ['user_id' => $doctor->id])->assertCreated()->assertJsonPath('data.id', $id);
    }

    public function test_staff_profiles_respect_permissions_and_tenancy(): void
    {
        $foreignDoctor = $this->user('doctor', $this->other);
        $theirs = StaffProfile::factory()->create(['hospital_id' => $this->other->id, 'user_id' => $foreignDoctor->id]);

        $this->as('receptionist');
        $this->getJson('/api/v1/admin/staff-profiles')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson('/api/v1/admin/staff-profiles', ['user_id' => $this->user('doctor')->id])->assertStatus(403);

        $this->as('hospital_admin');
        $this->getJson("/api/v1/admin/staff-profiles/{$theirs->id}")->assertStatus(404);
        $this->postJson('/api/v1/admin/staff-profiles', ['user_id' => $foreignDoctor->id])->assertStatus(422);
    }

    // ── Offline devices ─────────────────────────────────────────

    private function device(Hospital $h, User $u, array $extra = []): Device
    {
        return Device::create($extra + [
            'hospital_id' => $h->id, 'user_id' => $u->id, 'device_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'label' => 'Ward laptop', 'platform' => 'web', 'registered_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    public function test_devices_are_listed_filtered_and_revoked_with_a_reason(): void
    {
        $admin = $this->as('hospital_admin');
        $mine = $this->device($this->h, $admin);
        $old = $this->device($this->h, $admin, ['label' => 'Old tablet', 'last_seen_at' => now()->subDays(30)]);
        $this->device($this->other, $this->user('nurse', $this->other), ['label' => 'Foreign']);

        $this->getJson('/api/v1/admin/offline-devices')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.figures.devices', 2)->assertJsonPath('meta.figures.stale', 1);
        $this->assertSame(['Old tablet'], collect($this->getJson('/api/v1/admin/offline-devices?state=stale')->json('data'))->pluck('label')->all());
        $this->getJson("/api/v1/admin/offline-devices/{$mine->device_uuid}")->assertOk()->assertJsonStructure(['data' => ['recent_operations', 'recent_sessions']]);

        $this->postJson("/api/v1/admin/offline-devices/{$mine->device_uuid}/revoke", [])->assertStatus(422)->assertJsonValidationErrors('reason', 'errors');
        $this->postJson("/api/v1/admin/offline-devices/{$mine->device_uuid}/revoke", ['reason' => 'Laptop was lost'])
            ->assertOk()->assertJsonPath('data.state', 'revoked')->assertJsonPath('data.revoked_reason', 'Laptop was lost')->assertJsonPath('data.revoked_by.id', $admin->id);
        $this->assertNotNull($mine->fresh()->revoked_at);
        $this->getJson('/api/v1/admin/offline-devices?state=revoked')->assertJsonPath('meta.total', 1);

        $this->postJson("/api/v1/admin/offline-devices/{$mine->device_uuid}/restore")->assertOk()->assertJsonPath('data.state', 'active');
        $this->assertNull($mine->fresh()->revoked_at);
        $this->assertNotNull($old);
    }

    public function test_devices_need_manage_users_and_do_not_cross_hospitals(): void
    {
        $foreign = $this->device($this->other, $this->user('nurse', $this->other));

        $nurse = $this->as('nurse');
        $this->getJson('/api/v1/admin/offline-devices')->assertStatus(403);
        $this->postJson('/api/v1/admin/offline-devices/'.$this->device($this->h, $nurse)->device_uuid.'/revoke', ['reason' => 'because'])->assertStatus(403);

        $this->as('hospital_admin');
        $this->getJson("/api/v1/admin/offline-devices/{$foreign->device_uuid}")->assertStatus(404);
        $this->postJson("/api/v1/admin/offline-devices/{$foreign->device_uuid}/revoke", ['reason' => 'because'])->assertStatus(404);
        $this->assertNull($foreign->fresh()->revoked_at);
    }

    public function test_offline_readiness_is_open_to_any_signed_in_user_and_lists_only_their_machines(): void
    {
        $nurse = $this->as('nurse');
        $this->device($this->h, $nurse, ['label' => 'My phone']);
        $this->device($this->h, $this->user('doctor'), ['label' => 'Not mine']);

        $res = $this->getJson('/api/v1/admin/offline/readiness')->assertOk()
            ->assertJsonStructure(['data' => ['enabled', 'available', 'total', 'windows' => ['patient_days', 'visit_days'], 'devices']]);
        $this->assertSame(['My phone'], collect($res->json('data.devices'))->pluck('label')->all());
    }
}
