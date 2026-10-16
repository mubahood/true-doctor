<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use App\Models\Visit;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Lists that used to ->get() without a limit now cap at 200 and say so in meta. */
class ListCapApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);
        $patient = Patient::factory()->create(['hospital_id' => $this->hospital->id]);
        $this->visit = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $patient->id]);
        $u = User::factory()->create(['hospital_id' => $this->hospital->id, 'role' => 'doctor', 'is_active' => true]);
        $u->syncSpatieRole();
        Sanctum::actingAs($u);
    }

    private function rows(string $table, int $n, array $extra): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['uuid' => (string) Str::uuid(), 'hospital_id' => $this->hospital->id, 'visit_id' => $this->visit->id,
                'patient_id' => $this->visit->patient_id, 'created_at' => now(), 'updated_at' => now()] + $extra;
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    public function test_prescriptions_are_capped_at_200_and_the_meta_says_so(): void
    {
        $this->rows('prescriptions', 205, []);

        $res = $this->getJson("/api/v1/visits/{$this->visit->uuid}/prescriptions")->assertOk();

        $this->assertCount(200, $res->json('data'));
        $res->assertJsonPath('meta.total', 205)->assertJsonPath('meta.returned', 200)->assertJsonPath('meta.limit', 200)->assertJsonPath('meta.truncated', true);
    }

    public function test_a_short_list_is_not_truncated(): void
    {
        $this->rows('prescriptions', 3, []);

        $this->getJson("/api/v1/visits/{$this->visit->uuid}/prescriptions")
            ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.truncated', false);
        $this->assertSame(3, Prescription::count());
    }

    public function test_orders_are_capped_at_200_with_their_counts_intact(): void
    {
        $this->rows('orders', 201, ['type' => 'consultation', 'status' => 'pending', 'title' => 'Line']);

        $res = $this->getJson("/api/v1/visits/{$this->visit->uuid}/orders")->assertOk();

        $this->assertCount(200, $res->json('data.orders'));
        $res->assertJsonPath('meta.total', 201)->assertJsonPath('meta.truncated', true);
        $this->assertArrayHasKey('counts', $res->json('data'));
        $this->assertArrayHasKey('writable', $res->json('data'));
    }
}
