<?php

namespace Tests\Feature\Api;

use App\Enums\MessageStatus;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientMessage;
use App\Models\User;
use App\Services\Messaging\LogSmsTransport;
use App\Services\Messaging\MessageCredits;
use App\Services\Messaging\MessagingSettings;
use App\Services\Messaging\SmsTransport;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Settings, Messages over the API (docs/api/admin.md): the choices, the
 * balance, this month's figures and the message log, read and saved through
 * the same MessagingSettings as the web page.
 */
class MessageSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $h;

    private Hospital $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->h = Hospital::factory()->create(['name' => 'Kato Medical Centre']);
        $this->other = Hospital::factory()->create();
    }

    private function as(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->h)->id, 'role' => $role]);
        $u->syncSpatieRole();
        Sanctum::actingAs($u);

        return $u;
    }

    private function message(Hospital $h, array $attrs = []): PatientMessage
    {
        $patient = Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Amina', 'last_name' => 'Nakato']);
        $m = new PatientMessage($attrs + [
            'uuid' => (string) Str::uuid(),
            'patient_id' => $patient->id,
            'channel' => 'sms',
            'purpose' => 'care',
            'event' => 'dental_recall',
            'dedupe_key' => 'k-'.Str::random(8),
            'to_phone' => '+256772123456',
            'body' => 'Kato Medical Centre: it is six months since your dental check-up.',
            'status' => MessageStatus::Sent,
            'segments' => 1,
        ]);
        $m->forceFill(['hospital_id' => $h->id])->save();

        return $m;
    }

    public function test_the_settings_read_as_the_web_page_shows_them(): void
    {
        $this->getJson('/api/v1/admin/settings/messages')->assertUnauthorized();

        $this->as('hospital_admin');
        $this->message($this->h, ['status' => MessageStatus::Sent]);
        $this->message($this->h, ['status' => MessageStatus::Delivered]);
        $this->message($this->h, ['status' => MessageStatus::Failed]);
        $this->message($this->h, ['status' => MessageStatus::Skipped, 'skip_reason' => 'no_consent']);
        $this->message($this->other, ['status' => MessageStatus::Sent]);
        app(MessageCredits::class)->post($this->h->id, 'topup', 30, 'MM-1');

        $data = $this->getJson('/api/v1/admin/settings/messages')->assertOk()->json('data');

        $this->assertTrue($data['available'], 'a test server may send');
        $this->assertTrue($data['metered']);
        $this->assertSame(30, $data['balance']);
        $this->assertTrue($data['balance_low']);
        $this->assertSame(['sms' => false, 'events' => array_map(fn () => true, MessagingSettings::EVENTS), 'quiet_start' => '21:00', 'quiet_end' => '07:00'], $data['settings']);
        $this->assertSame(array_keys(MessagingSettings::EVENTS), array_column($data['events'], 'key'));
        $receipt = collect($data['events'])->firstWhere('key', 'receipt');
        $this->assertSame('Receipt when a visit is paid', $receipt['label']);
        $this->assertStringContainsString('Kato Medical Centre', $receipt['preview']);
        $this->assertSame(['sent' => 2, 'delivered' => 1, 'not_sent' => 2], array_intersect_key($data['month'], array_flip(['sent', 'delivered', 'not_sent'])), 'this hospital only');
        $this->assertContains(['value' => 'skipped', 'label' => 'Not sent', 'tone' => 'warn'], $data['statuses']);
        $this->assertTrue($data['owner_summary']);

        $this->as('nurse');
        $this->getJson('/api/v1/admin/settings/messages')->assertForbidden();
        $this->putJson('/api/v1/admin/settings/messages', ['quiet_start' => '20:00'])->assertForbidden();
        $this->getJson('/api/v1/admin/messages')->assertForbidden();
    }

    public function test_saving_is_partial_and_validated_like_the_web(): void
    {
        $this->as('hospital_admin');

        $res = $this->putJson('/api/v1/admin/settings/messages', ['sms' => true, 'events' => ['receipt' => false, 'made_up' => true]])->assertOk()
            ->assertJsonPath('message', 'Saved. Patients who agreed will get these messages.');
        $this->assertTrue($res->json('data.settings.sms'));
        $this->assertFalse($res->json('data.settings.events.receipt'));
        $this->assertTrue($res->json('data.settings.events.dental_recall'));
        $this->assertArrayNotHasKey('made_up', $res->json('data.settings.events'));

        // A field left out keeps its stored value.
        $this->putJson('/api/v1/admin/settings/messages', ['quiet_start' => '22:30'])->assertOk();
        $stored = MessagingSettings::of($this->h->fresh());
        $this->assertSame(['22:30', '07:00'], $stored->quietHours());
        $this->assertTrue($stored->smsEnabled());
        $this->assertFalse($stored->eventOn('receipt'));

        $this->putJson('/api/v1/admin/settings/messages', ['quiet_end' => '7am'])->assertStatus(422)->assertJsonValidationErrors('quiet_end');
        $this->putJson('/api/v1/admin/settings/messages', ['quiet_start' => null])->assertStatus(422)->assertJsonValidationErrors('quiet_start');
        $this->putJson('/api/v1/admin/settings/messages', ['events' => ['receipt' => 'perhaps']])->assertStatus(422)->assertJsonValidationErrors('events.receipt');
        $this->putJson('/api/v1/admin/settings/messages', ['sms' => false])->assertOk()->assertJsonPath('message', 'Saved. No text messages go to patients.');
    }

    public function test_a_production_server_without_a_gateway_will_not_turn_messages_on(): void
    {
        $this->as('hospital_admin');
        $this->app->instance(SmsTransport::class, new LogSmsTransport);
        $this->app->detectEnvironment(fn () => 'production');

        $this->putJson('/api/v1/admin/settings/messages', ['sms' => true])->assertStatus(422)
            ->assertJsonPath('errors.sms.0', 'Text messages are not switched on for this server yet.');
        $this->assertFalse($this->getJson('/api/v1/admin/settings/messages')->json('data.available'));
        // The quiet hours can still be set.
        $this->putJson('/api/v1/admin/settings/messages', ['quiet_start' => '20:00'])->assertOk();
    }

    public function test_the_log_is_this_hospitals_newest_first_with_what_became_of_each(): void
    {
        $this->as('hospital_admin');
        $this->message($this->h, ['status' => MessageStatus::Skipped, 'skip_reason' => 'no_phone']);
        $this->message($this->h, ['status' => MessageStatus::Failed, 'error' => 'Gateway said no']);
        $link = $this->message($this->h, ['event' => 'document_link', 'body' => 'Kato Medical Centre: your lab result is ready. View it here: http://localhost/p/AbCdEfGhIjKlMnOpQrStUvWx (code: 482913)']);
        $this->message($this->other, ['body' => 'Another clinic']);

        $res = $this->getJson('/api/v1/admin/messages')->assertOk()->assertJsonPath('meta.total', 3);
        $rows = $res->json('data');
        $this->assertSame($link->uuid, $rows[0]['uuid'], 'newest first');
        $this->assertSame('A document sent by link', $rows[0]['event_label']);
        $this->assertStringNotContainsString('482913', $rows[0]['body'], 'a link code is never read back');
        $this->assertStringNotContainsString('AbCdEfGhIjKlMnOpQrStUvWx', $rows[0]['body']);
        $this->assertStringContainsString('(code: ••••••)', $rows[0]['body']);
        $this->assertSame('Gateway said no', $rows[1]['note']);
        $this->assertSame('No mobile number on the record', $rows[2]['note']);
        $this->assertSame(['uuid', 'name', 'patient_no'], array_keys($rows[2]['patient']));
        $this->assertSame('Amina Nakato', $rows[2]['patient']['name']);
        $this->assertStringNotContainsString('Another clinic', (string) $res->getContent());

        $this->assertSame(['Failed'], array_column($this->getJson('/api/v1/admin/messages?status=failed')->json('data'), 'status_label'));
        $this->assertSame(3, $this->getJson('/api/v1/admin/messages?q=Amina')->json('meta.total'), 'by the patient\'s name');
        $this->assertSame(0, $this->getJson('/api/v1/admin/messages?q=Zed')->json('meta.total'), 'a name with no digits is not a phone number');
        $this->assertSame(3, $this->getJson('/api/v1/admin/messages?q='.urlencode('0772 123'))->json('meta.total'), 'by the number it went to');
        $this->getJson('/api/v1/admin/messages?status=lost')->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_a_save_sent_twice_with_one_key_is_answered_once(): void
    {
        $this->as('hospital_admin');

        $this->putJson('/api/v1/admin/settings/messages', ['quiet_start' => '20:00'], ['Idempotency-Key' => 'msg-1'])->assertOk();
        $this->putJson('/api/v1/admin/settings/messages', ['quiet_start' => '20:00'], ['Idempotency-Key' => 'msg-1'])->assertOk()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame('20:00', MessagingSettings::of($this->h->fresh())->quietHours()[0]);
    }
}
