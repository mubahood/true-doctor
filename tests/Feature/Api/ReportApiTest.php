<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reports_page_as_data_and_its_pdf(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $nurse = User::factory()->create(['hospital_id' => $h->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();
        $admin = User::factory()->create(['hospital_id' => $h->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();

        Sanctum::actingAs($nurse);
        $this->getJson('/api/v1/reports')->assertStatus(403);

        Sanctum::actingAs($admin);
        $res = $this->getJson('/api/v1/reports?from=2026-09-30&to=2026-09-01')->assertOk();
        $res->assertJsonPath('data.from', '2026-09-01')->assertJsonPath('data.to', '2026-09-30')
            ->assertJsonStructure(['data' => ['revenue' => ['total', 'by_method'], 'outstanding', 'valuation', 'occupancy', 'demographics', 'presets']]);
        $this->getJson('/api/v1/reports?preset=today')->assertJsonPath('data.preset', 'today');
        $this->get('/api/v1/reports/pdf?preset=month')->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
