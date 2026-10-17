<?php

namespace Tests\Feature;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Enums\MessageStatus;
use App\Enums\PaymentMethod;
use App\Jobs\SendPatientMessage;
use App\Models\Hospital;
use App\Models\MessageCredit;
use App\Models\Patient;
use App\Models\PatientConsent;
use App\Models\PatientMessage;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\AppointmentService;
use App\Services\BillingService;
use App\Services\Messaging\AfricasTalkingSmsTransport;
use App\Services\Messaging\ConsentService;
use App\Services\Messaging\MessageCredits;
use App\Services\Messaging\MessagingSettings;
use App\Services\Messaging\PatientMessenger;
use App\Services\Messaging\SmsResult;
use App\Services\Messaging\SmsTransport;
use App\Support\CurrentHospital;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Messages to patients: consent, credit, quiet hours, once per event, the gateway and its callbacks (roadmap 05). */
class PatientMessagingTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    /** A gateway that keeps what it was asked to send. @var SmsTransport&object{sent: list<array{0:string,1:string}>, answer: ?SmsResult} */
    private SmsTransport $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->h = Hospital::factory()->create(['name' => 'Kato Medical Centre', 'currency' => 'UGX', 'timezone' => 'Africa/Kampala']);
        app(CurrentHospital::class)->set($this->h->id);
        $this->travelTo(Carbon::parse('2026-10-16 10:00:00', 'Africa/Kampala')->utc());

        $this->gateway = new class implements SmsTransport
        {
            /** @var list<array{0:string,1:string}> */
            public array $sent = [];

            public ?SmsResult $answer = null;

            public function name(): string
            {
                return 'fake';
            }

            public function live(): bool
            {
                return true;
            }

            public function send(string $to, string $body): SmsResult
            {
                $this->sent[] = [$to, $body];

                return $this->answer ?? SmsResult::sent('ATX-'.count($this->sent), 'UGX 30.0000');
            }
        };
        $this->app->instance(SmsTransport::class, $this->gateway);
    }

    private function messagesOn(array $events = []): void
    {
        MessagingSettings::save($this->h->fresh(), ['sms' => true, 'events' => $events, 'quiet_start' => '21:00', 'quiet_end' => '07:00']);
    }

    private function patient(array $attrs = [], bool $consent = true): Patient
    {
        $p = Patient::factory()->create($attrs + ['hospital_id' => $this->h->id, 'phone_1' => '0772 123 456', 'first_name' => 'Amina']);
        if ($consent) {
            app(ConsentService::class)->record($p, ['sms.care' => true], 'desk');
        }

        return $p;
    }

    private function credit(int $n): void
    {
        app(MessageCredits::class)->post($this->h->id, 'topup', $n, 'MM-TEST');
    }

    private function send(Patient $p, string $key = 'k1'): ?PatientMessage
    {
        return app(PatientMessenger::class)->send($p, 'dental_recall', [], $key);
    }

    public function test_nothing_is_written_or_sent_while_the_hospital_has_messages_off(): void
    {
        $this->assertNull($this->send($this->patient()));
        $this->assertSame(0, PatientMessage::query()->count());
        $this->assertSame([], $this->gateway->sent);
    }

    public function test_a_message_goes_once_per_event_and_takes_its_credit(): void
    {
        $this->messagesOn();
        $this->credit(10);
        $p = $this->patient();

        $m = $this->send($p);
        $this->send($p); // the same event again

        $this->assertSame(1, PatientMessage::query()->count());
        $this->assertSame(MessageStatus::Sent, $m->fresh()->status);
        $this->assertSame([['+256772123456', 'Kato Medical Centre: it is six months since your dental check-up. Call the clinic to book your next visit.']], $this->gateway->sent);
        $this->assertSame('ATX-1', $m->fresh()->provider_id);
        $this->assertSame(9, app(MessageCredits::class)->balance($this->h->id));
    }

    public function test_no_consent_no_number_no_credit_are_written_down_with_the_reason_and_cost_nothing(): void
    {
        $this->messagesOn();
        $this->assertSame('no_consent', $this->send($this->patient([], false), 'a')->skip_reason);
        $this->assertSame('no_phone', $this->send($this->patient(['phone_1' => null, 'phone_2' => null]), 'b')->skip_reason);
        $this->assertSame('no_credit', $this->send($this->patient(), 'c')->fresh()->skip_reason);

        $this->assertSame([], $this->gateway->sent);
        $this->assertSame(0, app(MessageCredits::class)->balance($this->h->id));
        $this->assertSame(0, MessageCredit::query()->where('entry', 'send')->count());
    }

    public function test_a_marketing_message_needs_its_own_yes(): void
    {
        $this->messagesOn();
        $this->credit(5);
        $p = $this->patient();

        $m = app(PatientMessenger::class)->send($p, 'dental_recall', [], 'mkt', MessagePurpose::Marketing);
        $this->assertSame('no_consent', $m->skip_reason);
    }

    public function test_nothing_goes_in_the_quiet_hours_it_waits_for_morning(): void
    {
        $this->messagesOn();
        $this->credit(5);
        $this->travelTo(Carbon::parse('2026-10-16 22:30:00', 'Africa/Kampala')->utc());

        $m = $this->send($this->patient());

        $this->assertSame(MessageStatus::Queued, $m->fresh()->status);
        $this->assertSame('2026-10-17 07:00', $m->fresh()->send_after->setTimezone('Africa/Kampala')->format('Y-m-d H:i'));
        $this->assertSame([], $this->gateway->sent);

        $this->travelTo(Carbon::parse('2026-10-17 07:01:00', 'Africa/Kampala')->utc());
        (new SendPatientMessage($m->id))->handle($this->gateway, app(MessageCredits::class));
        $this->assertSame(MessageStatus::Sent, $m->fresh()->status);
    }

    public function test_a_gateway_failure_is_retried_and_a_final_one_gives_the_credit_back(): void
    {
        $this->messagesOn();
        $this->credit(5);
        $this->gateway->answer = SmsResult::failed('InvalidPhoneNumber (403)');

        $m = $this->send($this->patient());

        $this->assertSame(MessageStatus::Failed, $m->fresh()->status);
        $this->assertSame('InvalidPhoneNumber (403)', $m->fresh()->error);
        $this->assertSame(5, app(MessageCredits::class)->balance($this->h->id), 'a message that never left is not paid for');
    }

    public function test_the_ledger_never_goes_below_zero(): void
    {
        $this->credit(1);
        $this->expectException(\RuntimeException::class);
        app(MessageCredits::class)->post($this->h->id, 'send', -2);
    }

    public function test_booking_an_appointment_tells_the_patient_with_the_doctor_and_time(): void
    {
        $this->messagesOn();
        $this->credit(5);
        $p = $this->patient();
        $doctor = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'doctor', 'name' => 'Dr. Sarah Nakato']);
        \App\Models\DoctorSchedule::query()->create(['hospital_id' => $this->h->id, 'user_id' => $doctor->id, 'weekday' => 1, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'slot_minutes' => 30, 'is_active' => true]);

        app(AppointmentService::class)->book([
            'patient_id' => $p->id, 'doctor_user_id' => $doctor->id,
            'scheduled_at' => '2026-10-19 10:30:00', // the clinic's wall clock, as the desk types it
            'duration_minutes' => 30, 'source' => 'phone',
        ]);

        $this->assertCount(1, $this->gateway->sent);
        $this->assertSame('Kato Medical Centre: your appointment with Dr. Sarah Nakato is on Mon 19 Oct at 10:30. To change it, call the clinic.', $this->gateway->sent[0][1]);
    }

    public function test_a_bill_paid_in_full_sends_the_receipt_link_once(): void
    {
        $this->messagesOn();
        $this->credit(10);
        $p = $this->patient();
        $visit = Visit::factory()->create(['hospital_id' => $this->h->id, 'patient_id' => $p->id, 'status' => 'ongoing']);
        $billing = app(BillingService::class);
        $billing->orderService($visit->fresh(), Service::factory()->create(['hospital_id' => $this->h->id, 'price' => '20000'])->id, 1);
        $invoice = $billing->generateInvoice($visit->fresh(), '0.00', null);

        $billing->recordPayment($invoice, PaymentMethod::Cash, '5000.00');
        $this->assertSame([], $this->gateway->sent, 'part of a bill is not a receipt message');
        $billing->recordPayment($invoice->fresh(), PaymentMethod::MobileMoney, '15000.00');

        $this->assertCount(1, $this->gateway->sent);
        $this->assertStringContainsString('We received USh20,000 for invoice '.$invoice->invoice_no.'. Your receipt: '.url('/s/r/'), $this->gateway->sent[0][1]);
    }

    public function test_the_day_before_reminder_reaches_the_patient_through_the_messenger(): void
    {
        $this->messagesOn();
        $this->credit(5);
        $p = $this->patient();
        $doctor = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'doctor']);
        \App\Models\Appointment::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'hospital_id' => $this->h->id, 'patient_id' => $p->id, 'doctor_user_id' => $doctor->id,
            'scheduled_at' => '2026-10-17 11:00:00', 'ends_at' => '2026-10-17 11:30:00',
            'duration_minutes' => 30, 'source' => 'phone', 'status' => 'confirmed',
        ]);

        $this->artisan('appointments:remind')->assertExitCode(0);
        $this->artisan('appointments:remind')->assertExitCode(0);

        $this->assertCount(1, $this->gateway->sent);
        $this->assertSame('Kato Medical Centre: a reminder of your visit tomorrow at 11:00. To change it, call the clinic.', $this->gateway->sent[0][1]);
    }

    public function test_a_dental_recall_goes_on_the_day_it_falls_due(): void
    {
        $this->h->forceFill(['settings' => array_merge((array) $this->h->settings, ['practice' => ['sector' => 'dental_clinic']])])->save();
        $this->messagesOn();
        $this->credit(5);
        $due = $this->patient(['next_recall_on' => '2026-10-16']);
        $this->patient(['next_recall_on' => '2026-12-01', 'first_name' => 'Later']);

        $this->artisan('messages:due')->assertExitCode(0);
        $this->artisan('messages:due')->assertExitCode(0);

        $this->assertCount(1, $this->gateway->sent);
        $this->assertSame($due->id, PatientMessage::query()->sole()->patient_id);
    }

    public function test_a_child_without_a_phone_is_reached_through_the_guardian(): void
    {
        $mother = $this->patient(['phone_1' => '0701 555 444', 'first_name' => 'Ruth']);
        $child = $this->patient(['phone_1' => null, 'phone_2' => null, 'dob' => now()->subYears(6)->toDateString(), 'first_name' => 'Joel']);
        \App\Models\PatientDependent::query()->create(['hospital_id' => $this->h->id, 'patient_id' => $mother->id, 'dependent_patient_id' => $child->id, 'relationship' => 'child', 'status' => 'active']);

        $this->assertSame('+256701555444', app(PatientMessenger::class)->recipient($child, $this->h));
    }

    public function test_numbers_are_read_in_the_hospitals_own_country(): void
    {
        $this->assertSame('+256772123456', PhoneNumber::e164('0772 123 456', '256'));
        $this->assertSame('+254712345678', PhoneNumber::e164('0712345678', PhoneNumber::callingCodeFor(Hospital::factory()->make(['currency' => 'KES']))));
        $this->assertSame('+256772123456', PhoneNumber::e164('+256 772 123456', '254'));
        $this->assertNull(PhoneNumber::e164('12345', '256'));
    }

    public function test_delivery_reports_and_stop_come_in_only_with_the_secret(): void
    {
        config(['messaging.sms.webhook_token' => 'sekret']);
        $this->messagesOn();
        $this->credit(5);
        $p = $this->patient();
        $m = $this->send($p);

        $this->post('/gateway/sms/delivery/wrong', ['id' => 'ATX-1', 'status' => 'Success'])->assertNotFound();
        $this->assertSame(MessageStatus::Sent, $m->fresh()->status);

        $this->post('/gateway/sms/delivery/sekret', ['id' => 'ATX-1', 'status' => 'Success'])->assertOk();
        $this->assertSame(MessageStatus::Delivered, $m->fresh()->status);

        // STOP from the number, written as the gateway sends it: every patient on it is opted out.
        $other = Hospital::factory()->create(['currency' => 'UGX']);
        $elsewhere = Patient::factory()->create(['hospital_id' => $other->id, 'phone_1' => '+256772123456']);
        app(ConsentService::class)->record($elsewhere, ['sms.care' => true]);
        $this->post('/gateway/sms/inbound/sekret', ['from' => '+256772123456', 'text' => ' stop '])->assertOk();

        $this->assertFalse(app(ConsentService::class)->allows($p, MessageChannel::Sms, MessagePurpose::Care));
        $this->assertFalse(app(ConsentService::class)->allows($elsewhere, MessageChannel::Sms, MessagePurpose::Care));
        $this->assertSame('reply', PatientConsent::query()->withoutGlobalScopes()->latest('id')->first()->method);
        $this->assertSame('no_consent', $this->send($p, 'after-stop')->skip_reason);
    }

    public function test_africas_talking_answers_are_read_right(): void
    {
        Http::fake(['api.africastalking.com/*' => Http::sequence()
            ->push(['SMSMessageData' => ['Message' => 'Sent to 1/1', 'Recipients' => [['statusCode' => 101, 'number' => '+256772123456', 'status' => 'Success', 'cost' => 'UGX 30.0000', 'messageId' => 'ATXid_1']]]])
            ->push(['SMSMessageData' => ['Message' => 'Sent to 0/1', 'Recipients' => [['statusCode' => 403, 'status' => 'InvalidPhoneNumber']]]])
            ->push(['SMSMessageData' => ['Message' => 'Sent to 0/1', 'Recipients' => [['statusCode' => 500, 'status' => 'InternalServerError']]]])]);
        $at = new AfricasTalkingSmsTransport('clinic', 'key', 'TRUEDOCTOR');

        $ok = $at->send('+256772123456', 'Hello');
        $this->assertTrue($ok->ok);
        $this->assertSame('ATXid_1', $ok->providerId);
        $this->assertFalse($at->send('+256', 'x')->retryable);
        $this->assertTrue($at->send('+256772123456', 'x')->retryable);

        Http::assertSent(fn ($r) => $r->hasHeader('apiKey', 'key') && $r['username'] === 'clinic' && $r['from'] === 'TRUEDOCTOR');
    }

    public function test_a_long_or_accented_text_costs_more_than_one_message(): void
    {
        $this->assertSame(1, PatientMessenger::segments(str_repeat('a', 160)));
        $this->assertSame(2, PatientMessenger::segments(str_repeat('a', 161)));
        $this->assertSame(1, PatientMessenger::segments('Habari ya asubuhi'));
        $this->assertSame(2, PatientMessenger::segments(str_repeat('ü', 10).str_repeat('ł', 61)));
    }

    public function test_the_desk_records_the_three_answers_and_an_edit_invents_no_no(): void
    {
        $desk = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'receptionist']);
        $desk->syncSpatieRole();
        $this->actingAs($desk);

        Livewire::test(\App\Livewire\Patients\Form::class)
            ->set('first_name', 'Grace')->set('last_name', 'Atim')->set('sex', 'female')->set('phone_1', '0772 000 111')
            ->set('consent_given', true)
            ->set('messaging.sms_marketing', false)
            ->call('save')->assertHasNoErrors();

        $grace = Patient::query()->where('first_name', 'Grace')->sole();
        $this->assertEquals(['sms.care' => true, 'whatsapp.care' => true, 'sms.marketing' => false], app(ConsentService::class)->current($grace));
        $this->assertSame($desk->id, PatientConsent::query()->where('patient_id', $grace->id)->first()->recorded_by);

        // An older patient never asked: editing them records nothing unless a box is ticked.
        $older = Patient::factory()->create(['hospital_id' => $this->h->id]);
        Livewire::test(\App\Livewire\Patients\Form::class, ['patient' => $older])
            ->assertSet('messaging.sms_care', false)
            ->call('save')->assertHasNoErrors();
        $this->assertSame(0, PatientConsent::query()->where('patient_id', $older->id)->count());
    }

    public function test_the_app_sends_the_answers_with_the_patient(): void
    {
        $desk = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'receptionist']);
        $desk->syncSpatieRole();
        \Laravel\Sanctum\Sanctum::actingAs($desk);

        $uuid = $this->postJson('/api/v1/patients', ['first_name' => 'Isaac', 'last_name' => 'Tumwine', 'consent_given' => true,
            'messaging' => ['sms_care' => true, 'whatsapp_care' => false]])->assertCreated()->json('data.uuid');
        $this->getJson('/api/v1/patients/'.$uuid)->assertOk()->assertJsonPath('data.messaging.sms_care', true)->assertJsonPath('data.messaging.whatsapp_care', false);
        $this->assertSame('app', PatientConsent::query()->first()->method);
    }

    public function test_a_patient_registered_offline_brings_their_answers_with_them(): void
    {
        $desk = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'receptionist']);
        $desk->syncSpatieRole();
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $out = app(\App\Services\Sync\Handlers\PatientHandler::class)->apply('create', [
            'uuid' => $uuid, 'first_name' => 'Betty', 'last_name' => 'Nalubega', 'sex' => 'female', 'consent_given' => true,
            'messaging' => ['sms_care' => true, 'sms_marketing' => false],
        ], new \App\Models\SyncOperation(['device_id' => null, 'operation_id' => 'op-1']), $desk);

        $this->assertSame('accepted', $out['status'] ?? null);
        $betty = Patient::query()->where('uuid', $uuid)->sole();
        $this->assertEquals(['sms.care' => true, 'sms.marketing' => false], app(ConsentService::class)->current($betty));
    }

    public function test_the_settings_page_and_the_credit_page(): void
    {
        $admin = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'hospital_admin']);
        $admin->syncSpatieRole();
        $this->actingAs($admin);

        Livewire::test(\App\Livewire\Settings\Messages::class)
            ->assertSee('Messages to patients')->assertSee('Kato Medical Centre: a reminder of your visit tomorrow at 10:30.')
            ->set('sms', true)->set('events.receipt', false)->call('save')->assertHasNoErrors();
        $this->assertTrue(MessagingSettings::of($this->h->fresh())->smsEnabled());
        $this->assertFalse(MessagingSettings::of($this->h->fresh())->eventOn('receipt'));

        $nurse = User::factory()->create(['hospital_id' => $this->h->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();
        $this->actingAs($nurse)->get(route('admin.settings.messages'))->assertForbidden();

        $super = User::factory()->create(['hospital_id' => null, 'role' => 'super_admin', 'is_admin' => true]);
        $this->actingAs($super);
        Livewire::test(\App\Livewire\Super\Messaging\Index::class)
            ->set('hospitalId', $this->h->id)->set('quantity', 500)->set('reference', 'MM-123')->call('topUp')->assertHasNoErrors();
        $this->assertSame(500, app(MessageCredits::class)->balance($this->h->id));
    }

    public function test_a_production_server_without_a_gateway_never_lets_messages_be_turned_on(): void
    {
        $this->app->instance(SmsTransport::class, new \App\Services\Messaging\LogSmsTransport);
        $this->app->detectEnvironment(fn () => 'production');
        MessagingSettings::save($this->h->fresh(), ['sms' => true]);

        $this->assertFalse(MessagingSettings::available());
        $this->assertFalse(MessagingSettings::of($this->h->fresh())->smsEnabled());
    }
}
