<?php

namespace Tests\Feature;

use App\Livewire\Departments\Index;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Departments module behaviour through its only surface: the Livewire index +
 * slide-over (the classic controller/create/edit path was removed).
 */
class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function actAs(Hospital $h, string $role = 'hospital_admin'): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();
        $this->actingAs($u);
        app(CurrentHospital::class)->set($h->id);

        return $u;
    }

    public function test_admin_creates_a_department_with_normalised_code(): void
    {
        $h = Hospital::factory()->create();
        $this->actAs($h);

        Livewire::test(Index::class)->call('create')
            ->set('name', 'Outpatient')->set('code', 'opd')->set('is_active', true)
            ->call('save')->assertHasNoErrors()->assertSet('showForm', false);

        $this->assertDatabaseHas('departments', ['hospital_id' => $h->id, 'name' => 'Outpatient', 'code' => 'OPD']);
    }

    public function test_name_is_unique_per_hospital_but_reusable_across_hospitals(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        Department::factory()->create(['hospital_id' => $a->id, 'name' => 'Pharmacy', 'code' => 'PH']);

        $this->actAs($a);
        Livewire::test(Index::class)->call('create')->set('name', 'Pharmacy')->call('save')->assertHasErrors(['name']);

        $this->actAs($b);
        Livewire::test(Index::class)->call('create')->set('name', 'Pharmacy')->call('save')->assertHasNoErrors();
        $this->assertDatabaseCount('departments', 2);
    }

    public function test_a_role_without_departments_manage_cannot_create(): void
    {
        $h = Hospital::factory()->create();
        $this->actAs($h, 'doctor');

        Livewire::test(Index::class)->call('create')->assertForbidden();
        $this->assertDatabaseCount('departments', 0);
    }

    public function test_head_of_department_dropdown_only_lists_this_hospitals_users(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        User::factory()->create(['hospital_id' => $a->id, 'name' => 'MyOwnDoctor', 'role' => 'doctor']);
        $foreign = User::factory()->create(['hospital_id' => $b->id, 'name' => 'ForeignDoctor', 'role' => 'doctor']);
        $this->actAs($a);

        $c = Livewire::test(Index::class)->call('create')->assertSee('MyOwnDoctor')->assertDontSee('ForeignDoctor');

        // Defence in depth: even a forged pick of a foreign user is rejected.
        $c->set('name', 'Cardiology')->set('head_user_id', $foreign->id)->call('save')->assertHasErrors(['head_user_id']);
    }

    public function test_departments_are_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $deptB = Department::factory()->create(['hospital_id' => $b->id, 'name' => 'SecretDept']);
        $this->actAs($a);

        $this->get(route('admin.departments.index'))->assertOk()->assertDontSee('SecretDept');

        // The global scope hides B's row from A entirely: edit/delete resolve to "not found".
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        try {
            Livewire::test(Index::class)->call('edit', $deptB->id);
        } finally {
            $this->assertSame('SecretDept', Department::withoutGlobalScopes()->find($deptB->id)->name);
        }
    }
}
