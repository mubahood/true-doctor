<?php

namespace Tests\Feature;

use App\Livewire\Rooms\Index;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\Room;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rooms module behaviour through its only surface: the Livewire index +
 * slide-over (the classic controller/create/edit path was removed).
 */
class RoomTest extends TestCase
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

    public function test_create_a_room_attached_to_a_department(): void
    {
        $h = Hospital::factory()->create();
        $dept = Department::factory()->create(['hospital_id' => $h->id]);
        $this->actAs($h);

        Livewire::test(Index::class)->call('create')
            ->set('name', 'Consult 1')
            ->set('department_id', $dept->id)
            ->set('type', 'consultation')
            ->set('status', 'available')
            ->set('capacity', 2)
            ->call('save')->assertHasNoErrors()->assertSet('showForm', false);

        $this->assertDatabaseHas('rooms', ['hospital_id' => $h->id, 'name' => 'Consult 1', 'department_id' => $dept->id]);
    }

    public function test_cannot_attach_a_room_to_another_hospitals_department(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $deptB = Department::factory()->create(['hospital_id' => $b->id]);
        $this->actAs($a);

        Livewire::test(Index::class)->call('create')
            ->set('name', 'Consult X')
            ->set('department_id', $deptB->id)
            ->set('type', 'consultation')
            ->set('status', 'available')
            ->set('capacity', 1)
            ->call('save')->assertHasErrors('department_id');

        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_invalid_type_or_status_is_rejected(): void
    {
        $h = Hospital::factory()->create();
        $this->actAs($h);

        Livewire::test(Index::class)->call('create')
            ->set('name', 'Bad')
            ->set('type', 'spaceship')
            ->set('status', 'nope')
            ->set('capacity', 1)
            ->call('save')->assertHasErrors(['type', 'status']);

        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_name_is_unique_per_hospital_but_reusable_across_hospitals(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        Room::factory()->create(['hospital_id' => $a->id, 'name' => 'Theatre 1']);

        $this->actAs($a);
        Livewire::test(Index::class)->call('create')
            ->set('name', 'Theatre 1')->set('type', 'theatre')->set('status', 'available')->set('capacity', 1)
            ->call('save')->assertHasErrors(['name']);

        $this->actAs($b);
        Livewire::test(Index::class)->call('create')
            ->set('name', 'Theatre 1')->set('type', 'theatre')->set('status', 'available')->set('capacity', 1)
            ->call('save')->assertHasNoErrors();

        $this->assertDatabaseCount('rooms', 2);
    }

    public function test_a_role_without_rooms_manage_cannot_create(): void
    {
        $h = Hospital::factory()->create();
        $this->actAs($h, 'doctor');

        Livewire::test(Index::class)->call('create')->assertForbidden();
        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_rooms_are_tenant_isolated(): void
    {
        $a = Hospital::factory()->create();
        $b = Hospital::factory()->create();
        $roomB = Room::factory()->create(['hospital_id' => $b->id, 'name' => 'HiddenRoom']);
        $this->actAs($a);

        $this->get(route('admin.rooms.index'))->assertOk()->assertDontSee('HiddenRoom');

        // The global scope hides B's row from A entirely: edit/delete resolve to "not found".
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        try {
            Livewire::test(Index::class)->call('edit', $roomB->id);
        } finally {
            $this->assertSame('HiddenRoom', Room::withoutGlobalScopes()->find($roomB->id)->name);
        }
    }
}
