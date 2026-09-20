<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every role's dashboard must render (no Blade/route errors) and show its own
 * cockpit. Onboarding gate is off in tests, so /admin returns the dashboard.
 */
class DashboardHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        // Dashboard sections are #[Lazy]; render them inline so this suite keeps
        // asserting the page contents it always did.
        Livewire::withoutLazyLoading();
    }

    private function actAs(string $role, ?int $hospitalId): User
    {
        $u = User::factory()->create(['role' => $role, 'hospital_id' => $hospitalId]);
        $u->syncSpatieRole();

        return $u;
    }

    /** @return list<array{0:string,1:string}> role => a label unique-ish to its dashboard */
    public static function roles(): array
    {
        return [
            'hospital_admin' => ['hospital_admin', 'Outstanding'],
            'doctor' => ['doctor', 'My open visits'],
            'nurse' => ['nurse', 'Awaiting vitals'],
            'receptionist' => ['receptionist', 'New patients today'],
            'pharmacist' => ['pharmacist', 'Low stock items'],
            'lab_technician' => ['lab_technician', 'Worklist'],
            'radiologist' => ['radiologist', 'Awaiting report'],
            'accountant' => ['accountant', 'Revenue this month'],
            'records_officer' => ['records_officer', 'Recently registered'],
        ];
    }

    #[DataProvider('roles')]
    public function test_each_role_dashboard_renders(string $role, string $label): void
    {
        $h = Hospital::factory()->create();

        $this->actingAs($this->actAs($role, $h->id))
            ->get('/admin')
            ->assertOk()
            ->assertSee('Quick actions')
            ->assertSee($label);
    }

    public function test_super_admin_sees_the_saas_overview(): void
    {
        Hospital::factory()->count(2)->create();

        $this->actingAs($this->actAs('super_admin', null))
            ->get('/admin')
            ->assertOk()
            ->assertSee('Est. MRR')
            ->assertSee('Hospitals by status');
    }

    public function test_dashboard_widgets_are_permission_gated(): void
    {
        // A pharmacist has no billing/appointments — must not see those widgets.
        $h = Hospital::factory()->create();

        $this->actingAs($this->actAs('pharmacist', $h->id))
            ->get('/admin')
            ->assertOk()
            ->assertSee('Stock value')
            ->assertDontSee('Revenue today')
            ->assertDontSee('Appointments today');
    }
}
