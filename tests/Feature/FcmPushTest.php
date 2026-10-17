<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\Hospital;
use App\Models\User;
use App\Services\Push\FcmPushTransport;
use App\Services\Push\PushManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The FCM HTTP v1 push driver: off without a service account, then OAuth, the message, a dead token. */
class FcmPushTest extends TestCase
{
    use RefreshDatabase;

    /** A throwaway service account with a real RSA key, so the JWT really signs. */
    private function account(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return (string) json_encode(['type' => 'service_account', 'project_id' => 'true-doctor-test', 'client_email' => 'push@true-doctor-test.iam.gserviceaccount.com', 'private_key' => $pem]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['push.driver' => 'fcm', 'push.drivers.fcm' => FcmPushTransport::class]);
        Cache::flush();
    }

    public function test_without_a_service_account_nothing_is_said_to_deliver(): void
    {
        config(['push.fcm.credentials' => null]);
        Http::fake();

        $this->assertFalse(app(PushManager::class)->delivers());
        $this->assertFalse(app(FcmPushTransport::class)->send('tok', 'android', ['title' => 'x']));
        Http::assertNothingSent();
    }

    public function test_it_signs_in_once_and_sends_the_public_text_with_routing_data(): void
    {
        config(['push.fcm.credentials' => base64_encode($this->account())]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/true-doctor-test/messages/1']),
        ]);

        $fcm = app(FcmPushTransport::class);
        $this->assertTrue(app(PushManager::class)->delivers());
        $payload = ['title' => 'Lab result ready', 'body' => 'A result is waiting for you.', 'category' => 'clinical', 'priority' => 'high', 'route' => 'admin.lab-orders.index', 'dedupe_key' => 'lab:1'];
        $this->assertTrue($fcm->send('device-token-1', 'android', $payload));
        $this->assertTrue($fcm->send('device-token-2', 'android', $payload));

        Http::assertSentCount(3); // one sign-in, two messages
        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'messages:send')) {
                return str_contains($r->url(), 'oauth2') && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && substr_count((string) $r['assertion'], '.') === 2;
            }

            return str_contains($r->url(), '/v1/projects/true-doctor-test/messages:send')
                && $r->hasHeader('Authorization', 'Bearer ya29.test')
                && $r['message']['notification'] === ['title' => 'Lab result ready', 'body' => 'A result is waiting for you.']
                && $r['message']['data']['route'] === 'admin.lab-orders.index'
                && $r['message']['android']['priority'] === 'HIGH';
        });
    }

    public function test_a_token_fcm_no_longer_knows_is_cleared_on_the_device(): void
    {
        config(['push.fcm.credentials' => $this->account()]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);
        $user = User::factory()->create(['hospital_id' => Hospital::factory()->create()->id]);
        $device = DeviceToken::query()->create(['user_id' => $user->id, 'device_uuid' => 'd1', 'token' => 'gone-token', 'provider' => 'fcm', 'platform' => 'android', 'push_enabled' => true]);

        $this->assertFalse(app(FcmPushTransport::class)->send('gone-token', 'android', ['title' => 'x']));
        $this->assertNull($device->fresh()->token);
        $this->assertNull($device->fresh()->revoked_at, 'the install itself is still signed in');
    }
}
