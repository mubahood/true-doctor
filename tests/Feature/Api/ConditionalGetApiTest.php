<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ConditionalJson: weak ETag, If-None-Match -> 304, Cache-Control (docs/api/plane.md). */
class ConditionalGetApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);
    }

    private function staff(Hospital $h, string $role = 'hospital_admin'): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role, 'is_active' => true]);
        $u->syncSpatieRole();

        return $u;
    }

    public function test_a_get_carries_a_weak_etag_and_private_no_cache(): void
    {
        Sanctum::actingAs($this->staff($this->hospital));

        $res = $this->getJson('/api/v1/patients')->assertOk();

        $this->assertMatchesRegularExpression('/^W\/"[0-9a-f]{40}"$/', $res->headers->get('ETag'));
        $this->assertStringContainsString('private', $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', $res->headers->get('Cache-Control'));
        $this->assertNotEmpty($res->headers->get('X-Server-Time'));
    }

    public function test_a_matching_if_none_match_is_a_304_with_no_body(): void
    {
        Sanctum::actingAs($this->staff($this->hospital));
        Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        $etag = $this->getJson('/api/v1/patients')->headers->get('ETag');

        $res = $this->withHeader('If-None-Match', $etag)->getJson('/api/v1/patients');

        $res->assertStatus(304);
        $this->assertSame('', $res->getContent());
        $this->assertSame($etag, $res->headers->get('ETag'));
        $this->assertNotEmpty($res->headers->get('X-Server-Time'));
    }

    public function test_the_tag_ignores_server_time_so_a_later_request_still_matches(): void
    {
        Sanctum::actingAs($this->staff($this->hospital));

        $first = $this->getJson('/api/v1/patients');
        $this->travel(5)->seconds();
        $second = $this->withHeader('If-None-Match', $first->headers->get('ETag'))->getJson('/api/v1/patients');

        $second->assertStatus(304);
    }

    public function test_a_changed_body_gets_a_new_tag_and_a_full_answer(): void
    {
        Sanctum::actingAs($this->staff($this->hospital));
        $etag = $this->getJson('/api/v1/patients')->headers->get('ETag');

        Patient::factory()->create(['hospital_id' => $this->hospital->id]);

        $res = $this->withHeader('If-None-Match', $etag)->getJson('/api/v1/patients');
        $res->assertOk();
        $this->assertNotSame($etag, $res->headers->get('ETag'));
        $this->assertCount(1, $res->json('data'));
    }

    public function test_the_header_may_list_several_tags_or_be_a_star(): void
    {
        Sanctum::actingAs($this->staff($this->hospital));
        $etag = $this->getJson('/api/v1/patients')->headers->get('ETag');

        $this->withHeader('If-None-Match', 'W/"nope", '.$etag)->getJson('/api/v1/patients')->assertStatus(304);
        $this->withHeader('If-None-Match', '*')->getJson('/api/v1/patients')->assertStatus(304);
        $this->withHeader('If-None-Match', 'W/"nope"')->getJson('/api/v1/patients')->assertOk();
    }

    public function test_one_hospitals_tag_never_validates_anothers_answer(): void
    {
        $a = $this->staff($this->hospital);
        Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'Alpha']);
        $other = Hospital::factory()->create();
        $b = $this->staff($other);
        Patient::factory()->create(['hospital_id' => $other->id, 'first_name' => 'Beta']);

        Sanctum::actingAs($a);
        $tagA = $this->getJson('/api/v1/patients')->headers->get('ETag');

        app(CurrentHospital::class)->set(null);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($b);
        app(CurrentHospital::class)->set($other->id);
        $res = $this->withHeader('If-None-Match', $tagA)->getJson('/api/v1/patients');

        $res->assertOk();
        $this->assertSame('Beta', $res->json('data.0.first_name'));
    }

    public function test_writes_and_errors_are_never_tagged_or_answered_304(): void
    {
        Sanctum::actingAs($this->staff($this->hospital));

        $post = $this->withHeader('If-None-Match', '*')->postJson('/api/v1/patients', ['first_name' => 'Zed', 'last_name' => 'Zulu', 'consent_given' => true]);
        $post->assertStatus(201);
        $this->assertNull($post->headers->get('ETag'));

        $missing = $this->withHeader('If-None-Match', '*')->getJson('/api/v1/patients/'.\Illuminate\Support\Str::uuid());
        $missing->assertStatus(404);
        $this->assertNull($missing->headers->get('ETag'));
    }

    public function test_meta_is_conditional_too_and_a_permission_change_changes_the_tag(): void
    {
        $user = $this->staff($this->hospital, 'receptionist');
        Sanctum::actingAs($user);
        $etag = $this->getJson('/api/v1/meta')->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->getJson('/api/v1/meta')->assertStatus(304);

        $user->forceFill(['role' => 'pharmacist'])->save();
        $user->syncSpatieRole();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh());

        $this->withHeader('If-None-Match', $etag)->getJson('/api/v1/meta')->assertOk();
    }
}
