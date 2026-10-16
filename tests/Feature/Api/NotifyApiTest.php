<?php

namespace Tests\Feature\Api;

use App\Models\DeviceToken;
use App\Models\Hospital;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\LowStockAlert;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotifyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
    }

    private function staff(Hospital $h, string $role = 'doctor'): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role]);
        $u->syncSpatieRole();

        return $u;
    }

    private function note(User $u, array $data, string $type = 'App\\Notifications\\LabResultReady', ?string $when = null): void
    {
        $u->notifications()->create(['id' => (string) Str::uuid(), 'type' => $type, 'data' => $data, 'created_at' => $when ?? now(), 'updated_at' => now()]);
    }

    // ── Devices ───────────────────────────────────────────────────────

    public function test_a_device_registers_rotates_its_token_and_lists(): void
    {
        $h = Hospital::factory()->create();
        $me = $this->staff($h);
        Sanctum::actingAs($me);
        $uuid = (string) Str::uuid();

        $this->putJson("/api/v1/devices/{$uuid}/push-token", [
            'token' => 'tok-1', 'platform' => 'android', 'app_version' => '1.2.0', 'locale' => 'en', 'tz' => 'Africa/Kampala', 'push_enabled' => true,
        ])->assertOk()->assertJsonPath('data.device_uuid', $uuid)->assertJsonPath('data.provider', 'fcm')->assertJsonPath('data.push_delivers', false);

        // The same install, a new token: one row, not two.
        $this->putJson("/api/v1/devices/{$uuid}/push-token", ['token' => 'tok-2', 'platform' => 'android'])->assertOk();
        $this->assertSame(1, DeviceToken::where('user_id', $me->id)->count());
        $this->assertDatabaseHas('device_tokens', ['user_id' => $me->id, 'device_uuid' => $uuid, 'token' => 'tok-2']);

        // An install with no push at all still says who and where it is.
        $local = (string) Str::uuid();
        $this->putJson("/api/v1/devices/{$local}/push-token", ['platform' => 'ios', 'tz' => 'Africa/Kampala', 'push_enabled' => false])
            ->assertOk()->assertJsonPath('data.has_token', false);

        $this->getJson('/api/v1/devices')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_device_registration_is_validated(): void
    {
        Sanctum::actingAs($this->staff(Hospital::factory()->create()));
        $this->putJson('/api/v1/devices/abc/push-token', ['platform' => 'toaster'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->putJson('/api/v1/devices/abc/push-token', ['platform' => 'ios', 'tz' => 'Mars/Base'])->assertStatus(422);
    }

    public function test_one_cannot_see_or_remove_anothers_device(): void
    {
        $h = Hospital::factory()->create();
        $mine = $this->staff($h);
        $theirs = $this->staff($h, 'nurse');
        $uuid = (string) Str::uuid();
        DeviceToken::create(['user_id' => $theirs->id, 'device_uuid' => $uuid, 'token' => 'theirs', 'platform' => 'android', 'provider' => 'fcm']);
        Sanctum::actingAs($mine);

        $this->getJson('/api/v1/devices')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/devices/{$uuid}")->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->assertNull(DeviceToken::where('device_uuid', $uuid)->first()->revoked_at);

        // The same device id registered by me is MY row; theirs is untouched.
        $this->putJson("/api/v1/devices/{$uuid}/push-token", ['token' => 'mine', 'platform' => 'android'])->assertOk();
        $this->assertDatabaseHas('device_tokens', ['user_id' => $theirs->id, 'token' => 'theirs']);
        $this->assertDatabaseHas('device_tokens', ['user_id' => $mine->id, 'token' => 'mine']);
    }

    public function test_removing_a_device_revokes_and_registering_again_brings_it_back(): void
    {
        Sanctum::actingAs($me = $this->staff(Hospital::factory()->create()));
        $uuid = (string) Str::uuid();
        $this->putJson("/api/v1/devices/{$uuid}/push-token", ['token' => 't', 'platform' => 'android'])->assertOk();

        $this->deleteJson("/api/v1/devices/{$uuid}")->assertOk();
        $row = DeviceToken::where('device_uuid', $uuid)->first();
        $this->assertNotNull($row->revoked_at);
        $this->assertFalse($row->push_enabled);
        $this->getJson('/api/v1/devices')->assertJsonCount(0, 'data');
        $this->assertSame(0, DeviceToken::active()->where('user_id', $me->id)->count());

        $this->putJson("/api/v1/devices/{$uuid}/push-token", ['token' => 't', 'platform' => 'android'])->assertOk();
        $this->assertSame(1, DeviceToken::active()->where('user_id', $me->id)->count());
    }

    public function test_signing_out_revokes_this_phones_token_only(): void
    {
        $h = Hospital::factory()->create();
        $me = $this->staff($h);
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();
        DeviceToken::create(['user_id' => $me->id, 'device_uuid' => $a, 'token' => 'a', 'platform' => 'android', 'provider' => 'fcm']);
        DeviceToken::create(['user_id' => $me->id, 'device_uuid' => $b, 'token' => 'b', 'platform' => 'ios', 'provider' => 'apns']);
        $plain = $me->createToken('t')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$plain}", 'X-Device-Id' => $a])->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertNotNull(DeviceToken::where('device_uuid', $a)->first()->revoked_at);
        $this->assertNull(DeviceToken::where('device_uuid', $b)->first()->revoked_at);
    }

    // ── Preferences ───────────────────────────────────────────────────

    public function test_preferences_have_role_defaults_and_are_saved_per_person(): void
    {
        $h = Hospital::factory()->create();
        $nurse = $this->staff($h, 'nurse');
        $doctor = $this->staff($h, 'doctor');

        Sanctum::actingAs($nurse);
        $rows = collect($this->getJson('/api/v1/notification-preferences')->assertOk()->json('data'))->keyBy('category');
        $this->assertCount(8, $rows);
        $this->assertFalse($rows['stock']['enabled'], 'a nurse has no pharmacy permission: stock starts off');
        $this->assertTrue($rows['ward']['enabled']);
        $this->assertTrue($rows['sync']['enabled']);
        $this->assertSame('22:00', $rows['ward']['quiet_start']);

        $this->putJson('/api/v1/notification-preferences', ['preferences' => [
            ['category' => 'ward', 'enabled' => false, 'quiet_start' => '21:30', 'quiet_end' => '05:30', 'tz' => 'Africa/Kampala', 'channels' => ['database']],
        ]])->assertOk();

        $again = collect($this->getJson('/api/v1/notification-preferences')->json('data'))->keyBy('category');
        $this->assertFalse($again['ward']['enabled']);
        $this->assertSame('21:30', $again['ward']['quiet_start']);
        $this->assertFalse($again['ward']['is_default']);
        $this->assertTrue(NotificationPreference::allows($doctor, 'ward'), 'someone else is unaffected');
        $this->assertFalse(NotificationPreference::allows($nurse, 'ward'));
    }

    public function test_preferences_reject_nonsense(): void
    {
        Sanctum::actingAs($this->staff(Hospital::factory()->create()));
        $this->putJson('/api/v1/notification-preferences', ['preferences' => [['category' => 'gossip', 'enabled' => true]]])->assertStatus(422);
        $this->putJson('/api/v1/notification-preferences', ['preferences' => [['category' => 'ward', 'enabled' => true, 'quiet_start' => '25:99']]])->assertStatus(422);
        $this->putJson('/api/v1/notification-preferences', [])->assertStatus(422);
    }

    // ── The feed ──────────────────────────────────────────────────────

    public function test_the_feed_returns_the_standard_fields_top_level_and_old_rows_are_filled_in(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $me = $this->staff($h);
        // Stored before the standard payload existed.
        $this->note($me, ['type' => 'lab_result_ready', 'title' => 'Lab results ready', 'message' => 'For Amina.', 'lab_order_uuid' => 'lab-1']);
        Sanctum::actingAs($me);

        $row = $this->getJson('/api/v1/notifications')->assertOk()->json('data.0');

        $this->assertSame('clinical', $row['category']);
        $this->assertSame('urgent', $row['priority']);
        $this->assertSame('admin.lab-orders.index', $row['route']);
        $this->assertSame('lab_order', $row['entity']);
        $this->assertSame('lab-1', $row['entity_uuid']);
        $this->assertSame('For Amina.', $row['private_message']);
        $this->assertNull($row['public_message']);
        foreach (['dedupe_key', 'expires_at', 'data', 'read_at', 'created_at', 'message', 'title', 'type', 'id'] as $k) {
            $this->assertArrayHasKey($k, $row);
        }
    }

    public function test_new_notifications_store_the_standard_payload_and_keep_the_web_bell_keys(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $me = $this->staff($h);
        $item = \App\Models\StockItem::factory()->create(['hospital_id' => $h->id, 'name' => 'Amoxicillin', 'reorder_level' => '10', 'current_quantity' => '4']);

        $me->notifyNow(new LowStockAlert($item));
        Sanctum::actingAs($me);
        $row = $this->getJson('/api/v1/notifications')->assertOk()->json('data.0');

        $this->assertSame('low_stock', $row['type']);
        $this->assertSame('Low stock', $row['title']);
        $this->assertStringContainsString('Amoxicillin', $row['message'], 'the web bell still gets its message');
        $this->assertSame('stock', $row['category']);
        $this->assertSame($item->uuid, $row['entity_uuid']);
        $this->assertSame($item->uuid, $row['data']['stock_item_uuid']);
        $this->assertStringNotContainsString('Amoxicillin', (string) $row['public_message'], 'the lock-screen text names nothing');
        $this->assertSame('stock_item:'.$item->uuid.':low_stock', $row['dedupe_key']);
    }

    public function test_the_feed_filters_by_since_category_and_priority(): void
    {
        $h = Hospital::factory()->create();
        $me = $this->staff($h);
        $this->note($me, ['type' => 'lab_result_ready', 'title' => 'Old lab'], when: now()->subDays(3)->toDateTimeString());
        $this->note($me, ['type' => 'lab_result_ready', 'title' => 'New lab', 'category' => 'clinical', 'priority' => 'urgent']);
        $this->note($me, ['type' => 'low_stock', 'title' => 'Stock', 'category' => 'stock', 'priority' => 'normal']);
        $this->note($me, ['type' => 'trial_ending', 'title' => 'Trial']);   // legacy: account / normal
        Sanctum::actingAs($me);

        $titles = fn (string $qs) => collect($this->getJson("/api/v1/notifications?{$qs}")->assertOk()->json('data'))->pluck('title')->sort()->values()->all();

        $this->assertSame(['New lab', 'Old lab'], $titles('category=clinical'), 'legacy rows are found by what their type implies');
        $this->assertSame(['Stock'], $titles('category=stock'));
        $this->assertSame(['Trial'], $titles('category=account'));
        $this->assertSame(['New lab', 'Old lab'], $titles('priority=urgent'));
        $this->assertSame(['New lab', 'Stock', 'Trial'], $titles('since='.urlencode(now()->subDay()->toIso8601String())));
        $this->getJson('/api/v1/notifications?category=nope')->assertStatus(422);
    }

    public function test_read_all_is_one_bulk_update_and_only_touches_ones_own(): void
    {
        $h = Hospital::factory()->create();
        $me = $this->staff($h);
        $other = $this->staff($h, 'nurse');
        foreach (range(1, 5) as $i) {
            $this->note($me, ['title' => "n{$i}"]);
        }
        $this->note($other, ['title' => 'theirs']);
        Sanctum::actingAs($me);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->postJson('/api/v1/notifications/read-all', [])->assertOk()->assertJsonPath('data.unread', 0);
        $updates = collect(\Illuminate\Support\Facades\DB::getQueryLog())->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'update'))->count();
        $selects = collect(\Illuminate\Support\Facades\DB::getQueryLog())->filter(fn ($q) => str_contains(strtolower($q['query']), 'from "notifications"') || str_contains(strtolower($q['query']), 'from `notifications`'))->count();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertSame(1, $updates);
        $this->assertSame(0, $selects, 'the unread collection is not loaded to be marked');
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_the_unread_count_is_in_meta_with_a_single_row_page(): void
    {
        $h = Hospital::factory()->create();
        $me = $this->staff($h);
        foreach (range(1, 4) as $i) {
            $this->note($me, ['title' => "n{$i}"]);
        }
        Sanctum::actingAs($me);

        $this->getJson('/api/v1/notifications?unread=1&per_page=1')->assertOk()->assertJsonPath('meta.unread', 4)->assertJsonCount(1, 'data');
    }

    // ── Reminders ─────────────────────────────────────────────────────

    public function test_upcoming_reminders_are_filtered_by_permission_and_tenant(): void
    {
        $h = Hospital::factory()->create(['timezone' => 'Africa/Kampala']);
        $other = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $patient = \App\Models\Patient::factory()->create(['hospital_id' => $h->id, 'first_name' => 'Zainabu', 'last_name' => 'Kyomuhendo']);
        $doctor = $this->staff($h, 'doctor');
        $when = now()->addDays(2)->setTime(10, 0);
        $appt = \App\Models\Appointment::create([
            'uuid' => (string) Str::uuid(), 'hospital_id' => $h->id, 'patient_id' => $patient->id, 'doctor_user_id' => $doctor->id,
            'scheduled_at' => $when, 'ends_at' => $when->copy()->addMinutes(30), 'duration_minutes' => 30, 'source' => 'phone', 'status' => 'confirmed',
        ]);
        // Another hospital's appointment must never appear.
        app(CurrentHospital::class)->set($other->id);
        $foreign = $this->staff($other, 'doctor');
        $foreignPatient = \App\Models\Patient::factory()->create(['hospital_id' => $other->id]);
        \App\Models\Appointment::create([
            'uuid' => (string) Str::uuid(), 'hospital_id' => $other->id, 'patient_id' => $foreignPatient->id, 'doctor_user_id' => $foreign->id,
            'scheduled_at' => $when, 'ends_at' => $when->copy()->addMinutes(30), 'duration_minutes' => 30, 'source' => 'phone', 'status' => 'confirmed',
        ]);
        app(CurrentHospital::class)->set($h->id);

        Sanctum::actingAs($doctor);
        $res = $this->getJson('/api/v1/reminders/upcoming?from='.urlencode(now()->toIso8601String()).'&to='.urlencode(now()->addDays(5)->toIso8601String()))->assertOk();
        $items = collect($res->json('data'));

        $this->assertEqualsCanonicalizing(["appt:{$appt->uuid}:day", "appt:{$appt->uuid}:soon"], $items->pluck('key')->all());
        $day = $items->firstWhere('key', "appt:{$appt->uuid}:day");
        $this->assertSame('schedule', $day['category']);
        $this->assertSame('admin.appointments.index', $day['route']);
        $this->assertSame($appt->uuid, $day['uuid']);
        $this->assertStringContainsString('18:00', \Carbon\Carbon::parse($day['fire_at'])->format('H:i'), 'the evening before, in the hospital\'s clock');
        $this->assertStringContainsString('+03:00', $day['fire_at']);
        $this->assertStringNotContainsString('Zainabu', $day['title_public']);
        $this->assertStringContainsString('Zainabu', $day['body_private']);
        foreach (['key', 'category', 'priority', 'fire_at', 'title_public', 'body_private', 'route', 'entity', 'uuid', 'expires_at'] as $k) {
            $this->assertArrayHasKey($k, $day);
        }
    }

    public function test_reminders_leave_out_what_the_role_cannot_open(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        \App\Models\StockItem::factory()->create(['hospital_id' => $h->id, 'reorder_level' => '10', 'current_quantity' => '1']);

        Sanctum::actingAs($this->staff($h, 'receptionist'));
        $keys = fn () => collect($this->getJson('/api/v1/reminders/upcoming?from='.urlencode(now()->subMinute()->toIso8601String()).'&to='.urlencode(now()->addDays(2)->toIso8601String()))->assertOk()->json('data'))->pluck('category')->all();
        $this->assertNotContains('stock', $keys());

        Sanctum::actingAs($this->staff($h, 'pharmacist'));
        $this->assertContains('stock', $keys());
    }

    public function test_the_range_is_validated_and_capped(): void
    {
        Sanctum::actingAs($this->staff(Hospital::factory()->create()));
        $this->getJson('/api/v1/reminders/upcoming?from=2026-10-10&to=2026-10-01')->assertStatus(422);
        $this->getJson('/api/v1/reminders/upcoming?from=2026-10-01&to=2027-10-01')->assertOk()->assertJsonPath('meta.truncated', true);
    }

    public function test_every_notify_endpoint_needs_a_sign_in(): void
    {
        $this->getJson('/api/v1/devices')->assertStatus(401);
        $this->putJson('/api/v1/devices/x/push-token', [])->assertStatus(401);
        $this->getJson('/api/v1/notification-preferences')->assertStatus(401);
        $this->getJson('/api/v1/reminders/upcoming')->assertStatus(401);
    }
}
