<?php

namespace Tests\Feature\Api;

use App\Enums\InvoiceStatus;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Visit;
use App\Support\CurrentHospital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** GET /api/v1/search: grouped, ranked, permission-filtered, tenant-safe. */
class SearchApiTest extends TestCase
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

    private function staff(string $role, ?Hospital $h = null): User
    {
        $u = User::factory()->create(['hospital_id' => ($h ?? $this->hospital)->id, 'role' => $role, 'is_active' => true]);
        $u->syncSpatieRole();

        return $u;
    }

    private function patient(string $first, string $last, array $extra = [], ?Hospital $h = null): Patient
    {
        return Patient::factory()->create(['hospital_id' => ($h ?? $this->hospital)->id, 'first_name' => $first, 'last_name' => $last] + $extra);
    }

    private function search(string $query)
    {
        return $this->getJson('/api/v1/search?'.$query);
    }

    /** @return list<array<string,mixed>> */
    private function hits(\Illuminate\Testing\TestResponse $res, string $type): array
    {
        $group = collect($res->json('data.groups'))->firstWhere('type', $type);

        return $group['hits'] ?? [];
    }

    public function test_it_needs_a_token_and_two_characters(): void
    {
        $this->getJson('/api/v1/search?q=ab')->assertStatus(401);

        Sanctum::actingAs($this->staff('hospital_admin'));
        $this->search('q=a')->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['q']]);
        $this->search('q=%25_')->assertStatus(422);
        $this->search('')->assertStatus(422);
    }

    public function test_an_exact_number_outranks_a_prefix_which_outranks_a_substring(): void
    {
        $exact = $this->patient('Zed', 'One', ['patient_no' => 'P-100']);
        $prefix = $this->patient('Zed', 'Two', ['patient_no' => 'P-1000']);
        $inside = $this->patient('Zed', 'Three', ['patient_no' => 'XP-100']);
        Sanctum::actingAs($this->staff('hospital_admin'));

        $hits = $this->hits($this->search('q=P-100&types=patients&limit=10'), 'patients');

        $this->assertSame([$exact->uuid, $prefix->uuid, $inside->uuid], array_column($hits, 'uuid'));
        $this->assertSame([100, 90, 40], array_column($hits, 'score'));
        $this->assertSame('patients/'.$exact->uuid, $hits[0]['route']);
        $this->assertSame('patients', $hits[0]['type']);
    }

    public function test_a_name_prefix_outranks_a_name_substring_and_a_phone_prefix_is_found(): void
    {
        $prefix = $this->patient('Mariam', 'Okello', ['phone_1' => '0700111222']);
        $inside = $this->patient('Sumari', 'Wanjiru', ['phone_1' => '0788000000']);
        Sanctum::actingAs($this->staff('hospital_admin'));

        $hits = $this->hits($this->search('q=mari&types=patients'), 'patients');
        $this->assertSame([$prefix->uuid, $inside->uuid], array_column($hits, 'uuid'));

        $byPhone = $this->hits($this->search('q=070011&types=patients'), 'patients');
        $this->assertSame([$prefix->uuid], array_column($byPhone, 'uuid'));
        $this->assertSame(60, $byPhone[0]['score']);

        // Two words narrow it.
        $both = $this->hits($this->search('q='.urlencode('mariam oke').'&types=patients'), 'patients');
        $this->assertSame([$prefix->uuid], array_column($both, 'uuid'));
    }

    public function test_the_substring_pass_only_runs_to_fill_what_the_prefix_pass_left(): void
    {
        foreach (range(1, 3) as $i) {
            $this->patient('Pre'.$i, 'Fix');
        }
        $this->patient('Xpre', 'Fix');
        Sanctum::actingAs($this->staff('hospital_admin'));

        $hits = $this->hits($this->search('q=pre&types=patients&limit=3'), 'patients');
        $this->assertCount(3, $hits);
        $this->assertNotContains('Xpre', array_column($hits, 'title'));

        $hits = $this->hits($this->search('q=pre&types=patients&limit=10'), 'patients');
        $this->assertCount(4, $hits);
        $this->assertStringContainsString('Xpre', end($hits)['title']);
    }

    public function test_limit_is_capped_at_ten(): void
    {
        foreach (range(1, 14) as $i) {
            $this->patient('Bulk'.$i, 'Person');
        }
        Sanctum::actingAs($this->staff('hospital_admin'));

        $res = $this->search('q=bulk&types=patients&limit=99');
        $this->assertCount(10, $this->hits($res, 'patients'));
        $res->assertJsonPath('data.limit', 10);
        $this->assertCount(5, $this->hits($this->search('q=bulk&types=patients'), 'patients'));
    }

    public function test_it_finds_visits_invoices_stock_by_sku_and_staff(): void
    {
        $p = $this->patient('Alice', 'Kato');
        $visit = Visit::factory()->create(['hospital_id' => $this->hospital->id, 'patient_id' => $p->id, 'visit_no' => 'V-20261016-077']);
        $invoice = Invoice::create([
            'uuid' => (string) Str::uuid(), 'hospital_id' => $this->hospital->id, 'visit_id' => $visit->id, 'patient_id' => $p->id,
            'invoice_no' => 'INV-5001', 'currency' => 'UGX', 'subtotal' => '10', 'tax_total' => '0', 'discount' => '0',
            'total' => '10', 'amount_paid' => '0', 'balance' => '10', 'status' => InvoiceStatus::Issued, 'issued_at' => now(),
        ]);
        $stock = StockItem::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Amoxicillin', 'sku' => 'AMX-500']);
        $doctor = $this->staff('doctor');
        $doctor->forceFill(['name' => 'Dr Zebedee'])->save();
        Sanctum::actingAs($this->staff('hospital_admin'));

        $res = $this->search('q=V-20261016');
        $this->assertSame([$visit->uuid], array_column($this->hits($res, 'visits'), 'uuid'));
        $this->assertSame([$invoice->uuid], array_column($this->hits($this->search('q=INV-50'), 'invoices'), 'uuid'));
        $this->assertSame('invoices/'.$invoice->uuid, $this->hits($this->search('q=INV-5001'), 'invoices')[0]['route']);
        $this->assertSame([$stock->uuid], array_column($this->hits($this->search('q=amx-5'), 'stock'), 'uuid'));
        $this->assertSame(100, $this->hits($this->search('q=AMX-500&types=stock'), 'stock')[0]['score']);
        $this->assertSame([$doctor->id], array_column($this->hits($this->search('q=zebe&types=staff'), 'staff'), 'id'));
        // A visit is found by its patient's name too.
        $this->assertSame([$visit->uuid], array_column($this->hits($this->search('q=alice&types=visits'), 'visits'), 'uuid'));
    }

    public function test_types_the_user_may_not_see_are_omitted_not_emptied(): void
    {
        $this->patient('Alice', 'Kato');
        StockItem::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Alicin']);
        Sanctum::actingAs($this->staff('pharmacist'));

        $res = $this->search('q=ali')->assertOk();
        $types = array_column($res->json('data.groups'), 'type');

        $this->assertContains('patients', $types);
        $this->assertContains('stock', $types);
        $this->assertNotContains('invoices', $types);
        $this->assertNotContains('staff', $types);

        // Asking for only what is not allowed returns no groups at all.
        $this->assertSame([], $this->search('q=ali&types=invoices,staff')->json('data.groups'));
    }

    public function test_a_receptionist_cannot_search_stock_or_staff(): void
    {
        StockItem::factory()->create(['hospital_id' => $this->hospital->id, 'name' => 'Alicin']);
        Sanctum::actingAs($this->staff('receptionist'));

        $types = array_column($this->search('q=ali')->json('data.groups'), 'type');
        $this->assertNotContains('stock', $types);
        $this->assertNotContains('staff', $types);
    }

    public function test_hospital_b_cannot_find_hospital_as_records(): void
    {
        $other = Hospital::factory()->create();
        $theirs = $this->patient('Secret', 'Person', ['patient_no' => 'SECRET-1'], $other);
        Visit::factory()->create(['hospital_id' => $other->id, 'patient_id' => $theirs->id, 'visit_no' => 'V-SECRET-1']);
        StockItem::factory()->create(['hospital_id' => $other->id, 'name' => 'Secret Drug', 'sku' => 'SECRET-SKU']);
        $theirStaff = $this->staff('doctor', $other);
        $theirStaff->forceFill(['name' => 'Secret Doctor'])->save();

        Sanctum::actingAs($this->staff('hospital_admin'));
        $res = $this->search('q=secret&limit=10')->assertOk();

        foreach ($res->json('data.groups') as $group) {
            $this->assertSame([], $group['hits'], $group['type']);
        }
    }

    public function test_wildcards_in_the_query_are_not_wildcards(): void
    {
        $this->patient('Alice', 'Kato');
        Sanctum::actingAs($this->staff('hospital_admin'));

        $this->assertSame([], $this->hits($this->search('q='.urlencode('%%').'ab&types=patients'), 'patients'));
        // An underscore is dropped (it splits words), never a one-character wildcard.
        $this->assertSame([], $this->hits($this->search('q='.urlencode('zz_ce').'&types=patients'), 'patients'));
    }

    public function test_unknown_types_are_a_validation_error(): void
    {
        Sanctum::actingAs($this->staff('hospital_admin'));
        $this->search('q=ali&types=patients,wizards')->assertStatus(422)->assertJsonStructure(['errors' => ['types']]);
    }

    public function test_search_is_throttled_to_sixty_a_minute(): void
    {
        Sanctum::actingAs($this->staff('hospital_admin'));
        $last = null;
        for ($i = 0; $i < 61; $i++) {
            $last = $this->search('q=ali&types=patients');
        }

        $last->assertStatus(429)->assertJsonPath('code', 'too_many_requests')->assertJsonPath('retryable', true);
        $this->assertNotNull($last->json('retry_after'));
        $this->assertNotNull($last->headers->get('Retry-After'));
    }
}
