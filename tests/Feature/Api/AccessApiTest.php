<?php

namespace Tests\Feature\Api;

use App\Models\AuditEntry;
use App\Models\Hospital;
use App\Models\RoleAbility;
use App\Models\User;
use App\Models\UserAbility;
use App\Support\Access\PermissionResolver;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Settings, Staff and access over the API (docs/api/admin.md): the matrix,
 * switching an ability for a role, putting one or a whole role back to the
 * standard, and one person's extra abilities. Every rule is AccessService's;
 * these prove the API keeps them: only a hospital admin changes anything, the
 * hospital admin role never changes, and nobody raises their own access.
 */
class AccessApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private Hospital $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->h = Hospital::factory()->create();
        $this->other = Hospital::factory()->create();
    }

    private function user(string $role, ?Hospital $h = null, array $extra = []): User
    {
        $u = User::factory()->create($extra + ['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function as(User $u): User
    {
        app(PermissionResolver::class)->flush();
        Sanctum::actingAs($u);

        return $u;
    }

    /** A nurse given "Manage staff and settings" on her account: she runs the settings without being an admin. */
    private function nurseWhoRunsSettings(): User
    {
        $nurse = $this->user('nurse');
        app(CurrentHospital::class)->set($this->h->id);
        UserAbility::create(['user_id' => $nurse->id, 'ability' => 'manage_staff']);
        app(PermissionResolver::class)->flush();

        return $nurse;
    }

    private function cell(array $data, string $role, string $ability): array
    {
        return collect($data['roles'])->firstWhere('key', $role)['abilities'][$ability];
    }

    public function test_the_matrix_reads_for_whoever_runs_the_settings_and_says_who_may_change_it(): void
    {
        $this->getJson('/api/v1/admin/access')->assertUnauthorized();

        $this->as($this->user('hospital_admin'));
        $data = $this->getJson('/api/v1/admin/access')->assertOk()->json('data');

        $this->assertTrue($data['can_edit']);
        $this->assertSame('hospital_admin', $data['roles'][0]['key']);
        $this->assertTrue($data['roles'][0]['locked'], 'the hospital admin column is fixed');
        $this->assertSame(['doctor', 'nurse', 'receptionist', 'pharmacist', 'lab_technician', 'radiologist', 'accountant', 'records_officer'], array_slice(array_column($data['roles'], 'key'), 1));
        $this->assertSame(['on' => true, 'partial' => false, 'default' => true, 'changed' => false], $this->cell($data, 'nurse', 'see_clinical'));
        $this->assertFalse($this->cell($data, 'receptionist', 'see_clinical')['on']);
        $see = collect($data['abilities'])->firstWhere('key', 'record_vitals');
        $this->assertSame('Record vitals', $see['label']);
        $this->assertSame(['see_clinical'], $see['needs']);
        $this->assertNotEmpty($see['hint']);

        $this->as($this->nurseWhoRunsSettings());
        $this->assertFalse($this->getJson('/api/v1/admin/access')->assertOk()->json('data.can_edit'), 'she may look, not change');

        $this->as($this->user('nurse'));
        $this->getJson('/api/v1/admin/access')->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    public function test_an_admin_switches_an_ability_for_a_role_and_what_depends_on_it_moves_too(): void
    {
        $this->as($this->user('hospital_admin'));
        $nurse = $this->user('nurse');

        $res = $this->putJson('/api/v1/admin/access/roles/nurse/abilities/see_clinical', ['state' => 'off'])->assertOk();
        $this->assertSame(['see_clinical', 'record_vitals', 'diagnose_prescribe'], $res->json('data.moved'));
        $this->assertSame('See clinical notes, Record vitals, Diagnose and prescribe: off for every nurse.', $res->json('message'));
        $this->assertTrue($this->cell($res->json('data'), 'nurse', 'see_clinical')['changed']);
        $this->assertTrue(collect($res->json('data.roles'))->firstWhere('key', 'nurse')['changed']);

        app(PermissionResolver::class)->flush();
        $this->assertFalse($nurse->fresh()->can('visits.clinical'), 'the same answer the web and the phone get');
        $this->assertTrue(AuditEntry::query()->where('log_name', 'role_ability')->exists(), 'who changed what a role may do is audited');

        $res = $this->putJson('/api/v1/admin/access/roles/receptionist/abilities/record_vitals', ['state' => 'on'])->assertOk();
        $this->assertSame(['record_vitals', 'see_clinical'], $res->json('data.moved'), 'recording vitals brings reading the notes');
    }

    public function test_one_ability_goes_back_to_its_standard_and_a_whole_role_does(): void
    {
        $this->as($this->user('hospital_admin'));

        $this->putJson('/api/v1/admin/access/roles/receptionist/abilities/give_discounts', ['state' => 'off'])->assertOk();
        $this->putJson('/api/v1/admin/access/roles/receptionist/abilities/delete_visits', ['state' => 'on'])->assertOk();
        $this->assertSame(2, RoleAbility::query()->where('role', 'receptionist')->count());

        $res = $this->putJson('/api/v1/admin/access/roles/receptionist/abilities/give_discounts', ['state' => 'default'])->assertOk();
        $this->assertSame('Give discounts: back to the standard for every receptionist.', $res->json('message'));
        $this->assertSame(['on' => true, 'partial' => false, 'default' => true, 'changed' => false], $this->cell($res->json('data'), 'receptionist', 'give_discounts'));
        $this->assertSame(1, RoleAbility::query()->where('role', 'receptionist')->count());

        $res = $this->postJson('/api/v1/admin/access/roles/receptionist/reset')->assertOk();
        $this->assertSame('The receptionist role is back to the standard settings.', $res->json('message'));
        $this->assertFalse(collect($res->json('data.roles'))->firstWhere('key', 'receptionist')['changed']);
        $this->assertSame(0, RoleAbility::query()->count());
    }

    public function test_the_hospital_admin_role_and_names_not_on_the_list_are_refused(): void
    {
        $this->as($this->user('hospital_admin'));

        $this->putJson('/api/v1/admin/access/roles/hospital_admin/abilities/see_clinical', ['state' => 'off'])->assertStatus(422)
            ->assertJsonPath('message', 'The hospital admin keeps every ability, so the clinic can never be locked out.');
        $this->postJson('/api/v1/admin/access/roles/hospital_admin/reset')->assertStatus(422);
        $this->putJson('/api/v1/admin/access/roles/janitor/abilities/see_clinical', ['state' => 'on'])->assertStatus(422)
            ->assertJsonPath('errors.base.0', 'That role cannot be changed here.');
        $this->putJson('/api/v1/admin/access/roles/nurse/abilities/fly_planes', ['state' => 'on'])->assertStatus(422)
            ->assertJsonPath('message', 'That ability is not one of the list.');
        $this->putJson('/api/v1/admin/access/roles/nurse/abilities/see_clinical', ['state' => 'maybe'])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')->assertJsonPath('errors.state.0', 'Say on, off or default.');
        $this->putJson('/api/v1/admin/access/roles/nurse/abilities/see_clinical', [])->assertStatus(422)->assertJsonValidationErrors('state');

        $this->assertSame(0, RoleAbility::query()->count());
    }

    public function test_only_a_hospital_admin_changes_access_and_nobody_raises_their_own(): void
    {
        $nurse = $this->as($this->nurseWhoRunsSettings());
        $desk = $this->user('receptionist');

        $this->putJson('/api/v1/admin/access/roles/nurse/abilities/take_payments', ['state' => 'on'])->assertForbidden()
            ->assertJsonPath('message', 'Only a hospital admin can change who may do what.');
        $this->postJson('/api/v1/admin/access/roles/nurse/reset')->assertForbidden();
        $this->putJson("/api/v1/admin/users/{$nurse->id}/abilities", ['abilities' => ['manage_staff', 'take_payments']])->assertForbidden()
            ->assertJsonPath('message', 'Only a hospital admin can give extra abilities.');
        $this->putJson("/api/v1/admin/users/{$desk->id}/abilities", ['abilities' => ['delete_visits']])->assertForbidden();

        $this->assertSame(0, RoleAbility::query()->count());
        $this->assertSame(['manage_staff'], UserAbility::query()->pluck('ability')->all());

        // A hospital admin has everything already: there is nothing to add to their own account.
        $admin = $this->as($this->user('hospital_admin'));
        $this->putJson("/api/v1/admin/users/{$admin->id}/abilities", ['abilities' => ['take_payments']])->assertStatus(422)
            ->assertJsonPath('errors.abilities.0', 'A hospital admin already has every ability.');
        $this->assertFalse($this->getJson("/api/v1/admin/users/{$admin->id}/abilities")->assertOk()->json('data.can_edit'));
    }

    public function test_an_admin_gives_one_person_extras_and_what_they_need_comes_with_them(): void
    {
        $this->as($this->user('hospital_admin'));
        $desk = $this->user('receptionist', null, ['name' => 'Desk Person']);

        $read = $this->getJson("/api/v1/admin/users/{$desk->id}/abilities")->assertOk()->json('data');
        $this->assertSame([], $read['extras']);
        $this->assertTrue($read['can_edit']);
        $this->assertSame('On top of what the receptionist role allows. What every receptionist may do is set in Staff and access.', $read['note']);
        $this->assertCount(count(\App\Support\Access\Abilities::ALL), $read['abilities']);

        $res = $this->putJson("/api/v1/admin/users/{$desk->id}/abilities", ['abilities' => ['diagnose_prescribe']])->assertOk();
        $this->assertSame('Extra abilities saved for Desk Person.', $res->json('message'));
        $this->assertEqualsCanonicalizing(['diagnose_prescribe', 'see_clinical'], $res->json('data.extras'));
        app(PermissionResolver::class)->flush();
        $this->assertTrue($desk->fresh()->can('visits.diagnose'));

        $show = $this->getJson("/api/v1/admin/users/{$desk->id}")->assertOk();
        $this->assertEqualsCanonicalizing(['diagnose_prescribe', 'see_clinical'], array_column($show->json('data.extras'), 'key'));
        $this->assertContains(['key' => 'see_clinical', 'label' => 'See clinical notes'], $show->json('data.extras'));
        $this->assertTrue($show->json('data.can_give_extras'));
        $people = $this->getJson('/api/v1/admin/access')->json('data.people');
        $this->assertSame('Desk Person', $people[0]['name']);
        $this->assertEqualsCanonicalizing(['Diagnose and prescribe', 'See clinical notes'], array_column($people[0]['extras'], 'label'));

        $this->putJson("/api/v1/admin/users/{$desk->id}/abilities", ['abilities' => []])->assertOk()->assertJsonPath('data.extras', []);
        $this->assertSame(0, UserAbility::query()->count());
    }

    public function test_extras_are_validated(): void
    {
        $this->as($this->user('hospital_admin'));
        $desk = $this->user('receptionist');

        $this->putJson("/api/v1/admin/users/{$desk->id}/abilities", [])->assertStatus(422)->assertJsonValidationErrors('abilities');
        $this->putJson("/api/v1/admin/users/{$desk->id}/abilities", ['abilities' => 'take_payments'])->assertStatus(422)->assertJsonValidationErrors('abilities');
        $errors = $this->putJson("/api/v1/admin/users/{$desk->id}/abilities", ['abilities' => ['take_payments', 'fly_planes']])->assertStatus(422)->json('errors');
        $this->assertSame(['That ability is not one of the list.'], $errors['abilities.1']);
        $this->assertSame(0, UserAbility::query()->count());
    }

    public function test_another_hospitals_account_is_not_found(): void
    {
        $this->as($this->user('hospital_admin'));
        $foreign = $this->user('receptionist', $this->other);

        $this->getJson("/api/v1/admin/users/{$foreign->id}/abilities")->assertNotFound();
        $this->putJson("/api/v1/admin/users/{$foreign->id}/abilities", ['abilities' => ['take_payments']])->assertNotFound();
        $this->assertSame(0, UserAbility::withoutGlobalScopes()->count());

        // And one hospital's choices are never another's.
        $this->putJson('/api/v1/admin/access/roles/nurse/abilities/take_payments', ['state' => 'on'])->assertOk();
        $this->as($this->user('hospital_admin', $this->other));
        $this->assertFalse($this->cell($this->getJson('/api/v1/admin/access')->json('data'), 'nurse', 'take_payments')['on']);
    }

    public function test_a_change_sent_twice_with_one_key_is_made_once(): void
    {
        $this->as($this->user('hospital_admin'));
        $desk = $this->user('receptionist');

        $first = $this->putJson('/api/v1/admin/access/roles/nurse/abilities/take_payments', ['state' => 'on'], ['Idempotency-Key' => 'acc-1'])->assertOk();
        $again = $this->putJson('/api/v1/admin/access/roles/nurse/abilities/take_payments', ['state' => 'on'], ['Idempotency-Key' => 'acc-1'])
            ->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json('message'), $again->json('message'));
        $this->assertSame(1, RoleAbility::query()->count());
        $this->assertSame(1, AuditEntry::query()->where('log_name', 'role_ability')->count(), 'one change, one line in the audit log');

        $this->putJson("/api/v1/admin/users/{$desk->id}/abilities", ['abilities' => ['approve_refunds']], ['Idempotency-Key' => 'acc-2'])->assertOk();
        $this->putJson("/api/v1/admin/users/{$desk->id}/abilities", ['abilities' => ['approve_refunds']], ['Idempotency-Key' => 'acc-2'])
            ->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame(1, UserAbility::query()->count());
    }
}
