<?php

namespace Tests\Feature\Api;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Bed;
use App\Models\Hospital;
use App\Models\LabOrder;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\LabService;
use App\Services\PrescriptionService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** W2-B: GET /me/next-actions and GET /me/agenda. */
class JourneysHomeApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create(['timezone' => 'UTC']);
        app(CurrentHospital::class)->set($this->h->id);
        Cache::flush();
    }

    private function staff(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function visit(array $with = []): Visit
    {
        return Visit::factory()->create($with + ['hospital_id' => $this->h->id, 'patient_id' => Patient::factory()->create(['hospital_id' => $this->h->id])->id, 'status' => 'ongoing']);
    }

    public function test_both_need_sign_in_and_answer_in_the_contract_shape(): void
    {
        $this->getJson('/api/v1/me/next-actions')->assertStatus(401);
        $this->getJson('/api/v1/me/agenda')->assertStatus(401);

        Sanctum::actingAs($this->staff('hospital_admin'));
        $this->getJson('/api/v1/me/next-actions')->assertOk()->assertJsonStructure(['data' => ['items']]);
        $this->getJson('/api/v1/me/agenda?days=3')->assertOk()->assertJsonStructure(['data' => ['items']])->assertJsonPath('data.days', 3);
        $this->getJson('/api/v1/me/agenda?days=99')->assertStatus(422);
        $this->getJson('/api/v1/me/agenda?days=0')->assertStatus(422);
        $this->getJson('/api/v1/meta')->assertOk()->assertJsonPath('data.capabilities.features.agenda', true);
    }

    public function test_a_pharmacist_is_pointed_at_the_prescription_waiting_longest(): void
    {
        $visit = $this->visit();
        app(PrescriptionService::class)->prescribe($visit, [['drug_name' => 'Amoxil', 'slots' => ['morning'], 'days' => 3]], null, null);

        Sanctum::actingAs($this->staff('pharmacist'));
        $this->travel(40)->minutes();
        $items = $this->getJson('/api/v1/me/next-actions')->assertOk()->json('data.items');

        $first = $items[0];
        $this->assertSame('pending-prescriptions', $first['kind']);
        $this->assertSame($visit->uuid, $first['uuid']);
        $this->assertSame('high', $first['urgency']);
        $this->assertSame(1, $first['count']);
        $this->assertSame(['kind', 'title', 'sub', 'route', 'uuid', 'urgency', 'count'], array_keys($first));
        $this->assertLessThanOrEqual(8, count($items));
        $this->travelBack();
    }

    public function test_a_doctor_sees_the_next_patient_and_results_that_are_ready(): void
    {
        $doctor = $this->staff('doctor');
        $this->visit(['doctor_user_id' => $doctor->id, 'stage' => 'ongoing', 'vitals_recorded_at' => now()->subMinutes(5)]);
        $waiting = $this->visit(['doctor_user_id' => $doctor->id, 'stage' => 'ongoing', 'vitals_recorded_at' => now()->subMinutes(30)]);
        $this->visit(['doctor_user_id' => $this->staff('doctor')->id, 'vitals_recorded_at' => now()->subMinutes(60)]); // someone else's
        $test = LabTest::factory()->create(['hospital_id' => $this->h->id]);
        $order = app(LabService::class)->order($waiting, [$test->id], null, $doctor->id);
        LabOrder::whereKey($order->id)->update(['status' => 'completed', 'completed_at' => now()]);

        Sanctum::actingAs($doctor);
        $items = collect($this->getJson('/api/v1/me/next-actions')->assertOk()->json('data.items'))->keyBy('kind');

        $this->assertSame($waiting->uuid, $items['next-patient']['uuid'], 'the longest-waiting patient with vitals done');
        $this->assertSame('high', $items['next-patient']['urgency']);
        $this->assertSame($order->uuid, $items['results-ready']['uuid']);
        $this->assertSame('admin.lab-orders.show', $items['results-ready']['route']);
    }

    public function test_a_nurse_sees_overdue_doses_ranked_first_and_vitals_due(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $ward = Ward::factory()->create(['hospital_id' => $this->h->id]);
        $bed = Bed::factory()->create(['hospital_id' => $this->h->id, 'ward_id' => $ward->id]);
        $nurse = $this->staff('nurse');
        $admission = app(AdmissionService::class)->admit(Patient::factory()->create(['hospital_id' => $this->h->id]), $bed, [], $nurse->id);
        app(PrescriptionService::class)->prescribe($admission->visit, [['drug_name' => 'Quinine', 'slots' => ['morning'], 'days' => 1]], null, null);

        Sanctum::actingAs($nurse);
        $items = $this->getJson('/api/v1/me/next-actions')->assertOk()->json('data.items');
        $this->assertSame('doses-overdue', $items[0]['kind']);
        $this->assertSame('urgent', $items[0]['urgency']);
        $this->assertSame($admission->uuid, $items[0]['uuid']);
        $this->assertContains('vitals-due', array_column($items, 'kind'));

        // A cashier is shown neither.
        Sanctum::actingAs($this->staff('accountant'));
        $kinds = array_column($this->getJson('/api/v1/me/next-actions')->json('data.items'), 'kind');
        $this->assertNotContains('doses-overdue', $kinds);
        $this->assertNotContains('vitals-due', $kinds);
        $this->travelBack();
    }

    public function test_reception_sees_the_queue_and_it_is_cached_per_person_and_hospital(): void
    {
        // Midday, so "an hour ago" is still today's queue whatever time the suite runs.
        $this->travelTo(now()->setTime(12, 0));
        $recep = $this->staff('receptionist');
        $patient = Patient::factory()->create(['hospital_id' => $this->h->id]);
        $appt = Appointment::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $patient->id, 'status' => AppointmentStatus::CheckedIn->value,
            'scheduled_at' => now()->subHour(), 'ends_at' => now()->subMinutes(30), 'checked_in_at' => now()->subMinutes(50)]);

        Sanctum::actingAs($recep);
        $items = $this->getJson('/api/v1/me/next-actions')->assertOk()->json('data.items');
        $wait = collect($items)->firstWhere('kind', 'queue-long-waits');
        $this->assertNotNull($wait);
        $this->assertSame('urgent', $wait['urgency'], 'over 45 minutes is the board\'s "bad"');

        // Within the cache window a change is not seen; another hospital's user never sees this.
        $appt->update(['status' => AppointmentStatus::InProgress->value]);
        $this->assertContains('queue-long-waits', array_column($this->getJson('/api/v1/me/next-actions')->json('data.items'), 'kind'));
        Cache::flush();
        $this->assertNotContains('queue-long-waits', array_column($this->getJson('/api/v1/me/next-actions')->json('data.items'), 'kind'));

        $other = Hospital::factory()->create();
        Sanctum::actingAs($this->staff('receptionist', $other));
        app(CurrentHospital::class)->set($other->id);
        $this->assertNotContains('queue-long-waits', array_column($this->getJson('/api/v1/me/next-actions')->json('data.items'), 'kind'));
    }

    public function test_the_agenda_is_time_ordered_permission_filtered_and_says_where_to_go(): void
    {
        $this->travelTo(now()->setTime(7, 0));
        $doctor = $this->staff('doctor');
        $patient = Patient::factory()->create(['hospital_id' => $this->h->id, 'first_name' => 'Amina', 'last_name' => 'Nakato']);
        $soon = Appointment::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $patient->id, 'doctor_user_id' => $doctor->id, 'status' => 'scheduled',
            'scheduled_at' => now()->addHours(2), 'ends_at' => now()->addHours(3)]);
        Appointment::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $patient->id, 'doctor_user_id' => $this->staff('doctor')->id, 'status' => 'scheduled',
            'scheduled_at' => now()->addHours(1), 'ends_at' => now()->addHours(2)]);
        $later = Appointment::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $patient->id, 'doctor_user_id' => $doctor->id, 'status' => 'confirmed',
            'scheduled_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour()]);
        Appointment::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $patient->id, 'doctor_user_id' => $doctor->id, 'status' => 'scheduled',
            'scheduled_at' => now()->addDays(9), 'ends_at' => now()->addDays(9)->addHour()]);
        StockItem::factory()->create(['hospital_id' => $this->h->id, 'name' => 'Insulin', 'current_quantity' => '5.00', 'expiry_date' => now()->addDays(2)->toDateString()]);

        Sanctum::actingAs($doctor);
        $items = $this->getJson('/api/v1/me/agenda?days=3')->assertOk()->json('data.items');
        // A doctor books and manages appointments (appointments.manage), so sees the diary; nothing past 3 days, no stock.
        $this->assertSame(['appointment', 'appointment', 'appointment'], array_column($items, 'kind'));
        $this->assertSame(['route' => 'admin.appointments.show', 'uuid' => $soon->uuid, 'verb' => 'Open'], $items[1]['action']);
        $this->assertSame($later->uuid, $items[2]['action']['uuid']);
        $this->assertSame(['kind', 'due_at', 'priority', 'subject', 'action'], array_keys($items[0]));
        $this->assertStringContainsString('Amina Nakato', $items[0]['subject']);
        $this->assertTrue(strtotime($items[0]['due_at']) < strtotime($items[1]['due_at']));

        // A nurse has appointments.view only: just her own bookings (none here).
        Sanctum::actingAs($this->staff('nurse'));
        $this->assertSame([], $this->getJson('/api/v1/me/agenda?days=3')->json('data.items'));

        Sanctum::actingAs($this->staff('pharmacist'));
        $kinds = array_column($this->getJson('/api/v1/me/agenda?days=3')->json('data.items'), 'kind');
        $this->assertSame(['stock-expiring'], $kinds);

        $other = Hospital::factory()->create();
        Sanctum::actingAs($this->staff('pharmacist', $other));
        app(CurrentHospital::class)->set($other->id);
        $this->assertSame([], $this->getJson('/api/v1/me/agenda?days=3')->json('data.items'));
        $this->travelBack();
    }
}
