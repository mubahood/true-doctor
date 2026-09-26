<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_inbox_counts_reads_and_is_only_ones_own(): void
    {
        $h = Hospital::factory()->create();
        app(CurrentHospital::class)->set($h->id);
        $me = User::factory()->create(['hospital_id' => $h->id]);
        $other = User::factory()->create(['hospital_id' => $h->id]);
        foreach ([$me, $me, $other] as $u) {
            $u->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\LabResultReady', 'data' => ['type' => 'lab_result_ready', 'title' => 'Lab results ready', 'message' => 'For Amina.']]);
        }
        Sanctum::actingAs($me);

        $res = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.unread', 2)->assertJsonPath('data.0.title', 'Lab results ready');
        $this->postJson('/api/v1/notifications/'.$res->json('data.0.id').'/read', [])->assertOk()->assertJsonPath('data.unread', 1);
        $this->postJson('/api/v1/notifications/'.$other->notifications()->first()->id.'/read', [])->assertStatus(404);
        $this->postJson('/api/v1/notifications/read-all', [])->assertOk();
        $this->getJson('/api/v1/notifications?unread=1')->assertJsonPath('meta.unread', 0)->assertJsonCount(0, 'data');
    }
}
