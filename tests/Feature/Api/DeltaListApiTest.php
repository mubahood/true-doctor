<?php

namespace Tests\Feature\Api;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `?updated_since=` / `include_deleted=1` / `meta.next_since` / `meta.server_time`
 * on the list endpoints, and the shared page-size cap (docs/api/plane.md).
 */
class DeltaListApiTest extends TestCase
{
    use RefreshDatabase;

    private Hospital $hospital;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->hospital = Hospital::factory()->create();
        app(CurrentHospital::class)->set($this->hospital->id);
        $this->admin = $this->staff($this->hospital, 'hospital_admin');
        Sanctum::actingAs($this->admin);
    }

    private function staff(Hospital $h, string $role): User
    {
        $u = User::factory()->create(['hospital_id' => $h->id, 'role' => $role, 'is_active' => true]);
        $u->syncSpatieRole();

        return $u;
    }

    /** Pin a row's updated_at without Eloquent touching it. */
    private function stamp(string $table, int $id, Carbon $at): void
    {
        \DB::table($table)->where('id', $id)->update(['updated_at' => $at]);
    }

    public function test_patients_delta_returns_only_rows_changed_after_the_instant(): void
    {
        $old = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'Old']);
        $new = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'New']);
        $this->stamp('patients', $old->id, now()->subDays(3));
        $this->stamp('patients', $new->id, now()->subMinute());

        $since = now()->subDay()->toIso8601String();
        $res = $this->getJson('/api/v1/patients?updated_since='.urlencode($since))->assertOk();

        $this->assertSame([$new->uuid], collect($res->json('data'))->pluck('uuid')->all());
        $res->assertJsonPath('meta.next_since', $new->fresh()->updated_at->toIso8601String());
        $this->assertNotEmpty($res->json('meta.server_time'));
        $this->assertArrayHasKey('deleted_at', $res->json('data.0'));
        $this->assertNull($res->json('data.0.deleted_at'));
    }

    public function test_a_delta_with_no_changes_echoes_the_callers_instant(): void
    {
        $since = Carbon::parse('2030-01-01T00:00:00Z');
        $res = $this->getJson('/api/v1/patients?updated_since='.urlencode($since->toIso8601String()))->assertOk();

        $this->assertSame([], $res->json('data'));
        $res->assertJsonPath('meta.next_since', $since->toIso8601String());
    }

    public function test_deleted_rows_appear_only_with_include_deleted_and_carry_deleted_at(): void
    {
        $gone = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'Gone']);
        $kept = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'Kept']);
        $since = now()->subMinute()->toIso8601String();
        $gone->delete();

        $plain = $this->getJson('/api/v1/patients?updated_since='.urlencode($since))->assertOk();
        $this->assertSame([$kept->uuid], collect($plain->json('data'))->pluck('uuid')->all());

        $with = $this->getJson('/api/v1/patients?include_deleted=1&updated_since='.urlencode($since))->assertOk();
        $rows = collect($with->json('data'))->keyBy('uuid');
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[$gone->uuid]['deleted_at']);
        $this->assertNull($rows[$kept->uuid]['deleted_at']);
    }

    public function test_a_delta_is_ordered_oldest_change_first_so_the_last_page_ends_at_next_since(): void
    {
        $a = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'A']);
        $b = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'B']);
        $this->stamp('patients', $a->id, now()->subHours(2));
        $this->stamp('patients', $b->id, now()->subHour());

        $res = $this->getJson('/api/v1/patients?updated_since='.urlencode(now()->subDay()->toIso8601String()))->assertOk();

        $this->assertSame([$a->uuid, $b->uuid], collect($res->json('data'))->pluck('uuid')->all());
        $res->assertJsonPath('meta.next_since', $b->fresh()->updated_at->toIso8601String());
    }

    public function test_a_delta_never_crosses_hospitals_even_with_include_deleted(): void
    {
        $other = Hospital::factory()->create();
        $theirs = Patient::factory()->create(['hospital_id' => $other->id, 'first_name' => 'Theirs']);
        $theirs->delete();
        $mine = Patient::factory()->create(['hospital_id' => $this->hospital->id, 'first_name' => 'Mine']);

        $res = $this->getJson('/api/v1/patients?include_deleted=1&updated_since='.urlencode(now()->subDay()->toIso8601String()))->assertOk();

        $this->assertSame([$mine->uuid], collect($res->json('data'))->pluck('uuid')->all());
    }

    public function test_a_malformed_instant_is_a_validation_error(): void
    {
        $this->getJson('/api/v1/patients?updated_since=yesterday-ish')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['updated_since']]);
    }

    public function test_every_listed_endpoint_speaks_the_delta_protocol(): void
    {
        Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => Patient::factory()->create(['hospital_id' => $this->hospital->id])->id]);
        StockItem::factory()->create(['hospital_id' => $this->hospital->id]);
        Service::factory()->create(['hospital_id' => $this->hospital->id]);

        foreach (['patients', 'visits', 'invoices', 'stock-items', 'appointments', 'lab-orders', 'radiology-orders', 'services'] as $path) {
            $res = $this->getJson("/api/v1/{$path}?updated_since=".urlencode(now()->subDay()->toIso8601String()).'&include_deleted=1')
                ->assertOk();

            $this->assertArrayHasKey('server_time', $res->json('meta'), $path);
            $this->assertArrayHasKey('next_since', $res->json('meta'), $path);
            foreach ($res->json('data') as $row) {
                $this->assertArrayHasKey('deleted_at', $row, $path);
                $this->assertArrayHasKey('updated_at', $row, $path);
            }
        }
    }

    public function test_per_page_is_capped_at_100_and_floored_at_1(): void
    {
        Patient::factory()->count(3)->create(['hospital_id' => $this->hospital->id]);

        $this->getJson('/api/v1/patients?per_page=5000')->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/v1/patients?per_page=0')->assertOk()->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/v1/stock-items?per_page=999')->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/v1/services?per_page=999')->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/v1/patients')->assertOk()->assertJsonPath('meta.per_page', 20);
        $this->getJson('/api/v1/stock-items')->assertOk()->assertJsonPath('meta.per_page', 25);
    }

    public function test_stock_search_matches_the_sku(): void
    {
        StockItem::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Amoxicillin 500', 'sku' => 'AMX-500-CAP']);
        StockItem::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Gauze', 'sku' => 'GZ-1']);

        $res = $this->getJson('/api/v1/stock-items?q=AMX-500')->assertOk();

        $this->assertSame(['Amoxicillin 500'], collect($res->json('data'))->pluck('name')->all());
    }
}
