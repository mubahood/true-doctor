<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Appointments\Index as Appointments;
use App\Livewire\Ui\SelectSearch;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\CurrentHospital;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Narrowing the doctor list by department — without narrowing it to nothing.
 *
 * The doctor picker narrows through `staff_profiles`, and NO hospital in this
 * system had one: choosing a department emptied the doctor box completely, so
 * an appointment could not be booked at all. A filter that silently hides
 * everybody is worse than no filter.
 */
class DoctorPickerScopeTest extends TestCase
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

    private function doctor(string $name, ?Department $department = null): User
    {
        $doctor = User::factory()->create([
            'hospital_id' => $this->hospital->id, 'role' => 'doctor', 'name' => $name,
        ]);

        if ($department !== null) {
            StaffProfile::factory()->create([
                'hospital_id' => $this->hospital->id,
                'user_id' => $doctor->id,
                'department_id' => $department->id,
            ]);
        }

        return $doctor;
    }

    private function picker(?int $scope): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(SelectSearch::class, [
            'resource' => 'doctors', 'name' => 'doctor_user_id', 'scope' => $scope,
        ])->call('openList');
    }

    /** @return list<string> */
    private function offered(\Livewire\Features\SupportTesting\Testable $picker): array
    {
        $browse = $picker->instance()->browse();

        return array_column(array_merge($browse['likely'], $browse['rest']), 'label');
    }

    // ── The bug ──────────────────────────────────────────────────────────

    /**
     * The case every hospital was in: a department exists, doctors exist, and
     * not one of them has a staff profile tying the two together.
     */
    public function test_a_department_with_nobody_in_it_still_offers_every_doctor(): void
    {
        $lab = Department::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Laboratory']);
        $this->doctor('Dr Kasujja');
        $this->doctor('Dr Namara');

        $offered = $this->offered($this->picker($lab->id));

        $this->assertContains('Dr Kasujja', $offered, 'choosing a department emptied the doctor box');
        $this->assertContains('Dr Namara', $offered);
    }

    /** And when the department DOES have doctors, it narrows properly. */
    public function test_a_department_with_doctors_in_it_narrows_to_them(): void
    {
        $paeds = Department::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Paediatrics']);
        $this->doctor('Dr Inside', $paeds);
        $this->doctor('Dr Elsewhere');

        $offered = $this->offered($this->picker($paeds->id));

        $this->assertSame(['Dr Inside'], $offered);
    }

    public function test_with_no_department_chosen_the_whole_hospital_is_offered(): void
    {
        $paeds = Department::factory()->create(['hospital_id' => $this->hospital->id]);
        $this->doctor('Dr Inside', $paeds);
        $this->doctor('Dr Elsewhere');

        $offered = $this->offered($this->picker(null));

        $this->assertContains('Dr Inside', $offered);
        $this->assertContains('Dr Elsewhere', $offered);
    }

    public function test_the_narrowing_never_reaches_another_hospital(): void
    {
        $dept = Department::factory()->create(['hospital_id' => $this->hospital->id]);
        $this->doctor('Dr Ours', $dept);

        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($other->id);
        User::factory()->create(['hospital_id' => $other->id, 'role' => 'doctor', 'name' => 'Dr Theirs']);
        app(CurrentHospital::class)->set($this->hospital->id);

        $this->assertSame(['Dr Ours'], $this->offered($this->picker($dept->id)));
    }

    // ── And the form says which of the two is happening ──────────────────

    /**
     * "Narrowed to this department" beside a list of every doctor in the
     * hospital is a lie, and the reader has no way to tell.
     */
    public function test_the_form_says_when_it_is_not_actually_narrowing(): void
    {
        $lab = Department::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Laboratory']);
        $this->doctor('Dr Kasujja');

        Livewire::test(Appointments::class)
            ->call('create')
            ->call('picked', 'department_id', $lab->id)
            ->assertSet('departmentHasDoctors', false)
            ->assertSee('No doctors are attached to this department')
            ->assertDontSee('Narrowed to this department');
    }

    public function test_the_form_says_so_when_it_is(): void
    {
        $paeds = Department::factory()->create(['hospital_id' => $this->hospital->id]);
        $this->doctor('Dr Inside', $paeds);

        Livewire::test(Appointments::class)
            ->call('create')
            ->call('picked', 'department_id', $paeds->id)
            ->assertSet('departmentHasDoctors', true)
            ->assertSee('Narrowed to this department');
    }
}
