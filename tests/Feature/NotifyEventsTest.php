<?php

namespace Tests\Feature;

use App\Events\LabResultReleased;
use App\Events\StockBelowReorder;
use App\Models\Appointment;
use App\Models\DeviceToken;
use App\Models\Hospital;
use App\Models\NotificationPreference;
use App\Models\Patient;
use App\Models\StockItem;
use App\Models\User;
use App\Notifications\AppointmentReminder;
use App\Notifications\LabResultReady;
use App\Notifications\LowStockAlert;
use App\Services\Push\NullPushTransport;
use App\Services\Push\PushManager;
use App\Services\Push\PushTransport;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotifyEventsTest extends TestCase
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

    private function labOrder(Hospital $h, Patient $patient, User $doctor): \App\Models\LabOrder
    {
        $visit = \App\Models\Visit::factory()->create(['hospital_id' => $h->id, 'patient_id' => $patient->id]);
        $test = \App\Models\LabTest::factory()->create(['hospital_id' => $h->id]);

        return app(\App\Services\LabService::class)->order($visit, [$test->id], null, $doctor->id);
    }

    public function test_the_listeners_are_discovered(): void
    {
        $this->assertTrue(Event::hasListeners(LabResultReleased::class));
        $this->assertTrue(Event::hasListeners(StockBelowReorder::class));
    }

    public function test_low_stock_goes_to_everyone_who_manages_pharmacy_in_that_hospital_only(): void
    {
        Notification::fake();
        $h = Hospital::factory()->create();
        $pharmacist = $this->staff($h, 'pharmacist');
        $admin = $this->staff($h, 'hospital_admin');
        $nurse = $this->staff($h, 'nurse');
        $elsewhere = $this->staff(Hospital::factory()->create(), 'pharmacist');
        app(CurrentHospital::class)->set($h->id);
        $item = StockItem::factory()->create(['hospital_id' => $h->id]);

        StockBelowReorder::dispatch($item);

        Notification::assertSentTo($pharmacist, LowStockAlert::class);
        Notification::assertSentTo($admin, LowStockAlert::class);
        Notification::assertNotSentTo($nurse, LowStockAlert::class);
        Notification::assertNotSentTo($elsewhere, LowStockAlert::class);
    }

    public function test_someone_who_turned_a_category_off_is_not_notified(): void
    {
        Notification::fake();
        $h = Hospital::factory()->create();
        $quiet = $this->staff($h, 'pharmacist');
        $loud = $this->staff($h, 'pharmacist');
        NotificationPreference::create(['user_id' => $quiet->id, 'category' => 'stock', 'enabled' => false]);
        app(CurrentHospital::class)->set($h->id);

        StockBelowReorder::dispatch(StockItem::factory()->create(['hospital_id' => $h->id]));

        Notification::assertNotSentTo($quiet, LowStockAlert::class);
        Notification::assertSentTo($loud, LowStockAlert::class);
    }

    public function test_a_released_lab_result_tells_the_ordering_doctor_only(): void
    {
        Notification::fake();
        $h = Hospital::factory()->create();
        $doctor = $this->staff($h, 'doctor');
        $bystander = $this->staff($h, 'doctor');
        app(CurrentHospital::class)->set($h->id);
        $order = $this->labOrder($h, Patient::factory()->create(['hospital_id' => $h->id]), $doctor);

        LabResultReleased::dispatch($order);

        Notification::assertSentTo($doctor, LabResultReady::class);
        Notification::assertNotSentTo($bystander, LabResultReady::class);
    }

    public function test_the_push_payload_has_no_private_text(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $doctor = $this->staff($h, 'doctor');
        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Zainabu', 'last_name' => 'Kyomuhendo']);
        $order = $this->labOrder($h, $patient, $doctor);

        $note = new LabResultReady($order->fresh());
        $push = json_encode($note->toPush($doctor));
        $stored = $note->toArray($doctor);

        $this->assertStringNotContainsString('Zainabu', $push);
        $this->assertStringNotContainsString('Kyomuhendo', $push);
        $this->assertStringContainsString('Zainabu', $stored['message'], 'the web bell keeps its message');
        $this->assertSame('lab_result_ready', $stored['type']);
        $this->assertSame($order->uuid, $stored['lab_order_uuid']);
    }

    public function test_the_default_push_driver_logs_and_drops(): void
    {
        $this->assertSame('null', app(PushManager::class)->driverName());
        $this->assertInstanceOf(NullPushTransport::class, app(PushManager::class)->transport());
        $this->assertFalse(app(PushManager::class)->delivers());
        $this->assertFalse((new NullPushTransport)->send('t', 'android', ['category' => 'stock']));
    }

    public function test_a_configured_driver_receives_pushes_for_active_devices_only(): void
    {
        $fake = new class implements PushTransport
        {
            public array $sent = [];

            public function send(string $token, string $platform, array $payload): bool
            {
                $this->sent[] = [$token, $payload];

                return true;
            }

            public function delivers(): bool
            {
                return true;
            }
        };
        config(['push.driver' => 'fake', 'push.drivers.fake' => get_class($fake)]);
        $this->app->instance(get_class($fake), $fake);

        $h = Hospital::factory()->create();
        $u = $this->staff($h, 'pharmacist');
        DeviceToken::create(['user_id' => $u->id, 'device_uuid' => 'live', 'token' => 'tok-live', 'platform' => 'android', 'provider' => 'fcm']);
        DeviceToken::create(['user_id' => $u->id, 'device_uuid' => 'revoked', 'token' => 'tok-old', 'platform' => 'android', 'provider' => 'fcm', 'revoked_at' => now()]);
        DeviceToken::create(['user_id' => $u->id, 'device_uuid' => 'off', 'token' => 'tok-off', 'platform' => 'ios', 'provider' => 'apns', 'push_enabled' => false]);
        DeviceToken::create(['user_id' => $u->id, 'device_uuid' => 'notoken', 'token' => null, 'platform' => 'ios', 'provider' => 'none']);
        app(CurrentHospital::class)->set($h->id);
        $item = StockItem::factory()->create(['hospital_id' => $h->id, 'name' => 'Amoxicillin']);

        $u->notifyNow(new LowStockAlert($item), ['database', \App\Notifications\Channels\PushChannel::class]);

        $this->assertCount(1, $fake->sent);
        $this->assertSame('tok-live', $fake->sent[0][0]);
        $this->assertSame('stock', $fake->sent[0][1]['category']);
        $this->assertStringNotContainsString('Amoxicillin', json_encode($fake->sent[0][1]));

        // The category switched off: nothing goes out.
        NotificationPreference::create(['user_id' => $u->id, 'category' => 'stock', 'enabled' => false]);
        $u->notifyNow(new LowStockAlert($item), [\App\Notifications\Channels\PushChannel::class]);
        $this->assertCount(1, $fake->sent);
    }

    // ── appointments:remind ───────────────────────────────────────────

    private function appointmentAt(Hospital $h, User $doctor, \Carbon\CarbonInterface $when): Appointment
    {
        app(CurrentHospital::class)->set($h->id);
        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'phone_1' => '0772000000']);

        return Appointment::create([
            'uuid' => (string) Str::uuid(), 'hospital_id' => $h->id, 'patient_id' => $patient->id,
            'doctor_user_id' => $doctor->id, 'scheduled_at' => $when, 'ends_at' => $when->copy()->addMinutes(30),
            'duration_minutes' => 30, 'source' => 'phone', 'status' => 'confirmed',
        ]);
    }

    public function test_the_reminder_command_is_idempotent_across_hourly_runs(): void
    {
        Notification::fake();
        $h = Hospital::factory()->create(['timezone' => 'Africa/Kampala']);
        $doctor = $this->staff($h, 'doctor');
        // 09:00 on 17 Oct, Kampala (+03): reminded the day before from 07:00 local.
        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 08:00:00', 'Africa/Kampala'));
        $appt = $this->appointmentAt($h, $doctor, \Carbon\Carbon::parse('2026-10-17 10:00:00', 'Africa/Kampala'));

        $this->artisan('appointments:remind')->assertExitCode(0);
        $this->artisan('appointments:remind')->assertExitCode(0);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 09:00:00', 'Africa/Kampala'));
        $this->artisan('appointments:remind')->assertExitCode(0);

        Notification::assertSentToTimes($doctor, AppointmentReminder::class, 1);
        $this->assertNotNull($appt->fresh()->reminder_sent_at);
        $this->assertNull($appt->fresh()->reminder_soon_sent_at);
    }

    public function test_nothing_goes_out_in_the_small_hours_and_the_day_reminder_waits_for_morning(): void
    {
        Notification::fake();
        $h = Hospital::factory()->create(['timezone' => 'Africa/Kampala']);
        $doctor = $this->staff($h, 'doctor');
        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 03:00:00', 'Africa/Kampala'));
        $appt = $this->appointmentAt($h, $doctor, \Carbon\Carbon::parse('2026-10-17 10:00:00', 'Africa/Kampala'));

        $this->artisan('appointments:remind')->assertExitCode(0);
        Notification::assertNothingSent();

        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 07:05:00', 'Africa/Kampala'));
        $this->artisan('appointments:remind')->assertExitCode(0);
        Notification::assertSentToTimes($doctor, AppointmentReminder::class, 1);
        $this->assertNotNull($appt->fresh()->reminder_sent_at);
    }

    public function test_two_hours_before_is_a_second_reminder_once(): void
    {
        Notification::fake();
        $h = Hospital::factory()->create(['timezone' => 'Africa/Kampala']);
        $doctor = $this->staff($h, 'doctor');
        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 07:30:00', 'Africa/Kampala'));
        $appt = $this->appointmentAt($h, $doctor, \Carbon\Carbon::parse('2026-10-16 09:00:00', 'Africa/Kampala'));

        $this->artisan('appointments:remind')->assertExitCode(0);
        $this->artisan('appointments:remind')->assertExitCode(0);

        Notification::assertSentToTimes($doctor, AppointmentReminder::class, 1);
        Notification::assertSentTo($doctor, AppointmentReminder::class, fn ($n) => $n->kind === 'soon');
        $this->assertNotNull($appt->fresh()->reminder_soon_sent_at);
        $this->assertNull($appt->fresh()->reminder_sent_at, 'a same-day booking never gets a "tomorrow" reminder');
    }

    public function test_cancelled_appointments_are_not_reminded(): void
    {
        Notification::fake();
        $h = Hospital::factory()->create(['timezone' => 'Africa/Kampala']);
        $doctor = $this->staff($h, 'doctor');
        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 08:00:00', 'Africa/Kampala'));
        $appt = $this->appointmentAt($h, $doctor, \Carbon\Carbon::parse('2026-10-17 10:00:00', 'Africa/Kampala'));
        Appointment::withoutGlobalScopes()->whereKey($appt->id)->update(['status' => 'cancelled']);

        $this->artisan('appointments:remind')->assertExitCode(0);

        Notification::assertNothingSent();
    }
}
