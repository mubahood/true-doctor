<?php

namespace Tests\Feature\Api;

use App\Enums\ApiErrorCode;
use App\Exceptions\PlanLimitExceededException;
use App\Models\Device;
use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The error envelope: precise codes, retryable / retry_after, Accept-Language (docs/api/plane.md). */
class ErrorCodesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('api')->group(function () {
            Route::get('api/v1/_t/conflict', fn () => abort(409, 'Changed under you.'));
            Route::get('api/v1/_t/too-big', fn () => abort(413, 'Too big.'));
            Route::get('api/v1/_t/maintenance', fn () => abort(503, 'Back soon.', ['Retry-After' => 120]));
            Route::get('api/v1/_t/licence', fn () => abort(402, 'Licence ended.'));
            Route::get('api/v1/_t/plan', fn () => throw PlanLimitExceededException::make('patients', 5));
            Route::get('api/v1/_t/forbidden', fn () => abort(403, 'No.'));
            Route::get('api/v1/_t/locale', fn () => response()->json(['locale' => app()->getLocale()]));
        });
    }

    public function test_every_new_code_exists_with_its_wire_value(): void
    {
        $this->assertSame('conflict', ApiErrorCode::Conflict->value);
        $this->assertSame('plan_limit', ApiErrorCode::PlanLimit->value);
        $this->assertSame('payload_too_large', ApiErrorCode::PayloadTooLarge->value);
        $this->assertSame('device_revoked', ApiErrorCode::DeviceRevoked->value);
        $this->assertSame('licence_expired', ApiErrorCode::LicenceExpired->value);
        $this->assertSame('maintenance', ApiErrorCode::Maintenance->value);
    }

    public function test_status_codes_map_to_precise_codes_without_changing_the_status(): void
    {
        $this->getJson('/api/v1/_t/conflict')->assertStatus(409)->assertJsonPath('code', 'conflict')
            ->assertJsonPath('message', 'Changed under you.')->assertJsonPath('retryable', false);
        $this->getJson('/api/v1/_t/too-big')->assertStatus(413)->assertJsonPath('code', 'payload_too_large')->assertJsonPath('retryable', false);
        $this->getJson('/api/v1/_t/licence')->assertStatus(402)->assertJsonPath('code', 'licence_expired')->assertJsonPath('retryable', false);

        // An ordinary 403 is still `forbidden`, with no retry advice at all.
        $res = $this->getJson('/api/v1/_t/forbidden')->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->assertArrayNotHasKey('retryable', $res->json());
        $this->assertArrayNotHasKey('retry_after', $res->json());
        $this->assertSame(['success', 'code', 'message', 'data', 'errors'], array_keys($res->json()));
    }

    public function test_maintenance_is_retryable_and_says_when(): void
    {
        $res = $this->getJson('/api/v1/_t/maintenance')->assertStatus(503);

        $res->assertJsonPath('code', 'maintenance')->assertJsonPath('retryable', true)->assertJsonPath('retry_after', 120);
        $this->assertSame('120', $res->headers->get('Retry-After'));
    }

    public function test_a_plan_limit_nobody_caught_is_403_plan_limit(): void
    {
        $this->getJson('/api/v1/_t/plan')->assertStatus(403)->assertJsonPath('code', 'plan_limit')->assertJsonPath('retryable', false);
    }

    public function test_a_blocked_device_gets_device_revoked(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $nurse = User::factory()->create(['hospital_id' => $h->id, 'role' => 'nurse']);
        $nurse->syncSpatieRole();
        $device = Device::create([
            'hospital_id' => $h->id, 'user_id' => $nurse->id, 'device_uuid' => (string) Str::uuid(),
            'label' => 'tab', 'registered_at' => now(), 'revoked_at' => now(), 'revoked_reason' => 'Lost.',
        ]);
        Sanctum::actingAs($nurse);

        $this->withHeader('X-Device-Id', $device->device_uuid)->getJson('/api/v1/sync/pull')
            ->assertStatus(403)->assertJsonPath('code', 'device_revoked')->assertJsonPath('message', 'Lost.')
            ->assertJsonPath('success', false);
    }

    public function test_accept_language_picks_a_supported_language_by_quality(): void
    {
        $this->withHeader('Accept-Language', 'fr-FR, sw;q=0.8, en;q=0.5')->getJson('/api/v1/_t/locale')
            ->assertOk()->assertJsonPath('locale', 'sw')->assertHeader('Content-Language', 'sw');

        $this->withHeader('Accept-Language', 'lg-UG,en;q=0.2')->getJson('/api/v1/_t/locale')->assertJsonPath('locale', 'lg');
        $this->withHeader('Accept-Language', 'sw;q=0.1, en;q=0.9')->getJson('/api/v1/_t/locale')->assertJsonPath('locale', 'en');
    }

    public function test_an_unsupported_or_missing_language_falls_back_to_the_default(): void
    {
        $default = config('app.locale');

        $this->withHeader('Accept-Language', 'de, ja;q=0.7')->getJson('/api/v1/_t/locale')->assertJsonPath('locale', $default);
        $this->getJson('/api/v1/_t/locale')->assertJsonPath('locale', $default)->assertHeader('Content-Language', $default);
        $this->withHeader('Accept-Language', '*')->getJson('/api/v1/_t/locale')->assertJsonPath('locale', $default);
    }

    public function test_the_language_does_not_leak_into_the_next_request(): void
    {
        $this->withHeader('Accept-Language', 'sw')->getJson('/api/v1/_t/locale')->assertJsonPath('locale', 'sw');
        $this->flushHeaders();
        app()->setLocale(config('app.locale'));
        $this->getJson('/api/v1/_t/locale')->assertJsonPath('locale', config('app.locale'));
    }
}
