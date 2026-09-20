<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientIdCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    public function test_id_card_pdf_renders(): void
    {
        $h = Hospital::factory()->create();
        $user = User::factory()->create(['hospital_id' => $h->id, 'role' => 'receptionist']);
        $user->syncSpatieRole();
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);

        $res = $this->actingAs($user)->get("/admin/patients/{$patient->uuid}/id-card");

        $res->assertOk();
        $res->assertHeader('content-type', 'application/pdf');
    }
}
