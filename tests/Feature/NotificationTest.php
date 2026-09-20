<?php

namespace Tests\Feature;

use App\Enums\LabOrderStatus;
use App\Models\Hospital;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Notifications\AppointmentReminder;
use App\Notifications\LabResultReady;
use App\Notifications\LowStockAlert;
use App\Services\LabService;
use App\Services\StockService;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function staff(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_dispensing_below_reorder_alerts_the_pharmacist(): void
    {
        Notification::fake();
        $h = Hospital::factory()->create();
        $pharmacist = $this->staff($h, 'pharmacist');
        app(CurrentHospital::class)->set($h->id);
        $item = StockItem::factory()->create(['hospital_id' => $h->id, 'reorder_level' => '10']);
        app(StockService::class)->receive($item, '15', '1.00'); // above reorder, no alert

        app(StockService::class)->dispenseOut($item->fresh(), '8'); // 15 → 7, crosses reorder

        Notification::assertSentTo($pharmacist, LowStockAlert::class);
    }

    public function test_no_alert_while_still_above_reorder(): void
    {
        Notification::fake();
        $h = Hospital::factory()->create();
        $this->staff($h, 'pharmacist');
        app(CurrentHospital::class)->set($h->id);
        $item = StockItem::factory()->create(['hospital_id' => $h->id, 'reorder_level' => '10']);
        app(StockService::class)->receive($item, '50', '1.00');

        app(StockService::class)->dispenseOut($item->fresh(), '5'); // 50 → 45, still fine

        Notification::assertNothingSent();
    }

    public function test_completing_a_lab_order_notifies_the_ordering_doctor(): void
    {
        Notification::fake();
        $h = Hospital::factory()->create();
        $doctor = $this->staff($h, 'doctor');
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id]);
        $c = Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
        $test = LabTest::factory()->create(['hospital_id' => $h->id]);
        $order = app(LabService::class)->order($c, [$test->id], null, $doctor->id);

        foreach ([LabOrderStatus::Collected, LabOrderStatus::Processing, LabOrderStatus::Completed] as $s) {
            app(LabService::class)->transition($order, $s);
        }

        Notification::assertSentTo($doctor, LabResultReady::class);
    }

    public function test_appointment_reminder_command_notifies_the_doctor(): void
    {
        Notification::fake();
        $h = Hospital::factory()->create();
        $doctor = $this->staff($h, 'doctor');
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'phone_1' => '0772000000']);
        $when = now()->addDay()->setTime(10, 0);
        \App\Models\Appointment::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'hospital_id' => $h->id, 'patient_id' => $patient->id,
            'doctor_user_id' => $doctor->id, 'scheduled_at' => $when, 'ends_at' => $when->copy()->addMinutes(30),
            'duration_minutes' => 30, 'source' => 'phone', 'status' => 'confirmed',
        ]);

        $this->artisan('appointments:remind')->assertExitCode(0);

        Notification::assertSentTo($doctor, AppointmentReminder::class);
    }

    public function test_staff_can_register_a_device_token(): void
    {
        $h = Hospital::factory()->create();
        $user = $this->staff($h, 'doctor');

        $this->actingAs($user)->postJson('/admin/device-tokens', ['token' => 'fcm-abc-123', 'platform' => 'web'])
            ->assertOk()->assertJson(['status' => 'registered']);
        $this->assertDatabaseHas('device_tokens', ['user_id' => $user->id, 'token' => 'fcm-abc-123']);
    }

    public function test_registering_someone_elses_token_cannot_hijack_their_device(): void
    {
        $h = Hospital::factory()->create();
        $victim = $this->staff($h, 'doctor');
        $attacker = $this->staff(Hospital::factory()->create(), 'nurse');

        $this->actingAs($victim)->postJson('/admin/device-tokens', ['token' => 'shared-token', 'platform' => 'android'])->assertOk();
        $this->actingAs($attacker)->postJson('/admin/device-tokens', ['token' => 'shared-token', 'platform' => 'android'])->assertOk();

        // The victim's registration is untouched; the attacker simply has their own row.
        $this->assertDatabaseHas('device_tokens', ['user_id' => $victim->id, 'token' => 'shared-token']);
        $this->assertDatabaseHas('device_tokens', ['user_id' => $attacker->id, 'token' => 'shared-token']);
    }
}
