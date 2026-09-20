<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function user(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_admin_sees_the_reports_dashboard(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAs($this->user($h, 'hospital_admin'))->get('/admin/reports')
            ->assertOk()->assertSee('Reports &amp; dashboards', false)->assertSee('Bed occupancy');
    }

    public function test_nurse_cannot_view_reports(): void
    {
        $h = Hospital::factory()->create();
        $this->actingAs($this->user($h, 'nurse'))->get('/admin/reports')->assertForbidden();
    }
}
