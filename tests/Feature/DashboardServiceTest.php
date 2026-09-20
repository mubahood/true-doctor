<?php

namespace Tests\Feature;

use App\Enums\VisitStatus;
use App\Models\Appointment;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Support\CurrentHospital;
use App\Support\Dashboard\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Metric correctness + the guarantee that matters most: one hospital's dashboard
 * never counts another hospital's rows.
 */
class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $mine;

    private Hospital $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->mine = Hospital::factory()->create();
        $this->other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->mine->id);
    }

    private function svc(): DashboardService
    {
        return app(DashboardService::class);
    }

    public function test_patient_counts_are_tenant_scoped(): void
    {
        Patient::factory()->count(3)->create(['hospital_id' => $this->mine->id]);
        Patient::factory()->count(5)->create(['hospital_id' => $this->other->id]);

        $this->assertSame(3, $this->svc()->patientsTotal());
        $this->assertSame(3, $this->svc()->patientsNewToday());
    }

    public function test_staff_count_excludes_other_hospitals(): void
    {
        User::factory()->count(2)->create(['hospital_id' => $this->mine->id]);
        User::factory()->count(4)->create(['hospital_id' => $this->other->id]);

        $this->assertSame(2, $this->svc()->staffCount());
    }

    public function test_low_stock_count_is_scoped_and_only_counts_below_reorder(): void
    {
        StockItem::factory()->create(['hospital_id' => $this->mine->id, 'is_active' => true, 'current_quantity' => 2, 'reorder_level' => 10]);
        StockItem::factory()->create(['hospital_id' => $this->mine->id, 'is_active' => true, 'current_quantity' => 50, 'reorder_level' => 10]);
        StockItem::factory()->create(['hospital_id' => $this->other->id, 'is_active' => true, 'current_quantity' => 1, 'reorder_level' => 10]);

        $this->assertSame(1, $this->svc()->lowStockCount());
    }

    public function test_appointments_today_scoped_and_optionally_by_doctor(): void
    {
        $doctor = User::factory()->create(['hospital_id' => $this->mine->id, 'role' => 'doctor']);
        $patient = Patient::factory()->create(['hospital_id' => $this->mine->id]);

        Appointment::factory()->count(2)->create([
            'hospital_id' => $this->mine->id, 'doctor_user_id' => $doctor->id,
            'patient_id' => $patient->id, 'scheduled_at' => now(),
        ]);
        // Different hospital, same day — must not be counted.
        Appointment::factory()->create(['hospital_id' => $this->other->id, 'scheduled_at' => now()]);

        $this->assertSame(2, $this->svc()->appointmentsToday());
        $this->assertSame(2, $this->svc()->appointmentsToday($doctor->id));
    }

    public function test_visit_queues_by_status_and_doctor(): void
    {
        $doctor = User::factory()->create(['hospital_id' => $this->mine->id, 'role' => 'doctor']);
        $patient = Patient::factory()->create(['hospital_id' => $this->mine->id]);

        Visit::factory()->create([
            'hospital_id' => $this->mine->id, 'doctor_user_id' => $doctor->id,
            'patient_id' => $patient->id, 'status' => VisitStatus::Ongoing,
        ]);
        Visit::factory()->create([
            'hospital_id' => $this->mine->id, 'patient_id' => $patient->id,
            'status' => VisitStatus::Pending,
        ]);
        Visit::factory()->create([
            'hospital_id' => $this->other->id, 'status' => VisitStatus::Pending,
        ]);

        $this->assertSame(1, $this->svc()->openVisitsForDoctor($doctor->id));
        $this->assertSame(1, $this->svc()->notStarted());
        $this->assertSame(2, $this->svc()->visitsToday());
    }
}
