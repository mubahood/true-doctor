<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/v1/dashboard serves the same stat cards the web draws, from the
 * same definition (StatCards) and the same figures (DashboardWidgets).
 */
class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function staff(string $role, ?Hospital $hospital = null): User
    {
        $user = User::factory()->create(['hospital_id' => ($hospital ?? Hospital::factory()->create())->id, 'role' => $role]);
        $user->syncSpatieRole();

        return $user;
    }

    public function test_each_role_gets_its_own_cards(): void
    {
        $expected = [
            'nurse' => ['Awaiting vitals', 'Doses due today', 'Inpatients', 'Procedures today'],
            'doctor' => ['My appointments today', 'My open visits', 'Prescriptions today', 'My inpatients'],
            'lab_technician' => ['Pending orders', 'In processing', 'Awaiting processing', 'Completed today'],
            'records_officer' => ['Total patients', 'New today', 'New this week', 'Active patients'],
        ];

        foreach ($expected as $role => $labels) {
            Sanctum::actingAs($this->staff($role));

            $this->assertSame(
                $labels,
                collect($this->getJson('/api/v1/dashboard')->assertOk()->json('data.cards'))->pluck('label')->all(),
                "{$role} got the wrong cards",
            );
        }
    }

    public function test_the_figures_are_the_hospitals_own(): void
    {
        $hospital = Hospital::factory()->create();
        Patient::factory()->count(3)->create(['hospital_id' => $hospital->id]);
        Patient::factory()->count(5)->create(); // another hospital's

        Sanctum::actingAs($this->staff('records_officer', $hospital));

        $card = collect($this->getJson('/api/v1/dashboard')->json('data.cards'))->firstWhere('label', 'Total patients');

        $this->assertSame('3', $card['value']);
        $this->assertSame('admin.patients.index', $card['route']);
        $this->assertStringEndsWith('/admin/patients', $card['web_url']);
        $this->assertSame('fa-user-injured', $card['icon']);
    }

    public function test_a_card_a_role_may_not_see_is_not_served(): void
    {
        $admin = $this->staff('hospital_admin');
        $admin->revokePermissionTo('billing.view');
        $admin->syncRoles([]); // permissions now only what is directly given
        Sanctum::actingAs($admin->fresh());

        $labels = collect($this->getJson('/api/v1/dashboard')->assertOk()->json('data.cards'))->pluck('label');

        $this->assertNotContains('Collected today', $labels);
        $this->assertNotContains('Outstanding', $labels);
    }
}
